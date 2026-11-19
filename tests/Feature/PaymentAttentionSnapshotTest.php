<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Actions\HandlePaymentCallbackAction;
use App\Actions\ProcessWalletClearanceAction;
use App\Actions\ReconcilePaymentCallbackAction;
use App\Actions\RequestWalletClearanceAction;
use App\Data\VerifiedPaymentResult;
use App\Enums\InvoiceStatus;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\PaymentCallback;
use App\Models\ReconciliationCase;
use App\Models\ReconciliationEntry;
use App\Models\User;
use App\Services\PaymentAttentionCases;
use App\Support\WalletCapacity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Http::preventStrayRequests();
    Queue::fake();
});

function paymentSnapshotFixture(): array
{
    $owner = User::factory()->create(['credit' => WalletCapacity::LIMIT, 'password' => 'Snapshot-Password-123']);
    $actor = User::factory()->create();
    foreach (['finance-reconciliation', 'wallet-clearance-reviewer', 'wallet-clearance-operator', 'wallet-clearance-confirmer'] as $role) {
        app(AssignAdminRoleAction::class)->execute($actor, $role, 'Isolated source snapshot acceptance');
    }
    $invoice = Invoice::factory()->expired()->create(['user_id' => $owner->id, 'gateway' => 'epay_wechat']);
    $receipt = new VerifiedPaymentResult('epay_wechat', 'SNAPSHOT-'.Str::ulid(), $invoice->invoice_no, '1.00');
    app(HandlePaymentCallbackAction::class)->execute($receipt);

    return [$owner, $actor, $invoice, ReconciliationCase::sole(), PaymentCallback::sole(), $receipt];
}

function paymentSnapshotClear(User $owner, User $actor): void
{
    $clearance = app(RequestWalletClearanceAction::class)->execute($owner, ['request_id' => (string) Str::ulid(), 'amount' => '1.00',
        'payout_method' => 'bank_transfer', 'payee_name' => 'Original Snapshot Owner', 'payee_account' => 'PRIVATE-SNAPSHOT-ACCOUNT',
        'bank_name' => 'Isolated Bank', 'current_password' => 'Snapshot-Password-123', 'confirm' => '1']);
    $action = app(ProcessWalletClearanceAction::class);
    $input = fn (string $operation): array => ['request_id' => (string) Str::ulid(), 'operation' => $operation,
        'reason' => 'Isolated confirmed external clearance', 'confirm' => '1'];
    $action->execute($clearance, $actor, $input('approve'));
    $started = $action->execute($clearance, $actor, $input('start'));
    $action->execute($clearance, $actor, [...$input('paid'), 'intent_key' => $started->payout_intent_key,
        'external_reference' => 'SNAPSHOT-PAYOUT-'.$clearance->id, 'confirmed_amount' => '1.00',
        'currency' => 'CNY', 'evidence' => 'PRIVATE-SNAPSHOT-TRANSFER-EVIDENCE']);
}

function paymentSnapshotInput(ReconciliationCase $case): array
{
    return ['request_id' => (string) Str::ulid(), 'expected_revision' => $case->revision,
        'reason' => 'Original source snapshot verified', 'evidence' => 'PRIVATE-SNAPSHOT-RECONCILIATION', 'confirm' => '1'];
}

it('keeps original receipt reconciliation and replay independent of archived attention audits', function (): void {
    [$owner, $actor, $invoice, $case, $callback, $receipt] = paymentSnapshotFixture();
    $originalCallback = $callback->getRawOriginal();
    expect($case->source_snapshot)->toBe(app(PaymentAttentionCases::class)->callbackSourceSnapshot($callback, $invoice, 'wallet_capacity'));
    paymentSnapshotClear($owner, $actor);
    DB::table('audit_logs')->where('action', 'payment.callback.attention')->delete();
    DB::enableQueryLog();
    try {
        expect(app(PaymentAttentionCases::class)->hasUnresolvedForUser($owner->id))->toBeTrue();
        $input = paymentSnapshotInput($case);
        $updated = app(ReconcilePaymentCallbackAction::class)->execute($case, $actor, $input);
        expect($updated->status)->toBe('resolved')->and(app(PaymentAttentionCases::class)->isResolved($updated))->toBeTrue()
            ->and(app(PaymentAttentionCases::class)->hasUnresolvedForUser($owner->id))->toBeFalse();
        $counts = [FinancialTransaction::count(), ReconciliationEntry::count(), DB::table('notification_events')->count()];
        app(ReconcilePaymentCallbackAction::class)->execute($case, $actor, $input);
        app(HandlePaymentCallbackAction::class)->execute($receipt);
        expect([FinancialTransaction::count(), ReconciliationEntry::count(), DB::table('notification_events')->count()])->toBe($counts)
            ->and($callback->fresh()->getRawOriginal())->toBe($originalCallback)
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::EXPIRED)->and($owner->fresh()->credit)->toBe(WalletCapacity::LIMIT);
        $auditReads = array_filter(DB::getQueryLog(), fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select')
            && str_contains($query['query'], 'audit_logs'));
        expect($auditReads)->toBe([]);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    Http::assertNothingSent();
});

it('rejects missing or changed original attention snapshots without financial side effects', function (string $field): void {
    [$owner, $actor, , $case] = paymentSnapshotFixture();
    paymentSnapshotClear($owner, $actor);
    $snapshot = $case->source_snapshot;
    if ($field === 'missing') {
        $snapshot = null;
    } else {
        $snapshot[$field] = match ($field) {
            'version', 'callback_id', 'invoice_id', 'user_id' => $snapshot[$field] + 1,
            'reason' => 'closed_account',
            'outcome' => 'processed_paid',
            'amount' => '1.01',
            default => str_repeat('0', 64),
        };
    }
    $case->update(['source_snapshot' => $snapshot]);
    DB::table('audit_logs')->where('action', 'payment.callback.attention')->delete();
    $before = [FinancialTransaction::count(), ReconciliationEntry::count(), DB::table('notification_events')->count(), $owner->fresh()->credit];
    expect(fn () => app(ReconcilePaymentCallbackAction::class)->execute($case, $actor, paymentSnapshotInput($case)))->toThrow(ValidationException::class);
    expect([FinancialTransaction::count(), ReconciliationEntry::count(), DB::table('notification_events')->count(), $owner->fresh()->credit])->toBe($before)
        ->and(app(PaymentAttentionCases::class)->hasUnresolvedForUser($owner->id))->toBeTrue();
})->with(['missing', 'version', 'callback_id', 'invoice_id', 'user_id', 'reason', 'outcome', 'amount', 'source_fingerprint']);

it('rolls back the original callback if its source snapshot cannot be stored', function (): void {
    $owner = User::factory()->create(['credit' => WalletCapacity::LIMIT]);
    $invoice = Invoice::factory()->expired()->create(['user_id' => $owner->id, 'gateway' => 'epay_wechat']);
    ReconciliationCase::creating(function (ReconciliationCase $case): void {
        if ($case->source_snapshot !== null) {
            throw new RuntimeException('Isolated source snapshot persistence failure');
        }
    });
    try {
        expect(fn () => app(HandlePaymentCallbackAction::class)->execute(new VerifiedPaymentResult('epay_wechat', 'SNAPSHOT-FAIL', $invoice->invoice_no, '1.00')))
            ->toThrow(RuntimeException::class);
    } finally {
        ReconciliationCase::flushEventListeners();
    }
    expect(PaymentCallback::count())->toBe(0)->and(ReconciliationCase::count())->toBe(0)->and(ReconciliationEntry::count())->toBe(0)
        ->and(FinancialTransaction::count())->toBe(0)->and(DB::table('notification_events')->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'payment.callback.attention')->count())->toBe(0)
        ->and($owner->fresh()->credit)->toBe(WalletCapacity::LIMIT);
});

it('does not accept a zero callback resolution after its persisted source proof disappears or changes', function (string $damage): void {
    $owner = User::factory()->create(['deleted_at' => now(), 'anonymized_at' => now()]);
    $invoice = Invoice::factory()->expired()->create(['user_id' => $owner->id, 'gateway' => 'epay_wechat']);
    app(HandlePaymentCallbackAction::class)->execute(new VerifiedPaymentResult('epay_wechat', 'ZERO-SNAPSHOT-'.Str::ulid(), $invoice->invoice_no, '0.00'));
    $case = ReconciliationCase::sole();
    expect(app(PaymentAttentionCases::class)->isResolved($case))->toBeTrue();
    $case->update(['source_snapshot' => $damage === 'missing' ? null : [...$case->source_snapshot, 'amount' => '0.01']]);
    expect(app(PaymentAttentionCases::class)->isResolved($case->fresh()))->toBeFalse()
        ->and(app(PaymentAttentionCases::class)->hasUnresolvedForUser($owner->id))->toBeTrue()
        ->and(DB::table('wallet_clearances')->count())->toBe(0);
})->with(['missing', 'changed']);
