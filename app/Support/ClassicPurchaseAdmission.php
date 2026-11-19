<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\BillingCycle;
use App\Enums\DriverType;
use App\Models\Product;
use Illuminate\Validation\ValidationException;

class ClassicPurchaseAdmission
{
    /** New sales only: paid fulfillment and existing purchase replays retain their original contract. */
    public function reason(Product $product, string $cycle): ?string
    {
        if ($product->driver_type !== DriverType::ZJMF_API) {
            return null;
        }
        $publisher = $product->provisioning_rules['classic_catalog']['product_publisher_uid'] ?? null;
        if (! is_int($publisher) || $publisher < 0) {
            return '请先同步完整商品配置，再进行新购。';
        }
        if ($publisher > 0) {
            return '此商品需要绑定上游主机，当前暂不支持新购。';
        }
        if ($cycle === BillingCycle::ONTRIAL->value) {
            return '上游试用资格与共享次数尚无法完整核验，暂不支持新购试用周期。';
        }

        return null;
    }

    public function assertAllowed(Product $product, string $cycle): void
    {
        $reason = $this->reason($product, $cycle);
        if ($reason !== null) {
            throw ValidationException::withMessages([
                $cycle === BillingCycle::ONTRIAL->value ? 'billing_cycle' : 'product_id' => $reason,
            ]);
        }
    }
}
