<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\QuoteResult;
use App\Models\Instance;
use App\Models\Product;

/**
 * Local price table is authoritative. Required for every driver that is not
 * LiveQuotable, so that a quote is always obtainable (INV-I1 step 1).
 */
interface LocalPriced
{
    public function calculateLocalPurchase(Product $product, string $cycle, array $options, int $qty = 1): QuoteResult;

    public function calculateLocalUpgrade(Instance $instance, array $targetOptions): QuoteResult;
}
