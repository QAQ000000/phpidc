<?php

declare(strict_types=1);

namespace App\Actions;

use App\Contracts\LocalPriced;
use App\Enums\BillingCycle;
use App\Enums\DriverType;
use App\Models\Product;
use App\Models\RedemptionCode;
use App\Services\DriverManager;
use App\Support\ClassicPurchaseAdmission;
use App\Support\Money;

class DescribeStoreProductAction
{
    public function execute(Product $product): array
    {
        $driver = app(DriverManager::class)->fulfillment($product->driver_type, $product->upstream_api_id);
        $local = $driver instanceof LocalPriced;
        $prices = $local ? $product->local_prices : $product->upstream_base_prices;
        $cycles = [];
        $unavailable = null;
        foreach ($prices ?? [] as $key => $price) {
            $cycle = BillingCycle::tryFrom($key);
            if (! ($price['enabled'] ?? false) || $cycle === null || ($local && $cycle !== BillingCycle::ONETIME)) {
                continue;
            }
            $reason = app(ClassicPurchaseAdmission::class)->reason($product, $key);
            if ($reason !== null) {
                $unavailable ??= $reason;

                continue;
            }
            $cached = $product->local_prices[$key] ?? null;
            $cycles[] = ['value' => $key, 'label' => $cycle->label(),
                'price' => $local ? Money::of($price['price']) : (isset($cached['price']) ? Money::of($cached['price']) : null),
                'setup_fee' => $local ? Money::of($price['setup_fee'] ?? '0.00') : null];
        }

        $stock = $local && $product->upstream_api_id === null
            ? RedemptionCode::where('product_id', $product->id)->where('status', 'available')->whereNull('instance_id')->count() : null;
        if ($product->driver_type === DriverType::ZJMF_API && $product->stock_control) {
            $stock = max(0, (int) $product->qty);
        }

        return ['cycles' => $cycles, 'live' => ! $local, 'stock' => $stock,
            'purchase_unavailable' => $cycles === [] ? $unavailable : null];
    }
}
