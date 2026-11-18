<?php

declare(strict_types=1);

use App\Actions\CloseCustomerAccountAction;
use App\Actions\CreateComboInvoiceAction;
use App\Actions\CreateWalletTopupInvoiceAction;
use App\Actions\ExpireUnpaidInvoicesAction;
use App\Actions\HandlePaymentCallbackAction;
use App\Actions\ProcessAffiliateWithdrawalAction;
use App\Actions\RefundInvoiceAction;
use App\Actions\RequestAffiliateWithdrawalAction;
use App\Data\VerifiedPaymentResult;
use App\Enums\BillingCycle;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentCallbackOutcome;
use App\Enums\TransactionType;
use App\Models\AffiliateCommission;
use App\Models\FinancialTransaction;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\PaymentCallback;
use App\Models\Product;
use App\Models\RedemptionCode;
use App\Models\User;
use App\Services\AccountClosureEligibility;
use App\Services\SettingService;
use Illuminate\Support\Facades\Crypt;
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
    Queue::fake();
    Http::preventStrayRequests();
});

function capacityRegressionProduct(): Product
{
    return Product::factory()->create(['local_prices' => [
        'onetime' => ['price' => '10.00', 'setup_fee' => '0.00', 'enabled' => true],
    ]]);
}

function capacityRegressionReceipt(Invoice $invoice, string $amount, string $trade = 'CAPACITY-TRADE', ?string $outTradeNo = null): VerifiedPaymentResult
{
    return new VerifiedPaymentResult('epay_wechat', $trade, $outTradeNo ?? $invoice->invoice_no, $amount,
        rawPayload: ['trade_no' => $trade, 'money' => $amount], ackBody: 'capacity-ack');
}

function capacityRegressionEnableTopup(): void
{
    foreach (['wallet.topup_enabled' => '1', 'payment.epay_api_url' => 'https://pay.example.test',
        'payment.epay_pid' => 'capacity-test', 'payment.epay_key' => 'capacity-test-secret'] as $name => $value) {
        [$group, $key] = explode('.', $name, 2);
        DB::table('system_settings')->updateOrInsert(['group' => $group, 'key' => $key], [
            'value' => $key === 'epay_key' ? Crypt::encryptString($value) : $value,
            'is_encrypted' => $key === 'epay_key', 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(SettingService::class)->forget($name);
    }
}

it('preserves an entire ordinary overpayment for reconciliation when any wallet pool exhausts capacity', function (string $credit, string $frozen, string $clearance): void {
    $user = User::factory()->create(['credit' => $credit, 'frozen_credit' => $frozen, 'wallet_clearance_frozen' => $clearance]);
    $invoice = app(CreateComboInvoiceAction::class)->execute($user, capacityRegressionProduct(), BillingCycle::ONETIME,
        [], false, 'epay_wechat', ['request_id' => (string) Str::ulid()]);
    $attempt = $invoice->attempts()->create(['gateway' => 'epay_wechat', 'out_trade_no' => $invoice->invoice_no.'-attempt',
        'amount' => '10.00', 'status' => 'created']);
    $beforeInvoice = $invoice->fresh()->getRawOriginal();
    $beforeAttempt = $attempt->fresh()->getRawOriginal();
    $payment = capacityRegressionReceipt($invoice, '10.01', outTradeNo: $attempt->out_trade_no);

    expect(app(HandlePaymentCallbackAction::class)->execute($payment))->toBe('capacity-ack');
    $callback = PaymentCallback::sole();
    expect($callback->outcome)->toBe(PaymentCallbackOutcome::REJECTED_INVALID_STATE)
        ->and($callback->invoice_id)->toBe($invoice->id)->and($callback->amount)->toBe('10.01')
        ->and($callback->raw_payload)->toBe($payment->rawPayload)
        ->and($invoice->fresh()->getRawOriginal())->toBe($beforeInvoice)
        ->and($attempt->fresh()->getRawOriginal())->toBe($beforeAttempt)
        ->and($user->fresh()->credit)->toBe($credit)->and($user->fresh()->frozen_credit)->toBe($frozen)
        ->and($user->fresh()->wallet_clearance_frozen)->toBe($clearance);
    $this->assertDatabaseHas('audit_logs', ['action' => 'payment.callback.attention', 'subject_id' => $callback->id, 'reason' => 'wallet_capacity']);
    $this->assertDatabaseHas('notification_events', ['event_key' => 'callback:'.$callback->id.':attention', 'event_type' => 'payment.callback.attention']);
    foreach (['financial_transactions', 'instances', 'fulfillment_tasks'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }

    // Space becoming available cannot make a repeated gateway callback spend the same receipt again.
    $user->forceFill(['credit' => '0.00'])->save();
    expect(app(HandlePaymentCallbackAction::class)->execute($payment))->toBe('capacity-ack');
    expect($invoice->fresh()->getRawOriginal())->toBe($beforeInvoice)
        ->and($attempt->fresh()->getRawOriginal())->toBe($beforeAttempt)->and($user->fresh()->credit)->toBe('0.00');
    $this->assertDatabaseCount('payment_callbacks', 1);
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('notification_events', 1);
    expect(DB::table('audit_logs')->where('action', 'payment.callback.attention')->count())->toBe(1);
    Http::assertNothingSent();
})->with([
    'available column full' => ['9999999999.99', '0.00', '0.00'],
    'invoice hold consumes remaining room' => ['9999999999.98', '0.01', '0.00'],
    'clearance hold consumes remaining room' => ['9999999999.98', '0.00', '0.01'],
]);

it('accounts for consumed invoice funds before checking an ordinary overpayment final wallet state', function (): void {
    $user = User::factory()->create(['credit' => '5.00', 'frozen_credit' => '9999999993.99', 'wallet_clearance_frozen' => '1.00']);
    $invoice = app(CreateComboInvoiceAction::class)->execute($user, capacityRegressionProduct(), BillingCycle::ONETIME,
        [], true, 'epay_wechat', ['request_id' => (string) Str::ulid()]);
    $attempt = $invoice->attempts()->create(['gateway' => 'epay_wechat', 'out_trade_no' => $invoice->invoice_no.'-attempt',
        'amount' => '5.00', 'status' => 'created']);
    $payment = capacityRegressionReceipt($invoice, '10.00', outTradeNo: $attempt->out_trade_no);
    expect(app(HandlePaymentCallbackAction::class)->execute($payment))->toBe('capacity-ack');
    expect(app(HandlePaymentCallbackAction::class)->execute($payment))->toBe('capacity-ack');
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::PAID)->and($attempt->fresh()->status)->toBe('paid')
        ->and($user->fresh()->credit)->toBe('5.00')->and($user->fresh()->frozen_credit)->toBe('9999999993.99')
        ->and($user->fresh()->wallet_clearance_frozen)->toBe('1.00')
        ->and(PaymentCallback::sole()->outcome)->toBe(PaymentCallbackOutcome::PROCESSED_OVERPAYMENT);
    $this->assertDatabaseHas('financial_transactions', ['reference_key' => 'settle:'.$invoice->id, 'amount' => '5.00']);
    $this->assertDatabaseHas('financial_transactions', ['reference_key' => 'overpaid:'.PaymentCallback::sole()->id, 'amount' => '5.00']);
    $this->assertDatabaseCount('financial_transactions', 4);
    $this->assertDatabaseCount('fulfillment_tasks', 1);
    $this->assertDatabaseCount('instances', 1);
});

it('rejects topup creation and the full receipt against all pools while replaying the original request', function (): void {
    capacityRegressionEnableTopup();
    $user = User::factory()->create();
    $input = ['request_id' => (string) Str::ulid(), 'amount' => '1.00', 'gateway' => 'epay_wechat'];
    $invoice = app(CreateWalletTopupInvoiceAction::class)->execute($user, $input);
    $user->forceFill(['credit' => '9999999998.99', 'wallet_clearance_frozen' => '1.00'])->save();
    expect(app(CreateWalletTopupInvoiceAction::class)->execute($user, $input)->id)->toBe($invoice->id);
    expect(fn () => app(CreateWalletTopupInvoiceAction::class)->execute($user, [...$input, 'request_id' => (string) Str::ulid()]))
        ->toThrow(ValidationException::class);
    expect(app(HandlePaymentCallbackAction::class)->execute(capacityRegressionReceipt($invoice, '1.00')))->toBe('capacity-ack');
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::UNPAID)
        ->and($user->fresh()->credit)->toBe('9999999998.99')->and($user->fresh()->wallet_clearance_frozen)->toBe('1.00')
        ->and(PaymentCallback::sole()->outcome)->toBe(PaymentCallbackOutcome::REJECTED_INVALID_STATE);
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('fulfillment_tasks', 0);
    $this->assertDatabaseHas('audit_logs', ['action' => 'payment.callback.attention', 'reason' => 'wallet_capacity']);
});

it('replays a successfully credited topup before new capacity checks', function (): void {
    capacityRegressionEnableTopup();
    $user = User::factory()->create();
    $input = ['request_id' => (string) Str::ulid(), 'amount' => '1.00', 'gateway' => 'epay_wechat'];
    $invoice = app(CreateWalletTopupInvoiceAction::class)->execute($user, $input);
    app(HandlePaymentCallbackAction::class)->execute(capacityRegressionReceipt($invoice, '1.00'));
    $user->forceFill(['credit' => '9999999999.99', 'wallet_clearance_frozen' => '1.00'])->save();
    expect(app(CreateWalletTopupInvoiceAction::class)->execute($user, $input)->id)->toBe($invoice->id)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID)->and($user->fresh()->credit)->toBe('9999999999.99');
    $this->assertDatabaseCount('financial_transactions', 2);
    $this->assertDatabaseCount('invoices', 1);
});

it('rolls back the entire refund including service and commission changes when total wallet capacity is exhausted', function (): void {
    $buyer = User::factory()->create(['credit' => '9999999989.99', 'frozen_credit' => '5.00', 'wallet_clearance_frozen' => '5.00']);
    $instance = Instance::factory()->active()->create(['user_id' => $buyer->id]);
    $invoice = Invoice::factory()->paid()->create(['user_id' => $buyer->id, 'instance_id' => $instance->id,
        'product_id' => $instance->product_id, 'total_amount' => '10.00', 'gateway_amount' => '10.00']);
    $code = RedemptionCode::factory()->create(['instance_id' => $instance->id, 'product_id' => $instance->product_id,
        'status' => 'sold', 'sold_at' => now()]);
    $task = FulfillmentTask::factory()->create(['invoice_id' => $invoice->id, 'instance_id' => $instance->id]);
    $referrer = User::factory()->create(['affiliate_balance' => '2.00']);
    $commission = AffiliateCommission::factory()->create(['invoice_id' => $invoice->id, 'buyer_id' => $buyer->id,
        'referrer_id' => $referrer->id, 'amount' => '3.00', 'status' => 'available']);
    $actor = User::factory()->create();
    Gate::define('refund-invoice', fn (User $operator, Invoice $subject): bool => $operator->is($actor) && $subject->is($invoice));

    expect(fn () => app(RefundInvoiceAction::class)->execute($invoice, 'refund_wallet', true, 'Capacity regression', $actor))
        ->toThrow(DomainException::class, 'Refund exceeds the wallet capacity.');
    expect($buyer->fresh()->credit)->toBe('9999999989.99')->and($buyer->fresh()->frozen_credit)->toBe('5.00')
        ->and($buyer->fresh()->wallet_clearance_frozen)->toBe('5.00')->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and($instance->fresh()->status)->toBe(InstanceStatus::ACTIVE)->and($code->fresh()->status)->toBe('sold')
        ->and($task->fresh()->status)->toBe(FulfillmentTaskStatus::PENDING)
        ->and($referrer->fresh()->affiliate_balance)->toBe('2.00')->and($commission->fresh()->status)->toBe('available')
        ->and($commission->fresh()->clawed_back_at)->toBeNull();
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('audit_logs', 0);
    $this->assertDatabaseCount('notification_events', 0);
});

it('replays a completed refund before checking a now full wallet', function (): void {
    $buyer = User::factory()->create();
    $instance = Instance::factory()->create(['user_id' => $buyer->id, 'status' => InstanceStatus::CANCELLED]);
    $invoice = Invoice::factory()->paid()->create(['user_id' => $buyer->id, 'instance_id' => $instance->id]);
    $actor = User::factory()->create();
    Gate::define('refund-invoice', fn (User $operator, Invoice $subject): bool => $operator->is($actor) && $subject->is($invoice));
    $action = app(RefundInvoiceAction::class);
    $first = $action->execute($invoice, 'refund_wallet', reason: 'Initial refund', actor: $actor);
    $buyer->forceFill(['credit' => '9999999999.99', 'wallet_clearance_frozen' => '1.00'])->save();
    expect($action->execute($invoice, 'refund_wallet', reason: 'Same refund replay', actor: $actor)->id)->toBe($first->id)
        ->and($buyer->fresh()->credit)->toBe('9999999999.99')->and($invoice->fresh()->status)->toBe(InvoiceStatus::REFUNDED);
    $this->assertDatabaseCount('financial_transactions', 1);
    expect(DB::table('audit_logs')->where('action', 'invoice.refund')->count())->toBe(1);
});

it('keeps both balances and the request pending when affiliate transfer would exceed total wallet capacity', function (): void {
    $user = User::factory()->create(['credit' => '9999999989.99', 'frozen_credit' => '5.00',
        'wallet_clearance_frozen' => '5.00', 'affiliate_balance' => '10.00']);
    $withdrawal = app(RequestAffiliateWithdrawalAction::class)->execute($user, '10.00', (string) Str::ulid());
    $actor = User::factory()->create();
    Gate::define('finance.payout', fn (User $operator): bool => $operator->is($actor));
    $auditCount = DB::table('audit_logs')->count();
    expect(fn () => app(ProcessAffiliateWithdrawalAction::class)->execute($withdrawal, $actor, true, 'Capacity regression'))
        ->toThrow(ValidationException::class);
    expect($user->fresh()->credit)->toBe('9999999989.99')->and($user->fresh()->affiliate_balance)->toBe('10.00')
        ->and($user->fresh()->frozen_credit)->toBe('5.00')->and($user->fresh()->wallet_clearance_frozen)->toBe('5.00')
        ->and($withdrawal->fresh()->status)->toBe('pending')->and(DB::table('audit_logs')->count())->toBe($auditCount);
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('notification_events', 0);
});

it('replays an approved affiliate transfer before checking current wallet capacity', function (): void {
    $user = User::factory()->create(['affiliate_balance' => '10.00']);
    $withdrawal = app(RequestAffiliateWithdrawalAction::class)->execute($user, '10.00', (string) Str::ulid());
    $actor = User::factory()->create();
    Gate::define('finance.payout', fn (User $operator): bool => $operator->is($actor));
    $action = app(ProcessAffiliateWithdrawalAction::class);
    $action->execute($withdrawal, $actor, true, 'Initial wallet transfer');
    $user->forceFill(['credit' => '9999999999.99', 'wallet_clearance_frozen' => '1.00'])->save();
    $action->execute($withdrawal, $actor, true, 'Approved transfer replay');
    expect($user->fresh()->credit)->toBe('9999999999.99')->and($user->fresh()->affiliate_balance)->toBe('0.00')
        ->and($withdrawal->fresh()->status)->toBe('approved');
    $this->assertDatabaseCount('financial_transactions', 1);
    expect(DB::table('audit_logs')->where('action', 'affiliate.withdrawal_approved')->count())->toBe(1);
});

it('allows a lawful purchase to reduce a legacy excess without adjusting unrelated holds', function (): void {
    $user = User::factory()->create(['credit' => '20.00', 'frozen_credit' => '9999999980.00', 'wallet_clearance_frozen' => '20.00']);
    $input = ['request_id' => (string) Str::ulid()];
    $product = capacityRegressionProduct();
    $action = app(CreateComboInvoiceAction::class);
    $invoice = $action->execute($user, $product, BillingCycle::ONETIME, [], true, input: $input);
    expect($invoice->status)->toBe(InvoiceStatus::PAID)->and($user->fresh()->credit)->toBe('10.00')
        ->and($user->fresh()->frozen_credit)->toBe('9999999980.00')->and($user->fresh()->wallet_clearance_frozen)->toBe('20.00');
    expect($action->execute($user, $product, BillingCycle::ONETIME, [], true, input: $input)->id)->toBe($invoice->id);
    $this->assertDatabaseCount('financial_transactions', 2);
    $this->assertDatabaseCount('fulfillment_tasks', 1);
});

it('allows a legacy aggregate excess to unfreeze once when every destination column fits', function (): void {
    $user = User::factory()->create(['credit' => '5.00', 'frozen_credit' => '9999999994.99', 'wallet_clearance_frozen' => '10.00']);
    $invoice = Invoice::factory()->create(['user_id' => $user->id, 'credit_applied' => '5.00', 'gateway_amount' => '5.00', 'expires_at' => now()->subMinute()]);
    $attempt = $invoice->attempts()->create(['gateway' => 'epay_wechat', 'out_trade_no' => $invoice->invoice_no.'-attempt', 'amount' => '5.00', 'status' => 'created']);
    FinancialTransaction::create(['user_id' => $user->id, 'invoice_id' => $invoice->id, 'type' => TransactionType::CREDIT_FREEZE,
        'amount' => '5.00', 'reference_key' => 'freeze:'.$invoice->id]);
    app(ExpireUnpaidInvoicesAction::class)->execute();
    app(ExpireUnpaidInvoicesAction::class)->execute();
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::EXPIRED)->and($attempt->fresh()->status)->toBe('expired')
        ->and($user->fresh()->credit)->toBe('10.00')->and($user->fresh()->frozen_credit)->toBe('9999999989.99')
        ->and($user->fresh()->wallet_clearance_frozen)->toBe('10.00');
    $this->assertDatabaseHas('financial_transactions', ['reference_key' => 'unfreeze:'.$invoice->id, 'amount' => '5.00']);
    $this->assertDatabaseCount('financial_transactions', 2);
});

it('skips an overflowing expiry destination without blocking later eligible invoices', function (): void {
    $badUser = User::factory()->create(['credit' => '9999999999.99', 'frozen_credit' => '1.00']);
    $bad = Invoice::factory()->create(['user_id' => $badUser->id, 'credit_applied' => '1.00', 'gateway_amount' => '9.00', 'expires_at' => now()->subMinute()]);
    $badAttempt = $bad->attempts()->create(['gateway' => 'epay_wechat', 'out_trade_no' => $bad->invoice_no.'-attempt', 'amount' => '9.00', 'status' => 'created']);
    $goodUser = User::factory()->create(['frozen_credit' => '1.00']);
    $good = Invoice::factory()->create(['user_id' => $goodUser->id, 'credit_applied' => '1.00', 'gateway_amount' => '9.00', 'expires_at' => now()->subMinute()]);
    app(ExpireUnpaidInvoicesAction::class)->execute();
    expect($bad->fresh()->status)->toBe(InvoiceStatus::UNPAID)->and($badAttempt->fresh()->status)->toBe('created')
        ->and($badUser->fresh()->credit)->toBe('9999999999.99')->and($badUser->fresh()->frozen_credit)->toBe('1.00')
        ->and($good->fresh()->status)->toBe(InvoiceStatus::EXPIRED)->and($goodUser->fresh()->credit)->toBe('1.00')
        ->and($goodUser->fresh()->frozen_credit)->toBe('0.00');
    $this->assertDatabaseMissing('financial_transactions', ['reference_key' => 'unfreeze:'.$bad->id]);
    $this->assertDatabaseHas('financial_transactions', ['reference_key' => 'unfreeze:'.$good->id]);
    $this->assertDatabaseCount('financial_transactions', 1);
});

it('rejects a pure-wallet purchase before its intermediate invoice hold can overflow', function (): void {
    $user = User::factory()->create(['credit' => '10.00', 'frozen_credit' => '9999999999.99']);
    expect(fn () => app(CreateComboInvoiceAction::class)->execute($user, capacityRegressionProduct(), BillingCycle::ONETIME,
        [], true, input: ['request_id' => (string) Str::ulid()]))->toThrow(ValidationException::class);
    expect($user->fresh()->credit)->toBe('10.00')->and($user->fresh()->frozen_credit)->toBe('9999999999.99');
    foreach (['invoices', 'financial_transactions', 'instances', 'fulfillment_tasks', 'notification_events'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
});

it('blocks account closure while an independent clearance hold remains', function (): void {
    $user = User::factory()->create(['wallet_clearance_frozen' => '0.01', 'password' => 'Closure-Password-123'])->refresh();
    $eligibility = app(AccountClosureEligibility::class)->forUser($user);
    expect($eligibility['eligible'])->toBeFalse()->and($eligibility['reasons'])->toHaveKey('wallet_clearance_frozen');
    expect(fn () => app(CloseCustomerAccountAction::class)->execute($user, 'Closure-Password-123', true))
        ->toThrow(ValidationException::class);
    expect($user->fresh()->anonymized_at)->toBeNull()->and($user->fresh()->trashed())->toBeFalse()
        ->and($user->fresh()->wallet_clearance_frozen)->toBe('0.01');
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('audit_logs', 0);
});
