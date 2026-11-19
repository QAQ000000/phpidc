<?php

declare(strict_types=1);

namespace App\Actions;

use App\Contracts\RenewableResource;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\User;
use App\Services\DriverManager;
use App\Services\SettingService;
use App\Support\Money;
use App\Support\PaymentChannels;
use App\Support\RenewalRecovery;
use App\Support\SettlementCurrency;
use Illuminate\Support\Facades\Crypt;

final class QuoteRenewalRecoveryAction
{
    public function execute(User $user, Invoice $parent, bool $useCredit, ?string $gateway, ?string $coupon): array
    {
        abort_unless($parent->user_id === $user->id, 403);
        $parent = $parent->fresh();
        abort_unless($parent->user_id === $user->id, 403);
        $recovery = app(RenewalRecovery::class);
        $instance = $parent->instance()->firstOrFail();
        $root = $recovery->eligible($parent, $instance);
        $driver = app(DriverManager::class)->fulfillment($instance->driver_type, $instance->upstream_api_id);
        if (! $driver instanceof RenewableResource) {
            $recovery->reject('This service does not support renewal.');
        }
        $snapshot = app(CallUpstreamAction::class)->execute(fn () => $driver->syncInfo($instance));
        if (! app(ApplyInstanceSnapshotAction::class)->execute($instance, $snapshot)) {
            $recovery->reject('Service changed during recovery quotation.');
        }
        $instance = $instance->refresh();
        $recovery->eligible($parent, $instance);
        $quote = app(CallUpstreamAction::class)->execute(fn () => $driver->quoteRenewal($instance, $instance->billing_cycle->value));
        SettlementCurrency::assertSupported($quote->currency);
        if ($quote->currency !== $instance->currency) {
            $recovery->reject('Renewal quote currency mismatch.');
        }
        $subtotal = Money::add($quote->downstreamPrice, $quote->setupFee);
        $discount = app(ApplyCouponAction::class)->preview($user, $instance->product, InvoiceType::RENEW, $subtotal, $quote->currency, $coupon)['discount'];
        $total = Money::sub($subtotal, $discount);
        $credit = $useCredit ? Money::min($user->fresh()->credit, $total) : Money::zero();
        $gatewayAmount = Money::sub($total, $credit);
        if (! Money::isZero($gatewayAmount) && ! in_array($gateway, ['epay_wechat', 'epay_alipay'], true)) {
            $recovery->reject('Select a supported payment gateway.', 'gateway');
        }
        if (! Money::isZero($gatewayAmount)) {
            app(PaymentChannels::class)->assertEnabled($gateway);
        }
        $data = ['version' => 1, 'user_id' => $user->id, 'parent_id' => $parent->id, 'root_id' => $root->id,
            'payment_window_minutes' => max(1, app(SettingService::class)->getInt('billing.invoice_expiry_minutes', 30)),
            'fingerprint' => $recovery->fingerprint($instance), 'expires' => now()->addMinutes(max(1, app(SettingService::class)->getInt('billing.renewal_recovery_quote_minutes', 5)))->getTimestamp(),
            'use_credit' => $useCredit, 'gateway' => $gateway, 'coupon_code' => $coupon,
            'upstream' => $quote->upstreamBaseCost, 'period' => $quote->downstreamPrice, 'setup' => $quote->setupFee, 'currency' => $quote->currency,
            'subtotal' => $subtotal, 'discount' => $discount, 'total' => $total, 'credit' => $credit, 'gateway_amount' => $gatewayAmount];

        return $data + ['quote_token' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR))];
    }
}
