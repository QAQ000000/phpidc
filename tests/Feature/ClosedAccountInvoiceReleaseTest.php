<?php

declare(strict_types=1);

use App\Actions\ExpireUnpaidInvoicesAction;
use App\Actions\HandlePaymentCallbackAction;
use App\Actions\ProcessWalletClearanceAction;
use App\Actions\ReconcilePaymentCallbackAction;
use App\Actions\RequestClosedAccountWalletClearanceAction;
use App\Data\VerifiedPaymentResult;
use App\Enums\InvoiceStatus;
use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\InstanceMappingReview;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\ReconciliationCase;
use App\Models\User;
use App\Models\WalletClearance;
use App\Services\PaymentAttentionCases;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Queue::fake();
    Http::preventStrayRequests();
});

function closedInvoiceReleaseFixture(): array
{
    $owner = User::factory()->create(['credit' => '0.00', 'frozen_credit' => '4.00']);
    $email = $owner->email;
    $owner->forceFill(['email' => 'closed-'.Str::ulid().'@anonymized.invalid', 'deleted_at' => now(), 'anonymized_at' => now()])->save();
    $newUser = User::factory()->create(['email' => $email]);
    $actor = User::factory()->create();
    foreach (['finance.view', 'finance.reconcile', 'finance.wallet-clearance.reconcile', 'finance.wallet-clearance.approve',
        'finance.wallet-clearance.execute', 'finance.wallet-clearance.confirm'] as $ability) {
        Gate::define($ability, fn (User $candidate): bool => $candidate->id === $actor->id);
    }
    $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'gateway' => 'epay_wechat', 'credit_applied' => '4.00',
        'gateway_amount' => '6.00', 'expires_at' => now()->subMinute()]);
    $freeze = FinancialTransaction::create(['user_id' => $owner->id, 'invoice_id' => $invoice->id, 'type' => TransactionType::CREDIT_FREEZE,
        'amount' => '4.00', 'balance_after' => '0.00', 'frozen_after' => '4.00', 'reference_key' => 'freeze:'.$invoice->id]);
    $attempt = PaymentAttempt::create(['invoice_id' => $invoice->id, 'gateway' => 'epay_wechat', 'out_trade_no' => $invoice->invoice_no.'-ATTEMPT',
        'amount' => '6.00', 'status' => 'created']);

    return [$owner, $actor, $invoice, $freeze, $newUser, $attempt];
}

function payClosedReleaseSource(User $owner, User $actor, ReconciliationCase $case, string $amount): WalletClearance
{
    $clearance = app(RequestClosedAccountWalletClearanceAction::class)->execute($owner, $actor, ['request_id' => (string) Str::ulid(),
        'reconciliation_case_id' => $case->id, 'amount' => $amount, 'payout_method' => 'bank_transfer', 'payee_name' => 'Original owner',
        'payee_account' => 'PRIVATE-ORIGINAL-ACCOUNT', 'bank_name' => 'Isolated Bank', 'reason' => 'Original financial rights verified',
        'evidence' => 'PRIVATE-ORIGINAL-RIGHTS-EVIDENCE', 'confirm' => '1']);
    $operation = fn (string $action): array => ['request_id' => (string) Str::ulid(), 'operation' => $action, 'reason' => 'Verify actual external settlement', 'confirm' => '1'];
    $process = app(ProcessWalletClearanceAction::class);
    $process->execute($clearance, $actor, $operation('approve'));
    $started = $process->execute($clearance, $actor, $operation('start'));

    return $process->execute($clearance, $actor, [...$operation('paid'), 'intent_key' => $started->payout_intent_key,
        'external_reference' => 'CLOSED-RELEASE-'.$clearance->id, 'confirmed_amount' => $amount, 'currency' => 'CNY', 'evidence' => 'PRIVATE-ACTUAL-TRANSFER-PROOF']);
}

it('finishes closed receipt posting expiry original hold release and external clearance on the original financial identity', function (): void {
    [$owner, $actor, $invoice, $freeze, $newUser, $attempt] = closedInvoiceReleaseFixture();
    app(HandlePaymentCallbackAction::class)->execute(new VerifiedPaymentResult('epay_wechat', 'CLOSED-UNPAID-RELEASE', $invoice->invoice_no, '10.00'));
    $receiptCase = ReconciliationCase::sole();
    $posted = app(ReconcilePaymentCallbackAction::class)->execute($receiptCase, $actor, ['request_id' => (string) Str::ulid(),
        'expected_revision' => $receiptCase->revision, 'reason' => 'Verify original closed receipt', 'evidence' => 'Original callback evidence', 'confirm' => '1']);
    expect($posted->status)->toBe('awaiting_clearance')->and($invoice->fresh()->status)->toBe(InvoiceStatus::UNPAID);
    $expire = app(ExpireUnpaidInvoicesAction::class);
    $expire->executeOne($invoice->id);
    $release = FinancialTransaction::where('reference_key', 'unfreeze:'.$invoice->id)->sole();
    $releaseCase = ReconciliationCase::where('source_type', 'wallet_release')->sole();
    $original = User::withTrashed()->findOrFail($owner->id);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::EXPIRED)->and($release->type)->toBe(TransactionType::CREDIT_UNFREEZE)
        ->and($release->amount)->toBe('4.00')->and($original->credit)->toBe('14.00')->and($original->frozen_credit)->toBe('0.00')
        ->and($releaseCase->user_id)->toBe($owner->id)->and($releaseCase->source_credit_transaction_id)->toBe($release->id)
        ->and($attempt->fresh()->status)->toBe('expired')->and($newUser->fresh()->credit)->toBe('0.00');
    $counts = [FinancialTransaction::count(), ReconciliationCase::count(), DB::table('notification_events')->count()];
    $expire->executeOne($invoice->id);
    $expire->execute();
    expect([FinancialTransaction::count(), ReconciliationCase::count(), DB::table('notification_events')->count()])->toBe($counts);
    payClosedReleaseSource($owner, $actor, $posted, '10.00');
    payClosedReleaseSource($owner, $actor, $releaseCase, '4.00');
    expect(app(PaymentAttentionCases::class)->isResolved($posted->fresh()))->toBeTrue()
        ->and(app(PaymentAttentionCases::class)->isResolved($releaseCase->fresh()))->toBeTrue()
        ->and(User::withTrashed()->findOrFail($owner->id)->credit)->toBe('0.00')
        ->and(User::withTrashed()->findOrFail($owner->id)->anonymized_at)->not->toBeNull()
        ->and($newUser->fresh()->credit)->toBe('0.00')->and(FulfillmentTask::count())->toBe(0)->and(Instance::count())->toBe(0)
        ->and(DB::table('notification_events')->where('user_id', $newUser->id)->count())->toBe(0)
        ->and(FinancialTransaction::where('reference_key', 'settle:'.$invoice->id)->count())->toBe(0);
    Http::assertNothingSent();
});

it('refuses closed hold release when the original freeze proof is missing mismatched or already settled', function (string $problem): void {
    [$owner, $actor, $invoice, $freeze, $newUser] = closedInvoiceReleaseFixture();
    match ($problem) {
        'missing' => DB::table('financial_transactions')->where('id', $freeze->id)->delete(),
        'amount' => DB::table('financial_transactions')->where('id', $freeze->id)->update(['amount' => '3.99']),
        'owner' => DB::table('financial_transactions')->where('id', $freeze->id)->update(['user_id' => $newUser->id]),
        'settled' => FinancialTransaction::create(['user_id' => $owner->id, 'invoice_id' => $invoice->id, 'type' => TransactionType::CREDIT_SETTLE,
            'amount' => '4.00', 'balance_after' => '0.00', 'frozen_after' => '0.00', 'reference_key' => 'settle:'.$invoice->id]),
    };
    expect(fn () => app(ExpireUnpaidInvoicesAction::class)->executeOne($invoice->id))->toThrow(DomainException::class);
    $original = User::withTrashed()->findOrFail($owner->id);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::UNPAID)->and($original->credit)->toBe('0.00')->and($original->frozen_credit)->toBe('4.00')
        ->and(ReconciliationCase::count())->toBe(0)->and(FinancialTransaction::where('reference_key', 'unfreeze:'.$invoice->id)->count())->toBe(0);
})->with(['missing', 'amount', 'owner', 'settled']);

it('expires a closed invoice with no wallet hold without creating a fictitious source', function (): void {
    $owner = User::factory()->create(['deleted_at' => now(), 'anonymized_at' => now()]);
    $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'credit_applied' => '0.00', 'expires_at' => now()->subMinute()]);
    app(ExpireUnpaidInvoicesAction::class)->execute();
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::EXPIRED)->and(FinancialTransaction::count())->toBe(0)
        ->and(ReconciliationCase::count())->toBe(0)->and(FulfillmentTask::count())->toBe(0);
});

it('makes applied mapping review records immutable at the model boundary', function (): void {
    $instance = Instance::factory()->create();
    $review = InstanceMappingReview::create(['instance_id' => $instance->id, 'user_id' => $instance->user_id, 'actor_id' => $instance->user_id,
        'target_product_id' => $instance->product_id, 'request_id' => (string) Str::ulid(), 'review_fingerprint' => str_repeat('a', 64),
        'snapshot' => ['version' => 1], 'reason' => 'Applied immutable review', 'checked_at' => now(), 'confirmed_at' => now(), 'applied_at' => now()]);
    expect(fn () => $review->update(['reason' => 'Overwrite historical evidence']))->toThrow(LogicException::class);
    expect(fn () => $review->fresh()->delete())->toThrow(LogicException::class);
    expect($review->fresh()->reason)->toBe('Applied immutable review');
});
