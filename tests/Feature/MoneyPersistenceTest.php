<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\PaymentCallbackOutcome;
use App\Models\Coupon;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\PaymentCallback;
use App\Models\User;
use App\Support\Money;

it('reloads monetary columns as exact two-place strings without losing enum types', function (string $amount): void {
    $credit = Money::percentOf($amount, '25');
    $gateway = Money::sub($amount, $credit);
    $user = User::factory()->create([
        'credit' => $amount,
        'frozen_credit' => $amount,
        'affiliate_balance' => $amount,
    ]);
    $instance = Instance::factory()->create([
        'user_id' => $user->id,
        'recurring_amount' => $amount,
    ]);
    $coupon = Coupon::create([
        'code' => 'ROUNDTRIP',
        'type' => 'fixed',
        'value' => $amount,
        'min_order_amount' => $amount,
    ]);
    $invoice = Invoice::factory()->paid()->create([
        'user_id' => $user->id,
        'instance_id' => $instance->id,
        'product_id' => $instance->product_id,
        'coupon_id' => $coupon->id,
        'total_amount' => $amount,
        'credit_applied' => $credit,
        'gateway_amount' => $gateway,
        'discount_amount' => $amount,
        'upstream_expected_cost' => $amount,
        'gateway' => 'epay_wechat',
    ]);
    $attempt = $invoice->attempts()->create([
        'gateway' => 'epay_wechat',
        'out_trade_no' => $invoice->invoice_no.'1',
        'amount' => $gateway,
    ]);
    $callback = PaymentCallback::factory()->create([
        'invoice_id' => $invoice->id,
        'amount' => $gateway,
        'outcome' => PaymentCallbackOutcome::PROCESSED_PAID,
    ]);

    $reloadedUser = $user->fresh();
    $reloadedInvoice = $invoice->fresh();

    expect($reloadedUser->credit)->toBe($amount)
        ->and($reloadedUser->frozen_credit)->toBe($amount)
        ->and($reloadedUser->affiliate_balance)->toBe($amount)
        ->and($instance->fresh()->recurring_amount)->toBe($amount)
        ->and($coupon->fresh()->value)->toBe($amount)
        ->and($coupon->fresh()->min_order_amount)->toBe($amount)
        ->and($reloadedInvoice->total_amount)->toBe($amount)
        ->and($reloadedInvoice->credit_applied)->toBe($credit)
        ->and($reloadedInvoice->gateway_amount)->toBe($gateway)
        ->and($reloadedInvoice->discount_amount)->toBe($amount)
        ->and($reloadedInvoice->upstream_expected_cost)->toBe($amount)
        ->and($attempt->fresh()->amount)->toBe($gateway)
        ->and($callback->fresh()->amount)->toBe($gateway)
        ->and($reloadedInvoice->status)->toBe(InvoiceStatus::PAID)
        ->and(PaymentCallback::whereKey($callback->id)->value('outcome'))->toBe(PaymentCallbackOutcome::PROCESSED_PAID);
})->with(['zero' => '0.00', 'whole units' => '100.00', 'fractional units' => '123.45'])->group('phase0');
