<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Actions\CloseCustomerAccountAction;
use App\Actions\HandlePaymentCallbackAction;
use App\Actions\ProcessWalletClearanceAction;
use App\Actions\RefundInvoiceAction;
use App\Actions\RequestClosedAccountWalletClearanceAction;
use App\Actions\RequestWalletClearanceAction;
use App\Data\VerifiedPaymentResult;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\PaymentCallback;
use App\Models\ReconciliationCase;
use App\Models\User;
use App\Models\WalletClearance;
use App\Services\AccountClosureEligibility;
use App\Services\NotificationManager;
use App\Services\PaymentAttentionCases;
use App\Services\WalletClearanceEligibility;
use App\Support\WalletCapacity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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

function walletClearanceFixture(): array
{
    $owner = User::factory()->create(['password' => 'Original-Password-123', 'credit' => '25.00',
        'frozen_credit' => '7.00', 'affiliate_balance' => '9.00']);
    $actor = User::factory()->create();
    foreach (['wallet-clearance-reviewer', 'wallet-clearance-operator', 'wallet-clearance-confirmer'] as $role) {
        app(AssignAdminRoleAction::class)->execute($actor, $role, 'Isolated wallet clearance acceptance');
    }

    return [$owner, $actor];
}

function walletClearanceRequest(array $changes = []): array
{
    return array_replace(['request_id' => (string) Str::ulid(), 'amount' => '10.00', 'payout_method' => 'bank_transfer',
        'payee_name' => 'Isolated Original Payee', 'payee_account' => 'PRIVATE-WALLET-ACCOUNT', 'bank_name' => 'Isolated Test Bank',
        'current_password' => 'Original-Password-123', 'confirm' => '1'], $changes);
}

function walletClearanceOperation(string $operation, array $changes = []): array
{
    return array_replace(['request_id' => (string) Str::ulid(), 'operation' => $operation,
        'reason' => 'Isolated financial confirmation', 'confirm' => '1'], $changes);
}

function walletClearanceProcess(WalletClearance $clearance, User $actor, string $operation, array $changes = []): WalletClearance
{
    return app(ProcessWalletClearanceAction::class)->execute($clearance, $actor, walletClearanceOperation($operation, $changes));
}

function walletClearancePay(WalletClearance $clearance, User $actor): WalletClearance
{
    walletClearanceProcess($clearance, $actor, 'approve');
    $started = walletClearanceProcess($clearance, $actor, 'start');

    return walletClearanceProcess($clearance, $actor, 'paid', ['intent_key' => $started->payout_intent_key,
        'evidence' => 'PRIVATE-WALLET-PAID-EVIDENCE', 'external_reference' => 'WALLET-RECEIPT-'.$clearance->id,
        'confirmed_amount' => $clearance->amount, 'currency' => 'CNY']);
}

function closedWalletRefundFixture(): array
{
    [$owner, $actor] = walletClearanceFixture();
    $owner->forceFill(['credit' => '0.00', 'frozen_credit' => '0.00', 'affiliate_balance' => '0.00'])->save();
    $email = $owner->email;
    $instance = Instance::factory()->create(['user_id' => $owner->id, 'status' => InstanceStatus::CANCELLED]);
    $invoice = Invoice::factory()->paid()->create(['user_id' => $owner->id, 'product_id' => $instance->product_id,
        'instance_id' => $instance->id, 'total_amount' => '10.00', 'gateway_amount' => '10.00', 'credit_applied' => '0.00']);
    $closed = app(CloseCustomerAccountAction::class)->execute($owner, 'Original-Password-123', true);
    $new = User::factory()->create(['email' => $email, 'credit' => '0.00']);
    Gate::define('refund-invoice', fn (): bool => true);
    $credit = app(RefundInvoiceAction::class)->execute($invoice, 'refund_wallet', reason: 'Real old-identity wallet refund', actor: $actor);
    $case = ReconciliationCase::where('source_credit_transaction_id', $credit->id)->sole();

    return [$closed, $actor, $case, $new, $invoice];
}

function closedWalletClearanceRequest(ReconciliationCase $case, array $changes = []): array
{
    return walletClearanceRequest(array_replace(['reconciliation_case_id' => $case->id,
        'reason' => 'Original financial identity confirmed', 'evidence' => 'PRIVATE-ORIGINAL-RIGHTS-EVIDENCE'], $changes));
}

it('accepts a password-confirmed immutable request without freezing money and excludes a second unfinished request', function (): void {
    [$owner] = walletClearanceFixture();
    $input = walletClearanceRequest();
    $action = app(RequestWalletClearanceAction::class);
    $clearance = $action->execute($owner, $input);
    expect($action->execute($owner, $input)->id)->toBe($clearance->id)
        ->and($clearance->status)->toBe('pending')->and($clearance->scenario)->toBe('closure_balance')
        ->and($owner->fresh()->credit)->toBe('25.00')->and($owner->fresh()->wallet_clearance_frozen)->toBe('0.00')
        ->and(FinancialTransaction::count())->toBe(0)->and(WalletClearance::count())->toBe(1)
        ->and(DB::table('wallet_clearances')->value('payout_details'))->not->toContain('PRIVATE-WALLET-ACCOUNT')
        ->and($clearance->toArray())->not->toHaveKeys(['payout_details', 'payout_evidence', 'operation_requests']);
    expect(fn () => $action->execute($owner, walletClearanceRequest()))->toThrow(ValidationException::class);
    expect(fn () => $action->execute($owner, array_replace($input, ['payee_account' => 'changed'])))->toThrow(ValidationException::class);
});

it('requires the owner password explicit confirmation fixed-point limits and complete recipient', function (array $changes): void {
    [$owner] = walletClearanceFixture();
    expect(fn () => app(RequestWalletClearanceAction::class)->execute($owner, walletClearanceRequest($changes)))->toThrow(ValidationException::class);
    expect(WalletClearance::count())->toBe(0)->and(FinancialTransaction::count())->toBe(0)
        ->and($owner->fresh()->credit)->toBe('25.00');
})->with(array_map(fn (array $row): array => [$row], [
    ['current_password' => 'wrong'], ['confirm' => '0'], ['amount' => '0.00'], ['amount' => '10000.01'],
    ['amount' => '1e1'], ['amount' => '1.001'], ['amount' => '26.00'], ['payee_account' => ' '], ['bank_name' => ''],
]));

it('freezes only available wallet money exactly once and preserves invoice and affiliate balances', function (): void {
    [$owner, $actor] = walletClearanceFixture();
    $clearance = app(RequestWalletClearanceAction::class)->execute($owner, walletClearanceRequest());
    $input = walletClearanceOperation('approve');
    $action = app(ProcessWalletClearanceAction::class);
    $action->execute($clearance, $actor, $input);
    $action->execute($clearance, $actor, $input);
    expect($owner->fresh()->credit)->toBe('15.00')->and($owner->fresh()->wallet_clearance_frozen)->toBe('10.00')
        ->and($owner->fresh()->frozen_credit)->toBe('7.00')->and($owner->fresh()->affiliate_balance)->toBe('9.00')
        ->and($clearance->fresh()->status)->toBe('reserved')
        ->and(FinancialTransaction::where('reference_key', 'wallet_clearance:'.$clearance->id.':hold')->count())->toBe(1)
        ->and(app(AccountClosureEligibility::class)->forUser($owner->fresh())['eligible'])->toBeFalse();
    expect(fn () => $action->execute($clearance, $actor, array_replace($input, ['reason' => 'Changed reason'])))->toThrow(ValidationException::class);
});

it('revalidates current funds before approval without treating a pending request as a hold', function (): void {
    [$owner, $actor] = walletClearanceFixture();
    $clearance = app(RequestWalletClearanceAction::class)->execute($owner, walletClearanceRequest());
    $owner->forceFill(['credit' => '1.00'])->save();
    expect(fn () => walletClearanceProcess($clearance, $actor, 'approve'))->toThrow(ValidationException::class);
    expect($clearance->fresh()->status)->toBe('pending')->and($owner->fresh()->wallet_clearance_frozen)->toBe('0.00')
        ->and(FinancialTransaction::count())->toBe(0);
});

it('keeps an unknown transfer frozen until original evidence confirms exactly one payment', function (): void {
    [$owner, $actor] = walletClearanceFixture();
    $clearance = app(RequestWalletClearanceAction::class)->execute($owner, walletClearanceRequest());
    walletClearanceProcess($clearance, $actor, 'approve');
    $started = walletClearanceProcess($clearance, $actor, 'start');
    walletClearanceProcess($clearance, $actor, 'unknown', ['intent_key' => $started->payout_intent_key, 'evidence' => 'PRIVATE-UNKNOWN-WALLET']);
    expect(fn () => walletClearanceProcess($clearance, $actor, 'cancel'))->toThrow(ValidationException::class);
    expect(fn () => walletClearanceProcess($clearance, $actor, 'start'))->toThrow(ValidationException::class);
    expect(fn () => walletClearanceProcess($clearance, $actor, 'paid', ['intent_key' => 'different', 'evidence' => 'proof',
        'external_reference' => 'RECEIPT', 'confirmed_amount' => '10.00', 'currency' => 'CNY']))->toThrow(ValidationException::class);
    expect($owner->fresh()->credit)->toBe('15.00')->and($owner->fresh()->wallet_clearance_frozen)->toBe('10.00');
    $input = walletClearanceOperation('paid', ['intent_key' => $started->payout_intent_key, 'evidence' => 'PRIVATE-COMPLETED-WALLET',
        'external_reference' => ' original-wallet-receipt ', 'confirmed_amount' => '10.00', 'currency' => 'CNY']);
    app(ProcessWalletClearanceAction::class)->execute($clearance, $actor, $input);
    app(ProcessWalletClearanceAction::class)->execute($clearance, $actor, $input);
    expect($clearance->fresh()->status)->toBe('paid')->and($clearance->fresh()->external_reference)->toBe('ORIGINAL-WALLET-RECEIPT')
        ->and($owner->fresh()->credit)->toBe('15.00')->and($owner->fresh()->wallet_clearance_frozen)->toBe('0.00')
        ->and($owner->fresh()->frozen_credit)->toBe('7.00')
        ->and(DB::table('external_payout_receipts')->where('wallet_clearance_id', $clearance->id)->count())->toBe(1)
        ->and(FinancialTransaction::where('reference_key', 'wallet_clearance:'.$clearance->id.':paid')->count())->toBe(1);
    expect(fn () => walletClearanceProcess($clearance, $actor, 'confirm_not_paid', ['intent_key' => $started->payout_intent_key, 'evidence' => 'false reversal']))
        ->toThrow(ValidationException::class);
    expect(json_encode(DB::table('audit_logs')->get(), JSON_THROW_ON_ERROR))->not->toContain('PRIVATE-');
    expect(DB::table('wallet_clearances')->value('payout_evidence'))->not->toContain('PRIVATE-');
    expect(DB::table('wallet_clearances')->value('operation_requests'))->not->toContain('PRIVATE-');
    Http::assertNothingSent();
});

it('releases a hold exactly once only before intent or after proof of nonpayment', function (string $operation): void {
    [$owner, $actor] = walletClearanceFixture();
    $clearance = app(RequestWalletClearanceAction::class)->execute($owner, walletClearanceRequest());
    walletClearanceProcess($clearance, $actor, 'approve');
    $extra = [];
    if ($operation === 'confirm_not_paid') {
        $started = walletClearanceProcess($clearance, $actor, 'start');
        $extra = ['intent_key' => $started->payout_intent_key, 'evidence' => 'Confirmed not paid'];
    }
    $input = walletClearanceOperation($operation, $extra);
    app(ProcessWalletClearanceAction::class)->execute($clearance, $actor, $input);
    app(ProcessWalletClearanceAction::class)->execute($clearance, $actor, $input);
    expect($owner->fresh()->credit)->toBe('25.00')->and($owner->fresh()->wallet_clearance_frozen)->toBe('0.00')
        ->and($owner->fresh()->frozen_credit)->toBe('7.00')
        ->and(FinancialTransaction::where('reference_key', 'wallet_clearance:'.$clearance->id.':release')->count())->toBe(1)
        ->and(DB::table('external_payout_receipts')->count())->toBe(0);
})->with(['cancel', 'confirm_not_paid']);

it('rejects a frozen column overflow before approval and preserves the original balances', function (): void {
    [$owner, $actor] = walletClearanceFixture();
    $owner->forceFill(['wallet_clearance_frozen' => WalletCapacity::LIMIT])->save();
    $clearance = app(RequestWalletClearanceAction::class)->execute($owner, walletClearanceRequest());
    expect(fn () => walletClearanceProcess($clearance, $actor, 'approve'))->toThrow(ValidationException::class);
    expect($owner->fresh()->credit)->toBe('25.00')->and($owner->fresh()->wallet_clearance_frozen)->toBe(WalletCapacity::LIMIT)
        ->and($clearance->fresh()->status)->toBe('pending')->and(FinancialTransaction::count())->toBe(0);
});

it('keeps financial review permissions distinct from execution and confirmation', function (): void {
    [$owner, $actor] = walletClearanceFixture();
    $reviewer = User::factory()->create();
    app(AssignAdminRoleAction::class)->execute($reviewer, 'wallet-clearance-reviewer', 'Review only');
    $clearance = app(RequestWalletClearanceAction::class)->execute($owner, walletClearanceRequest());
    walletClearanceProcess($clearance, $reviewer, 'approve');
    expect(fn () => walletClearanceProcess($clearance, $reviewer, 'start'))->toThrow(AuthorizationException::class);
    $started = walletClearanceProcess($clearance, $actor, 'start');
    expect(fn () => walletClearanceProcess($clearance, $reviewer, 'paid', ['intent_key' => $started->payout_intent_key,
        'evidence' => 'proof', 'external_reference' => 'R', 'confirmed_amount' => '10.00', 'currency' => 'CNY']))->toThrow(AuthorizationException::class);
    expect($owner->fresh()->wallet_clearance_frozen)->toBe('10.00')->and($clearance->fresh()->status)->toBe('processing');
});

it('blocks unresolved receivables and unknown payment results without blocking a capacity case itself', function (string $blocker): void {
    [$owner] = walletClearanceFixture();
    if ($blocker === 'receivable') {
        FinancialTransaction::create(['user_id' => $owner->id, 'type' => TransactionType::AFFILIATE_CLAWBACK,
            'amount' => '1.00', 'reference_key' => 'broken-receivable', 'meta' => ['receivable_amount' => '1.00']]);
    } else {
        $invoice = Invoice::factory()->create(['user_id' => $owner->id]);
        PaymentAttempt::create(['invoice_id' => $invoice->id, 'gateway' => 'epay_wechat', 'out_trade_no' => 'UNKNOWN-CLEARANCE',
            'amount' => '1.00', 'status' => 'unknown']);
    }
    expect(fn () => app(RequestWalletClearanceAction::class)->execute($owner, walletClearanceRequest()))->toThrow(ValidationException::class);
    expect(WalletClearance::count())->toBe(0)->and($owner->fresh()->credit)->toBe('25.00');
})->with(['receivable', 'payment']);

it('retains legal anonymous wallet refunds and clears their original source in partial payments without reaching a reused email', function (): void {
    [$closed, $actor, $case, $new, $invoice] = closedWalletRefundFixture();
    expect($case->source_type)->toBe('wallet_credit')->and($case->status)->toBe('awaiting_clearance')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::REFUNDED)
        ->and(User::withTrashed()->findOrFail($closed->id)->credit)->toBe('10.00');
    $input = closedWalletClearanceRequest($case, ['amount' => '4.00']);
    $first = app(RequestClosedAccountWalletClearanceAction::class)->execute($closed, $actor, $input);
    expect(app(WalletClearanceEligibility::class)->sourceTotals($case)['held'])->toBe('0.00');
    walletClearanceProcess($first, $actor, 'approve');
    expect(app(WalletClearanceEligibility::class)->sourceTotals($case)['held'])->toBe('4.00');
    $started = walletClearanceProcess($first, $actor, 'start');
    walletClearanceProcess($first, $actor, 'unknown', ['intent_key' => $started->payout_intent_key, 'evidence' => 'Original result unknown']);
    expect($case->fresh()->status)->toBe('awaiting_clearance')->and(app(PaymentAttentionCases::class)->isResolved($case->fresh()))->toBeFalse();
    walletClearanceProcess($first, $actor, 'paid', ['intent_key' => $started->payout_intent_key, 'evidence' => 'Original transfer confirmed',
        'external_reference' => 'ANONYMOUS-FIRST', 'confirmed_amount' => '4.00', 'currency' => 'CNY']);
    expect($case->fresh()->status)->toBe('awaiting_clearance')
        ->and(app(WalletClearanceEligibility::class)->sourceTotals($case->fresh()))->toBe(['principal' => '10.00', 'paid' => '4.00', 'held' => '0.00', 'remaining' => '6.00']);
    expect(app(RequestClosedAccountWalletClearanceAction::class)->execute($closed, $actor, $input)->id)->toBe($first->id);
    $second = app(RequestClosedAccountWalletClearanceAction::class)->execute($closed, $actor, closedWalletClearanceRequest($case, ['amount' => '6.00']));
    walletClearancePay($second, $actor);
    expect($case->fresh()->status)->toBe('resolved')->and(app(PaymentAttentionCases::class)->isResolved($case->fresh()))->toBeTrue()
        ->and(User::withTrashed()->findOrFail($closed->id)->credit)->toBe('0.00')
        ->and(User::withTrashed()->findOrFail($closed->id)->wallet_clearance_frozen)->toBe('0.00')
        ->and(User::withTrashed()->findOrFail($closed->id)->trashed())->toBeTrue()->and($new->fresh()->credit)->toBe('0.00')
        ->and(DB::table('notification_events')->where('user_id', $new->id)->count())->toBe(0);
    expect(fn () => app(RequestClosedAccountWalletClearanceAction::class)->execute($closed, $actor, closedWalletClearanceRequest($case, ['amount' => '0.01'])))
        ->toThrow(ValidationException::class);
});

it('enforces source principal independently of a larger old wallet and rechecks it at approval', function (): void {
    [$closed, $actor, $case] = closedWalletRefundFixture();
    User::withTrashed()->whereKey($closed->id)->update(['credit' => '50.00']);
    expect(fn () => app(RequestClosedAccountWalletClearanceAction::class)->execute($closed, $actor, closedWalletClearanceRequest($case, ['amount' => '10.01'])))
        ->toThrow(ValidationException::class);
    $clearance = app(RequestClosedAccountWalletClearanceAction::class)->execute($closed, $actor, closedWalletClearanceRequest($case));
    DB::table('reconciliation_cases')->where('id', $case->id)->update(['source_fingerprint' => str_repeat('0', 64)]);
    expect(fn () => walletClearanceProcess($clearance, $actor, 'approve'))->toThrow(ValidationException::class);
    expect($clearance->fresh()->status)->toBe('pending')->and(User::withTrashed()->findOrFail($closed->id)->wallet_clearance_frozen)->toBe('0.00');
});

it('creates a usable source for actual late callbacks after closure and clears only the original financial identity', function (): void {
    [$owner, $actor] = walletClearanceFixture();
    $owner->forceFill(['credit' => '0.00', 'frozen_credit' => '0.00', 'affiliate_balance' => '0.00'])->save();
    $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'status' => InvoiceStatus::EXPIRED,
        'credit_applied' => '0.00', 'total_amount' => '7.50', 'gateway_amount' => '7.50', 'gateway' => 'epay_wechat']);
    $closed = app(CloseCustomerAccountAction::class)->execute($owner, 'Original-Password-123', true);
    app(HandlePaymentCallbackAction::class)->execute(new VerifiedPaymentResult('epay_wechat', 'CLOSED-CLEARANCE-CALLBACK', $invoice->invoice_no, '7.50', [], 'success'));
    $case = ReconciliationCase::where('user_id', $closed->id)->sole();
    $clearance = app(RequestClosedAccountWalletClearanceAction::class)->execute($closed, $actor, closedWalletClearanceRequest($case, ['amount' => '7.50']));
    walletClearancePay($clearance, $actor);
    expect($case->fresh()->status)->toBe('resolved')->and(User::withTrashed()->findOrFail($closed->id)->credit)->toBe('0.00')
        ->and(Instance::where('user_id', $closed->id)->count())->toBe(0)->and($invoice->fresh()->status)->toBe(InvoiceStatus::EXPIRED);
});

it('requires actual completed payout to free aggregate capacity and never replays the original rejected receipt', function (): void {
    [$owner, $actor] = walletClearanceFixture();
    $owner->forceFill(['credit' => WalletCapacity::LIMIT, 'frozen_credit' => '0.00'])->save();
    $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'status' => InvoiceStatus::EXPIRED,
        'credit_applied' => '0.00', 'total_amount' => '1.00', 'gateway_amount' => '1.00', 'gateway' => 'epay_wechat']);
    $payment = new VerifiedPaymentResult('epay_wechat', 'CAPACITY-ORIGINAL', $invoice->invoice_no, '1.00', [], 'success');
    app(HandlePaymentCallbackAction::class)->execute($payment);
    $case = ReconciliationCase::where('user_id', $owner->id)->sole();
    $clearance = app(RequestWalletClearanceAction::class)->execute($owner, walletClearanceRequest(['amount' => '1.00']));
    walletClearanceProcess($clearance, $actor, 'approve');
    expect($owner->fresh()->wallet_clearance_frozen)->toBe('1.00')->and($case->fresh()->status)->toBe('open');
    $started = walletClearanceProcess($clearance, $actor, 'start');
    app(HandlePaymentCallbackAction::class)->execute(new VerifiedPaymentResult('epay_wechat', 'CAPACITY-STILL-HELD', $invoice->invoice_no, '1.00', [], 'success'));
    expect(FinancialTransaction::where('type', TransactionType::CREDIT_IN)->count())->toBe(0);
    walletClearanceProcess($clearance, $actor, 'paid', ['intent_key' => $started->payout_intent_key, 'evidence' => 'Settled',
        'external_reference' => 'CAPACITY-CLEARANCE-PAID', 'confirmed_amount' => '1.00', 'currency' => 'CNY']);
    app(HandlePaymentCallbackAction::class)->execute($payment);
    expect($owner->fresh()->credit)->toBe('9999999998.99')->and($case->fresh()->status)->toBe('open');
    app(HandlePaymentCallbackAction::class)->execute(new VerifiedPaymentResult('epay_wechat', 'CAPACITY-NEW-TRADE', $invoice->invoice_no, '1.00', [], 'success'));
    expect($owner->fresh()->credit)->toBe(WalletCapacity::LIMIT)->and(PaymentCallback::count())->toBe(3);
});

it('rolls back wallet payment ledger receipt and status if the durable result cannot be stored', function (): void {
    [$owner, $actor] = walletClearanceFixture();
    $clearance = app(RequestWalletClearanceAction::class)->execute($owner, walletClearanceRequest());
    walletClearanceProcess($clearance, $actor, 'approve');
    $started = walletClearanceProcess($clearance, $actor, 'start');
    $real = app(NotificationManager::class);
    $broken = Mockery::mock(NotificationManager::class);
    $broken->shouldReceive('queue')->once()->andThrow(new RuntimeException('simulated durable event failure'));
    $this->app->instance(NotificationManager::class, $broken);
    $input = walletClearanceOperation('paid', ['intent_key' => $started->payout_intent_key, 'evidence' => 'Completed transfer proof',
        'external_reference' => 'RETRY-ORIGINAL-PROOF', 'confirmed_amount' => '10.00', 'currency' => 'CNY']);
    expect(fn () => app(ProcessWalletClearanceAction::class)->execute($clearance, $actor, $input))->toThrow(RuntimeException::class, 'simulated durable event failure');
    expect($clearance->fresh()->status)->toBe('processing')->and($owner->fresh()->wallet_clearance_frozen)->toBe('10.00')
        ->and(DB::table('external_payout_receipts')->count())->toBe(0)
        ->and(FinancialTransaction::where('reference_key', 'wallet_clearance:'.$clearance->id.':paid')->count())->toBe(0);
    $this->app->instance(NotificationManager::class, $real);
    app(ProcessWalletClearanceAction::class)->execute($clearance, $actor, $input);
    expect($clearance->fresh()->status)->toBe('paid')->and($owner->fresh()->wallet_clearance_frozen)->toBe('0.00');
});

it('settles the minimum cent without a fee deduction or movement in other balance pools', function (): void {
    [$owner, $actor] = walletClearanceFixture();
    $clearance = app(RequestWalletClearanceAction::class)->execute($owner, walletClearanceRequest(['amount' => '0.01']));
    walletClearancePay($clearance, $actor);
    expect($owner->fresh()->credit)->toBe('24.99')->and($owner->fresh()->wallet_clearance_frozen)->toBe('0.00')
        ->and($owner->fresh()->frozen_credit)->toBe('7.00')->and($owner->fresh()->affiliate_balance)->toBe('9.00')
        ->and(FinancialTransaction::where('reference_key', 'wallet_clearance:'.$clearance->id.':paid')->sole()->amount)->toBe('0.01');
});

it('rejects a damaged hold before execution instead of spending another reservation', function (): void {
    [$owner, $actor] = walletClearanceFixture();
    $clearance = app(RequestWalletClearanceAction::class)->execute($owner, walletClearanceRequest());
    walletClearanceProcess($clearance, $actor, 'approve');
    // Deliberately simulate out-of-band corruption; production ledger models are append-only.
    DB::table('financial_transactions')->where('reference_key', 'wallet_clearance:'.$clearance->id.':hold')->update(['amount' => '9.99']);
    expect(fn () => walletClearanceProcess($clearance, $actor, 'start'))->toThrow(ValidationException::class);
    expect($clearance->fresh()->status)->toBe('reserved')->and($clearance->fresh()->payout_intent_key)->toBeNull()
        ->and($owner->fresh()->wallet_clearance_frozen)->toBe('10.00');
});

it('does not consider a source resolved when its original hold paid ledger or receipt evidence is damaged', function (string $evidence): void {
    [$closed, $actor, $case] = closedWalletRefundFixture();
    $clearance = app(RequestClosedAccountWalletClearanceAction::class)->execute($closed, $actor, closedWalletClearanceRequest($case));
    walletClearancePay($clearance, $actor);
    expect(app(PaymentAttentionCases::class)->isResolved($case->fresh()))->toBeTrue();
    if ($evidence === 'receipt') {
        DB::table('external_payout_receipts')->where('wallet_clearance_id', $clearance->id)->update(['amount' => '9.99']);
    } else {
        DB::table('financial_transactions')->where('reference_key', 'wallet_clearance:'.$clearance->id.':'.$evidence)->update(['amount' => '9.99']);
    }
    expect(fn () => app(WalletClearanceEligibility::class)->sourceTotals($case->fresh()))->toThrow(ValidationException::class);
    expect(app(PaymentAttentionCases::class)->isResolved($case->fresh()))->toBeFalse();
})->with(['hold', 'paid', 'receipt']);
