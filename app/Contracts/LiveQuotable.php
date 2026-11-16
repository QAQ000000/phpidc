<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\QuoteResult;
use App\Models\Instance;
use App\Models\Product;

/**
 * Prices that change in real time (upstream stock, FX, promo). A driver that
 * implements this is authoritative for pricing; otherwise LocalPriced applies.
 * One of the two is mandatory — resolve via capability check, never a string.
 */
interface LiveQuotable
{
    public function quotePurchase(Product $product, string $cycle, array $options, int $qty = 1): QuoteResult;

    public function quoteUpgrade(Instance $instance, array $targetOptions): QuoteResult;
}
