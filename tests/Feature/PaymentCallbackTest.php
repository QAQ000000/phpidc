<?php

declare(strict_types=1);

use App\Actions\HandlePaymentCallbackAction;
use App\Data\VerifiedPaymentResult;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentCallbackOutcome;
use App\Models\FulfillmentTask;
use App\Models\Invoice;
use App\Models\PaymentCallback;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('credits a late payment on an expired invoice without provisioning', function (): void {
    Queue::fake();
    $user = User::factory()->create(['credit' => '0.00']);
    $invoice = Invoice::factory()->create([
        'user_id' => $user->id,
        'gateway' => 'epay_wechat',
        'total_amount' => '10.00',
        'gateway_amount' => '10.00',
        'status' => InvoiceStatus::EXPIRED->value,
    ]);
    $invoice->attempts()->create([
        'gateway' => 'epay_wechat',
        'out_trade_no' => "{$invoice->invoice_no}1",
        'amount' => '10.00',
        'status' => 'expired',
    ]);

    app(HandlePaymentCallbackAction::class)->execute(new VerifiedPaymentResult(
        gateway: 'epay_wechat',
        gatewayTradeNo: 'TRADE_LATE_1',
        outTradeNo: "{$invoice->invoice_no}1",
        paidAmount: '10.00',
    ));

    expect($user->fresh()->credit)->toBe('10.00')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::EXPIRED)
        ->and(PaymentCallback::where('gateway_trade_no', 'TRADE_LATE_1')->value('outcome'))
        ->toBe(PaymentCallbackOutcome::PROCESSED_LATE_CREDIT);

    Queue::assertNothingPushed();
});

it('ignores a duplicate callback and records exactly one callback row', function (): void {
    Queue::fake();
    $user = User::factory()->create(['credit' => '0.00']);
    $invoice = Invoice::factory()->create([
        'user_id' => $user->id,
        'gateway' => 'epay_wechat',
        'total_amount' => '10.00',
        'gateway_amount' => '10.00',
        'status' => InvoiceStatus::EXPIRED->value,
    ]);
    $invoice->attempts()->create([
        'gateway' => 'epay_wechat',
        'out_trade_no' => "{$invoice->invoice_no}1",
        'amount' => '10.00',
        'status' => 'expired',
    ]);

    $verified = new VerifiedPaymentResult(
        gateway: 'epay_wechat',
        gatewayTradeNo: 'TRADE_DUP_1',
        outTradeNo: "{$invoice->invoice_no}1",
        paidAmount: '10.00',
    );

    $handler = app(HandlePaymentCallbackAction::class);
    $handler->execute($verified);
    $handler->execute($verified);

    expect($user->fresh()->credit)->toBe('10.00')
        ->and(PaymentCallback::where('gateway_trade_no', 'TRADE_DUP_1')->count())->toBe(1);
});

it('credits the excess on overpayment and never provisions twice', function (): void {
    $user = User::factory()->create(['credit' => '0.00']);
    $invoice = Invoice::factory()->create([
        'user_id' => $user->id,
        'gateway' => 'epay_wechat',
        'total_amount' => '10.00',
        'credit_applied' => '0.00',
        'gateway_amount' => '10.00',
        'status' => InvoiceStatus::UNPAID->value,
    ]);
    $invoice->attempts()->create([
        'gateway' => 'epay_wechat',
        'out_trade_no' => "{$invoice->invoice_no}1",
        'amount' => '10.00',
        'status' => 'created',
    ]);

    app(HandlePaymentCallbackAction::class)->execute(new VerifiedPaymentResult(
        gateway: 'epay_wechat',
        gatewayTradeNo: 'TRADE_OVER_1',
        outTradeNo: "{$invoice->invoice_no}1",
        paidAmount: '12.00',
    ));

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and($user->fresh()->credit)->toBe('2.00')
        ->and(FulfillmentTask::where('invoice_id', $invoice->id)->count())->toBe(1)
        ->and(PaymentCallback::where('gateway_trade_no', 'TRADE_OVER_1')->value('outcome'))
        ->toBe(PaymentCallbackOutcome::PROCESSED_OVERPAYMENT);
});

it('rejects a short payment without marking the invoice paid', function (): void {
    $user = User::factory()->create(['credit' => '0.00']);
    $invoice = Invoice::factory()->create([
        'user_id' => $user->id,
        'gateway' => 'epay_wechat',
        'total_amount' => '10.00',
        'gateway_amount' => '10.00',
        'status' => InvoiceStatus::UNPAID->value,
    ]);
    $invoice->attempts()->create([
        'gateway' => 'epay_wechat',
        'out_trade_no' => "{$invoice->invoice_no}1",
        'amount' => '10.00',
        'status' => 'created',
    ]);

    app(HandlePaymentCallbackAction::class)->execute(new VerifiedPaymentResult(
        gateway: 'epay_wechat',
        gatewayTradeNo: 'TRADE_SHORT_1',
        outTradeNo: "{$invoice->invoice_no}1",
        paidAmount: '7.00',
    ));

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::UNPAID)
        ->and(FulfillmentTask::where('invoice_id', $invoice->id)->count())->toBe(0)
        ->and(PaymentCallback::where('gateway_trade_no', 'TRADE_SHORT_1')->value('outcome'))
        ->toBe(PaymentCallbackOutcome::REJECTED_SHORT_AMOUNT);
});

it('records an unknown invoice callback instead of failing the webhook', function (): void {
    app(HandlePaymentCallbackAction::class)->execute(new VerifiedPaymentResult(
        gateway: 'epay_wechat',
        gatewayTradeNo: 'TRADE_UNKNOWN_1',
        outTradeNo: 'NO-SUCH-ORDER',
        paidAmount: '10.00',
    ));

    expect(PaymentCallback::where('gateway_trade_no', 'TRADE_UNKNOWN_1')->value('outcome'))
        ->toBe(PaymentCallbackOutcome::UNKNOWN_INVOICE);
});
