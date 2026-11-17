<?php

declare(strict_types=1);

use App\Actions\HandlePaymentCallbackAction;
use App\Data\VerifiedPaymentResult;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentCallbackOutcome;
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
