<?php

declare(strict_types=1);

use App\Actions\ConfigureEpayAction;
use App\Actions\CreateComboInvoiceAction;
use App\Actions\ExpireUnpaidInvoicesAction;
use App\Actions\HandlePaymentCallbackAction;
use App\Actions\InitiatePaymentAction;
use App\Actions\RefundInvoiceAction;
use App\Data\VerifiedPaymentResult;
use App\Drivers\Payment\EpayGateway;
use App\Enums\BillingCycle;
use App\Enums\DriverType;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentCallbackOutcome;
use App\Models\AffiliateCommission;
use App\Models\FinancialTransaction;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\PaymentCallback;
use App\Models\Product;
use App\Models\User;
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

function foreignInvoiceFixture(InvoiceStatus $status = InvoiceStatus::UNPAID, string $currency = 'USD'): array
{
    $user = User::factory()->create(['credit' => '17.00', 'frozen_credit' => '4.00']);
    $invoice = Invoice::factory()->create(['user_id' => $user->id, 'currency' => $currency, 'status' => $status,
        'gateway' => 'epay_wechat', 'total_amount' => '10.00', 'credit_applied' => '4.00', 'gateway_amount' => '6.00']);
    $attempt = $invoice->attempts()->create(['gateway' => 'epay_wechat', 'out_trade_no' => 'PAY'.Str::ulid(), 'amount' => '6.00', 'status' => 'created']);

    return [$user, $invoice, $attempt];
}

function currencyCallbackPayload(PaymentAttempt $attempt, string $trade = 'FOREIGN-CURRENCY-TRADE'): array
{
    // The request's currency is deliberately misleading; the locked invoice is authoritative.
    $payload = ['pid' => 'merchant', 'type' => 'wxpay', 'out_trade_no' => $attempt->out_trade_no, 'trade_no' => $trade,
        'money' => '6.00', 'trade_status' => 'TRADE_SUCCESS', 'currency' => 'CNY'];
    ksort($payload, SORT_STRING);
    $pairs = [];
    foreach ($payload as $key => $value) {
        $pairs[] = $key.'='.$value;
    }
    $payload['sign'] = md5(implode('&', $pairs).'isolated-currency-key');
    $payload['sign_type'] = 'MD5';

    return $payload;
}

it('rejects foreign purchases before any wallet invoice or outbox mutation including free orders', function (string $currency, string $payment): void {
    $price = $payment === 'zero' ? '0.00' : '10.00';
    $wallet = match ($payment) {
        'wallet' => '50.00', 'combo' => '4.00', default => '0.00'
    };
    $user = User::factory()->create(['credit' => $wallet, 'frozen_credit' => '0.00']);
    $product = Product::factory()->create(['currency' => $currency,
        'local_prices' => ['onetime' => ['price' => $price, 'setup_fee' => '0.00', 'enabled' => true]]]);

    expect(fn () => app(CreateComboInvoiceAction::class)->execute($user, $product, BillingCycle::ONETIME, [], $payment !== 'gateway', 'epay_wechat', input: ['request_id' => (string) Str::ulid()]))
        ->toThrow(ValidationException::class);
    expect($user->fresh()->credit)->toBe($wallet)->and($user->fresh()->frozen_credit)->toBe('0.00');
    foreach (['invoices', 'financial_transactions', 'instances', 'fulfillment_tasks', 'payment_attempts', 'coupon_usages'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    Http::assertNothingSent();
    Queue::assertNothingPushed();
})->with(['USD', 'EUR'])->with(['wallet', 'combo', 'gateway', 'zero']);

it('rejects foreign renewal upgrade and repeated service requests before any upstream call', function (string $operation, bool $wallet): void {
    $instance = Instance::factory()->create(['currency' => 'USD', 'status' => InstanceStatus::ACTIVE, 'driver_type' => DriverType::ZJMF_API]);
    $request = (string) Str::ulid();
    if ($operation === 'repeat') {
        Invoice::factory()->create(['user_id' => $instance->user_id, 'instance_id' => $instance->id, 'currency' => 'USD',
            'type' => InvoiceType::RENEW, 'line_items' => ['request_id' => $request]]);
    }
    $invoiceCount = Invoice::count();
    $before = $instance->user->only(['credit', 'frozen_credit']);
    $action = app(CreateComboInvoiceAction::class);
    expect(fn () => $operation === 'upgrade'
        ? $action->upgrade($instance->user, $instance, [], $request, $wallet)
        : $action->renewal($instance->user, $instance, $wallet, requestId: $request))->toThrow(ValidationException::class);
    expect($instance->user->fresh()->only(['credit', 'frozen_credit']))->toBe($before)
        ->and(Invoice::count())->toBe($invoiceCount)->and(FinancialTransaction::count())->toBe(0)->and(FulfillmentTask::count())->toBe(0);
    Http::assertNothingSent();
})->with(['renew', 'upgrade', 'repeat'])->with([false, true]);

it('checks the locked currency before creating a payment attempt even with a stale CNY model', function (bool $stale): void {
    app(ConfigureEpayAction::class)->execute('https://epay.test', 'merchant', 'isolated-currency-key', 'Isolated currency test');
    $user = User::factory()->create(['credit' => '17.00', 'frozen_credit' => '4.00']);
    $invoice = Invoice::factory()->create(['user_id' => $user->id, 'currency' => 'USD', 'gateway' => 'epay_wechat']);
    if ($stale) {
        $invoice->currency = 'CNY';
    }
    expect(fn () => app(InitiatePaymentAction::class)->execute($invoice, $user))->toThrow(ValidationException::class);
    expect(PaymentAttempt::count())->toBe(0)->and(FinancialTransaction::count())->toBe(0)
        ->and($user->fresh()->credit)->toBe('17.00')->and($user->fresh()->frozen_credit)->toBe('4.00');
    Http::assertNothingSent();
})->with([false, true]);

it('makes the Epay adapter independently reject foreign invoices without sending a request', function (): void {
    [, $invoice] = foreignInvoiceFixture();
    $result = (new EpayGateway('epay_wechat', 'wxpay', 'https://epay.test', 'merchant', 'isolated-currency-key'))->charge($invoice, '6.00', 'PAY-FOREIGN');
    expect($result->success)->toBeFalse()->and($result->outTradeNo)->toBe('PAY-FOREIGN')->and($result->errorMessage)->toContain('CNY');
    Http::assertNothingSent();
});

it('records and deduplicates foreign callbacks before every terminal state and amount branch', function (InvoiceStatus $status, string $amount): void {
    [$user, $invoice, $attempt] = foreignInvoiceFixture($status);
    $payment = new VerifiedPaymentResult('epay_wechat', 'FOREIGN-TRADE', $attempt->out_trade_no, $amount, ['verified' => true]);
    $action = app(HandlePaymentCallbackAction::class);
    expect($action->execute($payment))->toBe('success')->and($action->execute($payment))->toBe('success');
    $callback = PaymentCallback::sole();
    expect($callback->outcome)->toBe(PaymentCallbackOutcome::REJECTED_UNSUPPORTED_CURRENCY)
        ->and($callback->invoice_id)->toBe($invoice->id)->and($callback->amount)->toBe($amount)
        ->and($callback->raw_payload)->toBe(['verified' => true])
        ->and($invoice->fresh()->status)->toBe($status)->and($attempt->fresh()->status)->toBe('created')
        ->and($user->fresh()->credit)->toBe('17.00')->and($user->fresh()->frozen_credit)->toBe('4.00');
    foreach (['financial_transactions', 'instances', 'fulfillment_tasks'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    Queue::assertNothingPushed();
})->with([InvoiceStatus::UNPAID, InvoiceStatus::PAID, InvoiceStatus::EXPIRED, InvoiceStatus::CANCELLED, InvoiceStatus::REFUNDED])->with(['5.00', '6.00', '8.00']);

it('verifies signed foreign callbacks at the HTTP boundary and does not trust their currency field', function (): void {
    app(ConfigureEpayAction::class)->execute('https://epay.test', 'merchant', 'isolated-currency-key', 'Isolated currency test');
    [$user, $invoice, $attempt] = foreignInvoiceFixture();
    $payload = currencyCallbackPayload($attempt);
    $invalid = $payload;
    $invalid['sign'] = str_repeat('0', 32);
    $this->postJson(route('payments.notify', ['gateway' => 'epay_wechat']), $invalid)->assertUnprocessable();
    $this->assertDatabaseCount('payment_callbacks', 0);
    $this->post(route('payments.notify', ['gateway' => 'epay_wechat']), $payload)->assertOk()->assertContent('success');
    $this->post(route('payments.notify', ['gateway' => 'epay_wechat']), $payload)->assertOk()->assertContent('success');
    expect(PaymentCallback::sole()->outcome)->toBe(PaymentCallbackOutcome::REJECTED_UNSUPPORTED_CURRENCY)
        ->and(PaymentCallback::sole()->raw_payload['currency'])->toBe('CNY')
        ->and($invoice->fresh()->currency)->toBe('USD')->and($invoice->fresh()->status)->toBe(InvoiceStatus::UNPAID)
        ->and($user->fresh()->credit)->toBe('17.00')->and(FinancialTransaction::count())->toBe(0);
});

it('keeps gateway mismatch precedence and rejects unsupported currency as a nonaccepted alert', function (): void {
    [, $invoice, $attempt] = foreignInvoiceFixture();
    $invoice->update(['gateway' => 'epay_alipay']);
    app(HandlePaymentCallbackAction::class)->execute(new VerifiedPaymentResult('epay_wechat', 'WRONG-CHANNEL', $attempt->out_trade_no, '6.00'));
    expect(PaymentCallback::sole()->outcome)->toBe(PaymentCallbackOutcome::REJECTED_GATEWAY_MISMATCH)
        ->and(PaymentCallbackOutcome::REJECTED_UNSUPPORTED_CURRENCY->isAccepted())->toBeFalse()
        ->and(PaymentCallbackOutcome::REJECTED_UNSUPPORTED_CURRENCY->requiresAlert())->toBeTrue();
});

it('retains foreign frozen funds for manual review while continuing to expire CNY invoices', function (): void {
    [$foreignUser, $foreign, $attempt] = foreignInvoiceFixture();
    $foreign->update(['expires_at' => now()->subMinute()]);
    $cnyUser = User::factory()->create(['credit' => '0.00', 'frozen_credit' => '3.00']);
    $cny = Invoice::factory()->create(['user_id' => $cnyUser->id, 'currency' => 'CNY', 'credit_applied' => '3.00',
        'gateway_amount' => '7.00', 'expires_at' => now()->subMinute()]);
    app(ExpireUnpaidInvoicesAction::class)->execute();
    app(ExpireUnpaidInvoicesAction::class)->execute();
    expect($foreign->fresh()->status)->toBe(InvoiceStatus::UNPAID)->and($attempt->fresh()->status)->toBe('created')
        ->and($foreignUser->fresh()->credit)->toBe('17.00')->and($foreignUser->fresh()->frozen_credit)->toBe('4.00')
        ->and($cny->fresh()->status)->toBe(InvoiceStatus::EXPIRED)->and($cnyUser->fresh()->credit)->toBe('3.00')
        ->and($cnyUser->fresh()->frozen_credit)->toBe('0.00')->and(FinancialTransaction::sole()->reference_key)->toBe('unfreeze:'.$cny->id);
});

it('refuses foreign refunds before touching buyer funds service or affiliate clawbacks', function (string $mode, string $commissionStatus): void {
    [$user, $invoice] = foreignInvoiceFixture(InvoiceStatus::PAID);
    $instance = Instance::factory()->create(['user_id' => $user->id, 'status' => InstanceStatus::CANCELLED]);
    $invoice->update(['instance_id' => $instance->id]);
    $referrer = User::factory()->create(['affiliate_balance' => '8.00']);
    $commission = AffiliateCommission::factory()->create(['invoice_id' => $invoice->id, 'buyer_id' => $user->id,
        'referrer_id' => $referrer->id, 'amount' => '10.00', 'status' => $commissionStatus]);
    $actor = User::factory()->create();
    Gate::define('refund-invoice', fn (User $candidate, Invoice $subject): bool => $candidate->is($actor) && $subject->is($invoice));
    expect(fn () => app(RefundInvoiceAction::class)->execute($invoice, $mode, reason: 'Review legacy currency', actor: $actor))->toThrow(DomainException::class);
    expect($user->fresh()->credit)->toBe('17.00')->and($user->fresh()->frozen_credit)->toBe('4.00')
        ->and($referrer->fresh()->affiliate_balance)->toBe('8.00')->and($commission->fresh()->status)->toBe($commissionStatus)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID)->and($instance->fresh()->status)->toBe(InstanceStatus::CANCELLED)
        ->and(FinancialTransaction::count())->toBe(0);
    Http::assertNothingSent();
})->with(['refund_wallet', 'external_record'])->with(['pending', 'available']);
