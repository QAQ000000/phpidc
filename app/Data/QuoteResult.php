<?php

declare(strict_types=1);

namespace App\Data;

/**
 * A priced quote. `upstreamBaseCost` is what we expect to pay upstream;
 * `downstreamPrice` is what the customer pays (INV-M1, INV-I1).
 */
readonly class QuoteResult
{
    public function __construct(
        public string $upstreamBaseCost,
        public string $downstreamPrice,
        public string $setupFee = '0.00',
        public string $currency = 'CNY',
        public array $breakdown = [],
    ) {}
}
