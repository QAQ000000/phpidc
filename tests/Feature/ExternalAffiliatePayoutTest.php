<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Actions\ProcessExternalAffiliateWithdrawalAction;
use App\Actions\ReadExternalPayoutDetailsAction;
use App\Actions\ReencryptSecretsAction;
use App\Actions\RefundInvoiceAction;
use App\Actions\RequestAffiliateWithdrawalAction;
use App\Actions\RequestExternalAffiliateWithdrawalAction;
use App\Enums\TransactionType;
use App\Models\AffiliateCommission;
use App\Models\AffiliateWithdrawal;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Services\AffiliateFunds;
use App\Support\InstallationState;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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

function externalPayoutFixture(): array
{
    $owner = User::factory()->create(['credit' => '7.00', 'frozen_credit' => '2.00', 'affiliate_balance' => '30.00']);
    $actor = User::factory()->create();
    foreach (['external-payout-reviewer', 'external-payout-operator', 'external-payout-confirmer'] as $role) {
        app(AssignAdminRoleAction::class)->execute($actor, $role, 'Isolated external payout review');
    }

    return [$owner, $actor];
}

function externalPayoutInput(array $changes = []): array
{
    return array_replace(['request_id' => (string) Str::ulid(), 'amount' => '10.00', 'payout_method' => 'bank_transfer',
        'payee_name' => 'Isolated Payee', 'payee_account' => 'PRIVATE-ACCOUNT-123', 'bank_name' => 'Isolated Test Bank'], $changes);
}

function externalPayoutOperation(string $operation, array $changes = []): array
{
    return array_replace(['request_id' => (string) Str::ulid(), 'operation' => $operation, 'reason' => 'Isolated payout evidence review', 'confirm' => '1'], $changes);
}

function externalPayoutProcess(AffiliateWithdrawal $withdrawal, User $actor, string $operation, array $changes = []): AffiliateWithdrawal
{
    return app(ProcessExternalAffiliateWithdrawalAction::class)->execute($withdrawal, $actor, externalPayoutOperation($operation, $changes));
}

it('retains immutable encrypted payout intent and one pending reservation on a customer retry', function (): void {
    [$owner] = externalPayoutFixture();
    $input = externalPayoutInput();
    $withdrawal = app(RequestExternalAffiliateWithdrawalAction::class)->execute($owner, $input);
    expect(app(RequestExternalAffiliateWithdrawalAction::class)->execute($owner, $input)->id)->toBe($withdrawal->id)
        ->and(AffiliateWithdrawal::count())->toBe(1)->and($owner->fresh()->affiliate_balance)->toBe('30.00')
        ->and(app(AffiliateFunds::class)->pending($owner->id))->toBe('10.00')
        ->and(DB::table('affiliate_withdrawals')->value('encrypted_payout_details'))->not->toContain('PRIVATE-ACCOUNT-123')
        ->and(NotificationEvent::where('event_key', 'withdrawal:'.$withdrawal->id.':pending')->count())->toBe(1);
    expect(fn () => app(RequestExternalAffiliateWithdrawalAction::class)->execute($owner, array_replace($input, ['payee_account' => 'CHANGED'])))
        ->toThrow(ValidationException::class);
    expect(fn () => app(RequestAffiliateWithdrawalAction::class)->execute($owner, '10.00', $input['request_id']))->toThrow(ValidationException::class);
});

it('rejects invalid external amounts and incomplete recipients without a financial effect', function (array $changes): void {
    [$owner] = externalPayoutFixture();
    expect(fn () => app(RequestExternalAffiliateWithdrawalAction::class)->execute($owner, externalPayoutInput($changes)))->toThrow(ValidationException::class);
    expect(AffiliateWithdrawal::count())->toBe(0)->and(FinancialTransaction::count())->toBe(0)
        ->and($owner->fresh()->affiliate_balance)->toBe('30.00');
})->with(array_map(fn (array $row): array => [$row], [['amount' => '0.99'], ['amount' => '10000.01'], ['amount' => '1e1'], ['amount' => '1.001'],
    ['payee_account' => ' '], ['bank_name' => ''], ['payout_method' => 'unverified_api']]));

it('counts wallet and external pending applications once and stops counting a frozen application', function (): void {
    [$owner, $actor] = externalPayoutFixture();
    $withdrawal = app(RequestExternalAffiliateWithdrawalAction::class)->execute($owner, externalPayoutInput(['amount' => '20.00']));
    app(RequestAffiliateWithdrawalAction::class)->execute($owner, '10.00', (string) Str::ulid());
    expect(fn () => app(RequestExternalAffiliateWithdrawalAction::class)->execute($owner, externalPayoutInput(['amount' => '1.00'])))->toThrow(ValidationException::class);
    externalPayoutProcess($withdrawal, $actor, 'approve');
    expect($owner->fresh()->affiliate_balance)->toBe('10.00')->and($owner->fresh()->affiliate_frozen_balance)->toBe('20.00')
        ->and(app(AffiliateFunds::class)->pending($owner->id))->toBe('10.00');
});

it('keeps an unknown external transfer frozen until original evidence confirms one payment', function (): void {
    [$owner, $actor] = externalPayoutFixture();
    $withdrawal = app(RequestExternalAffiliateWithdrawalAction::class)->execute($owner, externalPayoutInput());
    $approve = externalPayoutOperation('approve');
    $action = app(ProcessExternalAffiliateWithdrawalAction::class);
    $action->execute($withdrawal, $actor, $approve);
    $action->execute($withdrawal, $actor, $approve);
    $started = externalPayoutProcess($withdrawal, $actor, 'start');
    $key = $started->payout_intent_key;
    expect($key)->toStartWith('EP')->and($owner->fresh()->affiliate_balance)->toBe('20.00')
        ->and($owner->fresh()->affiliate_frozen_balance)->toBe('10.00');
    externalPayoutProcess($withdrawal, $actor, 'unknown', ['intent_key' => $key, 'evidence' => 'PRIVATE-UNCERTAIN-EVIDENCE']);
    expect(fn () => externalPayoutProcess($withdrawal, $actor, 'start'))->toThrow(ValidationException::class);
    expect(fn () => externalPayoutProcess($withdrawal, $actor, 'cancel'))->toThrow(ValidationException::class);
    expect($withdrawal->fresh()->status)->toBe('unknown')->and($owner->fresh()->affiliate_frozen_balance)->toBe('10.00');
    $paid = externalPayoutOperation('paid', ['intent_key' => $key, 'evidence' => 'PRIVATE-COMPLETED-EVIDENCE',
        'external_reference' => ' complete-receipt-1 ', 'currency' => 'CNY', 'confirmed_amount' => '10.00']);
    $action->execute($withdrawal, $actor, $paid);
    $action->execute($withdrawal, $actor, $paid);
    expect($withdrawal->fresh()->status)->toBe('paid')->and($withdrawal->fresh()->external_reference)->toBe('COMPLETE-RECEIPT-1')
        ->and($owner->fresh()->affiliate_balance)->toBe('20.00')->and($owner->fresh()->affiliate_frozen_balance)->toBe('0.00')
        ->and($owner->fresh()->credit)->toBe('7.00')->and($owner->fresh()->frozen_credit)->toBe('2.00')
        ->and(FinancialTransaction::where('reference_key', 'external_payout_hold:'.$withdrawal->id)->count())->toBe(1)
        ->and(FinancialTransaction::where('reference_key', 'payout:'.$withdrawal->id)->count())->toBe(1)
        ->and(NotificationEvent::where('event_key', 'withdrawal:'.$withdrawal->id.':paid')->count())->toBe(1);
    expect(fn () => $action->execute($withdrawal, $actor, array_replace($paid, ['evidence' => 'Changed proof'])))->toThrow(ValidationException::class);
    expect(fn () => externalPayoutProcess($withdrawal, $actor, 'confirm_not_paid', ['intent_key' => $key, 'evidence' => 'False release']))->toThrow(ValidationException::class);
    $raw = json_encode(DB::table('audit_logs')->get(), JSON_THROW_ON_ERROR);
    expect($raw)->not->toContain('PRIVATE-')->and(DB::table('affiliate_withdrawals')->value('encrypted_payout_evidence'))->not->toContain('PRIVATE-')
        ->and(DB::table('affiliate_withdrawals')->value('operation_requests'))->not->toContain('PRIVATE-');
});

it('releases a frozen transfer exactly once only before intent or after proof of nonpayment', function (string $operation): void {
    [$owner, $actor] = externalPayoutFixture();
    $withdrawal = app(RequestExternalAffiliateWithdrawalAction::class)->execute($owner, externalPayoutInput());
    externalPayoutProcess($withdrawal, $actor, 'approve');
    $extra = [];
    if ($operation === 'confirm_not_paid') {
        $started = externalPayoutProcess($withdrawal, $actor, 'start');
        $extra = ['intent_key' => $started->payout_intent_key, 'evidence' => 'Original transfer definitively absent'];
    }
    $input = externalPayoutOperation($operation, $extra);
    $action = app(ProcessExternalAffiliateWithdrawalAction::class);
    $action->execute($withdrawal, $actor, $input);
    $action->execute($withdrawal, $actor, $input);
    expect($withdrawal->fresh()->status)->toBe($operation === 'cancel' ? 'cancelled' : 'failed')
        ->and($owner->fresh()->affiliate_balance)->toBe('30.00')->and($owner->fresh()->affiliate_frozen_balance)->toBe('0.00')
        ->and($owner->fresh()->credit)->toBe('7.00')
        ->and(FinancialTransaction::where('reference_key', 'external_payout_release:'.$withdrawal->id)->count())->toBe(1);
    expect(fn () => externalPayoutProcess($withdrawal, $actor, 'start'))->toThrow(ValidationException::class);
})->with(['cancel', 'confirm_not_paid']);

it('requires independent authority for every external operation including same-request replay', function (): void {
    [$owner, $actor] = externalPayoutFixture();
    $withdrawal = app(RequestExternalAffiliateWithdrawalAction::class)->execute($owner, externalPayoutInput());
    $walletOperator = User::factory()->create();
    app(AssignAdminRoleAction::class)->execute($walletOperator, 'finance-payout', 'Wallet authority only');
    expect(fn () => externalPayoutProcess($withdrawal, $walletOperator, 'approve'))->toThrow(AuthorizationException::class);
    $approve = externalPayoutOperation('approve');
    app(ProcessExternalAffiliateWithdrawalAction::class)->execute($withdrawal, $actor, $approve);
    app(AssignAdminRoleAction::class)->execute($actor, 'external-payout-reviewer', 'Revoked payout approval', revoke: true);
    expect(fn () => app(ProcessExternalAffiliateWithdrawalAction::class)->execute($withdrawal, $actor, $approve))->toThrow(AuthorizationException::class);
    expect(fn () => externalPayoutProcess($withdrawal, $walletOperator, 'start'))->toThrow(AuthorizationException::class);
});

it('rejects wrong intent currency amount or missing evidence while preserving the hold', function (array $changes): void {
    [$owner, $actor] = externalPayoutFixture();
    $withdrawal = app(RequestExternalAffiliateWithdrawalAction::class)->execute($owner, externalPayoutInput());
    externalPayoutProcess($withdrawal, $actor, 'approve');
    $started = externalPayoutProcess($withdrawal, $actor, 'start');
    $extra = array_replace(['intent_key' => $started->payout_intent_key, 'currency' => 'CNY', 'confirmed_amount' => '10.00',
        'evidence' => 'Completed proof', 'external_reference' => 'completed-receipt'], $changes);
    expect(fn () => externalPayoutProcess($withdrawal, $actor, 'paid', $extra))->toThrow(ValidationException::class);
    expect($withdrawal->fresh()->status)->toBe('processing')->and($owner->fresh()->affiliate_frozen_balance)->toBe('10.00')
        ->and(FinancialTransaction::where('type', TransactionType::AFFILIATE_PAYOUT)->count())->toBe(0);
})->with(array_map(fn (array $row): array => [$row], [['intent_key' => 'CHANGED'], ['currency' => 'USD'], ['confirmed_amount' => '9.99'], ['evidence' => ' '], ['external_reference' => '']]));

it('prevents one normalized external receipt from paying two withdrawals', function (): void {
    [$owner, $actor] = externalPayoutFixture();
    foreach (['paid', 'conflict'] as $slot) {
        $withdrawal = app(RequestExternalAffiliateWithdrawalAction::class)->execute($owner, externalPayoutInput());
        externalPayoutProcess($withdrawal, $actor, 'approve');
        $started = externalPayoutProcess($withdrawal, $actor, 'start');
        $extra = ['intent_key' => $started->payout_intent_key, 'currency' => 'CNY', 'confirmed_amount' => '10.00',
            'evidence' => 'Verified completed payment', 'external_reference' => $slot === 'paid' ? ' SAME-RECEIPT ' : 'same-receipt'];
        if ($slot === 'paid') {
            externalPayoutProcess($withdrawal, $actor, 'paid', $extra);
        } else {
            expect(fn () => externalPayoutProcess($withdrawal, $actor, 'paid', $extra))->toThrow(ValidationException::class);
            expect($withdrawal->fresh()->status)->toBe('processing');
        }
    }
    expect($owner->fresh()->affiliate_balance)->toBe('10.00')->and($owner->fresh()->affiliate_frozen_balance)->toBe('10.00')
        ->and(FinancialTransaction::where('type', TransactionType::AFFILIATE_PAYOUT)->count())->toBe(1);
});

it('rolls approval back when its ledger audit or durable notification cannot persist', function (string $table): void {
    [$owner, $actor] = externalPayoutFixture();
    $withdrawal = app(RequestExternalAffiliateWithdrawalAction::class)->execute($owner, externalPayoutInput());
    $events = NotificationEvent::count();
    $active = true;
    DB::listen(function (QueryExecuted $query) use ($table, &$active): void {
        if ($active && preg_match('/^insert into [`"]?'.preg_quote($table, '/').'[`"]? /i', $query->sql) === 1) {
            throw new RuntimeException('Isolated external persistence failure');
        }
    });
    try {
        expect(fn () => externalPayoutProcess($withdrawal, $actor, 'approve'))->toThrow(RuntimeException::class, 'Isolated external persistence failure');
    } finally {
        $active = false;
    }
    expect($withdrawal->fresh()->status)->toBe('pending')->and($owner->fresh()->affiliate_balance)->toBe('30.00')
        ->and($owner->fresh()->affiliate_frozen_balance)->toBe('0.00')->and(FinancialTransaction::count())->toBe(0)
        ->and(NotificationEvent::count())->toBe($events);
})->with(['financial_transactions', 'audit_logs', 'notification_events']);

it('keeps external holds separate from refund clawback and never treats release as receivable repayment', function (): void {
    [$owner, $actor] = externalPayoutFixture();
    $owner->forceFill(['affiliate_balance' => '10.00'])->save();
    $withdrawal = app(RequestExternalAffiliateWithdrawalAction::class)->execute($owner, externalPayoutInput());
    externalPayoutProcess($withdrawal, $actor, 'approve');
    $invoice = Invoice::factory()->paid()->create(['total_amount' => '100.00', 'gateway_amount' => '100.00']);
    AffiliateCommission::factory()->create(['referrer_id' => $owner->id, 'buyer_id' => $invoice->user_id,
        'invoice_id' => $invoice->id, 'amount' => '10.00', 'status' => 'available']);
    app(AssignAdminRoleAction::class)->execute($actor, 'finance-operator', 'Isolated refund authority');
    app(RefundInvoiceAction::class)->execute($invoice, 'external_record', true, 'Already completed isolated refund', $actor);
    expect($owner->fresh()->affiliate_frozen_balance)->toBe('10.00')->and(app(AffiliateFunds::class)->hasReceivable($owner->id))->toBeTrue();
    expect(fn () => externalPayoutProcess($withdrawal, $actor, 'start'))->toThrow(ValidationException::class);
    externalPayoutProcess($withdrawal, $actor, 'cancel');
    expect($owner->fresh()->affiliate_balance)->toBe('10.00')->and($owner->fresh()->affiliate_frozen_balance)->toBe('0.00')
        ->and(app(AffiliateFunds::class)->hasReceivable($owner->id))->toBeTrue();
    expect(fn () => app(RequestExternalAffiliateWithdrawalAction::class)->execute($owner, externalPayoutInput()))->toThrow(ValidationException::class);
});

it('rolls terminal evidence and money back when result persistence fails', function (string $operation): void {
    [$owner, $actor] = externalPayoutFixture();
    $withdrawal = app(RequestExternalAffiliateWithdrawalAction::class)->execute($owner, externalPayoutInput());
    externalPayoutProcess($withdrawal, $actor, 'approve');
    $started = externalPayoutProcess($withdrawal, $actor, 'start');
    $before = FinancialTransaction::count();
    $active = true;
    DB::listen(function (QueryExecuted $query) use (&$active): void {
        if ($active && preg_match('/^insert into [`"]?audit_logs[`"]? /i', $query->sql) === 1) {
            throw new RuntimeException('Isolated terminal payout audit failure');
        }
    });
    try {
        expect(fn () => externalPayoutProcess($withdrawal, $actor, $operation, ['intent_key' => $started->payout_intent_key,
            'evidence' => 'Private terminal proof', 'external_reference' => 'TERMINAL-ROLLBACK', 'currency' => 'CNY', 'confirmed_amount' => '10.00']))
            ->toThrow(RuntimeException::class, 'Isolated terminal payout audit failure');
    } finally {
        $active = false;
    }
    expect($withdrawal->fresh()->status)->toBe('processing')->and($withdrawal->fresh()->encrypted_payout_evidence)->toBeNull()
        ->and($withdrawal->fresh()->external_reference)->toBeNull()->and($owner->fresh()->affiliate_balance)->toBe('20.00')
        ->and($owner->fresh()->affiliate_frozen_balance)->toBe('10.00')->and(FinancialTransaction::count())->toBe($before);
})->with(['paid', 'confirm_not_paid']);

it('rotates payout recipient evidence and encrypted operation identity without changing money', function (): void {
    [$owner, $actor] = externalPayoutFixture();
    $withdrawal = app(RequestExternalAffiliateWithdrawalAction::class)->execute($owner, externalPayoutInput());
    externalPayoutProcess($withdrawal, $actor, 'approve');
    $started = externalPayoutProcess($withdrawal, $actor, 'start');
    $unknown = externalPayoutOperation('unknown', ['intent_key' => $started->payout_intent_key, 'evidence' => 'PRIVATE-ROTATION-PROOF']);
    app(ProcessExternalAffiliateWithdrawalAction::class)->execute($withdrawal, $actor, $unknown);
    $columns = ['encrypted_payout_details', 'encrypted_payout_evidence', 'operation_requests'];
    $before = $withdrawal->fresh()->only($columns);
    $raw = (array) DB::table('affiliate_withdrawals')->where('id', $withdrawal->id)->first($columns);
    $oldKey = config('app.key');
    $newKey = 'base64:'.base64_encode(random_bytes(32));
    $installation = app(InstallationState::class);
    $installation->write('installed.lock', $installation->seal(['format' => 1, 'admin_id' => $actor->id], $oldKey));
    File::put(storage_path('framework/down'), '{}');
    app(ReencryptSecretsAction::class)->execute($oldKey, $newKey, 'Isolated payout key rotation', true);
    $previous = app('encrypter');
    try {
        config(['app.key' => $newKey]);
        app()->instance('encrypter', $installation->encrypter($newKey));
        Crypt::clearResolvedInstance('encrypter');
        expect($withdrawal->fresh()->only($columns))->toBe($before);
        foreach ($columns as $column) {
            expect(DB::table('affiliate_withdrawals')->where('id', $withdrawal->id)->value($column))->not->toBe($raw[$column]);
        }
        expect(app(ProcessExternalAffiliateWithdrawalAction::class)->execute($withdrawal, $actor, $unknown)->status)->toBe('unknown')
            ->and($owner->fresh()->affiliate_balance)->toBe('20.00')->and($owner->fresh()->affiliate_frozen_balance)->toBe('10.00');
    } finally {
        config(['app.key' => $oldKey]);
        app()->instance('encrypter', $previous);
        Crypt::clearResolvedInstance('encrypter');
    }
});

it('protects payee details with explicit audited access and avoids flashing sensitive inputs on errors', function (): void {
    [$owner, $actor] = externalPayoutFixture();
    $withdrawal = app(RequestExternalAffiliateWithdrawalAction::class)->execute($owner, externalPayoutInput());
    $this->actingAs($owner)->get('/console/affiliate/external')->assertOk()->assertDontSee('PRIVATE-ACCOUNT-123');
    $this->actingAs($owner)->post('/admin/external-payouts/'.$withdrawal->id.'/details', ['reason' => 'Unauthorized view', 'confirm' => '1'])->assertForbidden();
    $this->actingAs($actor)->post('/admin/external-payouts/'.$withdrawal->id.'/details', ['reason' => 'Verify original payee', 'confirm' => '1'])
        ->assertOk()->assertSee('PRIVATE-ACCOUNT-123')->assertHeader('Cache-Control', 'no-store, private');
    expect(DB::table('audit_logs')->where('action', 'affiliate.external_details_view')->count())->toBe(1);
    $this->actingAs($owner)->from('/console/affiliate/external')->post('/console/affiliate/external', externalPayoutInput(['amount' => '0.01']))
        ->assertRedirect('/console/affiliate/external')->assertSessionHasErrors('amount');
    expect(session()->getOldInput('payee_account'))->toBeNull()->and(session()->getOldInput('payee_name'))->toBeNull()
        ->and(session()->getOldInput('bank_name'))->toBeNull();
    app(AssignAdminRoleAction::class)->execute($actor, 'external-payout-operator', 'Revoked payee access', revoke: true);
    app(AssignAdminRoleAction::class)->execute($actor, 'external-payout-confirmer', 'Revoked confirm payee access', revoke: true);
    expect(fn () => app(ReadExternalPayoutDetailsAction::class)->execute($withdrawal, $actor, 'No longer authorized'))->toThrow(AuthorizationException::class);
});
