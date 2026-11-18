<?php

declare(strict_types=1);

namespace App\Drivers\SelfHosted;

use App\Contracts\FulfillmentDriver;
use App\Contracts\LocalPriced;
use App\Contracts\OneTimeDeliverable;
use App\Data\InstanceInfoResult;
use App\Data\ProvisionResult;
use App\Data\QuoteResult;
use App\Enums\BillingCycle;
use App\Enums\InstanceStatus;
use App\Models\Instance;
use App\Models\Product;
use App\Models\RedemptionCode;
use App\Support\Money;
use App\Support\ProvisioningValidator;
use Illuminate\Validation\ValidationException;

class RedemptionCodeDriver implements FulfillmentDriver, LocalPriced, OneTimeDeliverable
{
    public function deliveryEvidence(Instance $instance): array
    {
        $code = RedemptionCode::where('instance_id', $instance->id)->first();
        $credentials = $instance->encrypted_credentials;
        if ($instance->billing_cycle !== BillingCycle::ONETIME || $instance->status !== InstanceStatus::ACTIVE
            || $instance->next_due_at !== null || $code === null || $code->product_id !== $instance->product_id
            || $code->status !== 'sold' || $code->sold_at === null || $code->reserved_until !== null
            || ! is_array($credentials) || ! is_string($credentials['card_key'] ?? null) || trim($credentials['card_key']) === ''
            || ! hash_equals($code->code, $credentials['card_key'])
            || ! hash_equals($code->code_hash, hash_hmac('sha256', $credentials['card_key'], config('app.key')))) {
            throw ValidationException::withMessages(['operation' => '卡密尚未完整交付，或当前已售卡与交付凭据不一致，请先核对。']);
        }

        return ['kind' => 'fixed_code', 'card_id' => $code->id, 'sold_at' => $code->sold_at->copy()->utc()->format('Y-m-d\TH:i:s\Z')];
    }

    public function validateParams(Product $product, array $input): array
    {
        return app(ProvisioningValidator::class)->validate($product, $input);
    }

    public function calculateLocalPurchase(Product $product, string $cycle, array $options, int $qty = 1): QuoteResult
    {
        $price = $product->local_prices[$cycle] ?? null;
        if ($cycle !== BillingCycle::ONETIME->value || ! ($price['enabled'] ?? false) || $options !== [] || $qty !== 1) {
            throw ValidationException::withMessages(['billing_cycle' => 'Card keys require an enabled one-time price, no options, and quantity one.']);
        }
        $period = Money::of($price['price']);
        $setup = Money::of($price['setup_fee'] ?? '0.00');

        return new QuoteResult(upstreamBaseCost: Money::zero(), downstreamPrice: $period, setupFee: $setup, currency: $product->currency);
    }

    public function calculateLocalUpgrade(Instance $instance, array $targetOptions): QuoteResult
    {
        throw ValidationException::withMessages(['options' => 'Card keys cannot be upgraded.']);
    }

    public function provision(Instance $instance, array $params): ProvisionResult
    {
        $code = RedemptionCode::where('instance_id', $instance->id)->where('product_id', $instance->product_id)->first();
        if ($code === null || ! in_array($code->status, ['reserved', 'sold'], true)) {
            return ProvisionResult::fail('No card is bound to this instance.', retryable: true);
        }

        return ProvisionResult::ok(credentials: ['card_key' => $code->code]);
    }

    public function syncInfo(Instance $instance): InstanceInfoResult
    {
        return new InstanceInfoResult(upstreamStatus: $instance->status->value);
    }

    public function supportedCommands(Instance $instance): array
    {
        return [];
    }
}
