<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Protocol-verified callback. Produced ONLY by a payment adapter; it carries no
 * model state and no DB access has happened yet (INV-L2, external-drivers-spec §1.4).
 *
 * `outTradeNo` is OUR merchant order number (epay `out_trade_no`) and is resolved
 * to an invoice via `payment_attempts`; `gatewayTradeNo` is the gateway's own id.
 */
readonly class VerifiedPaymentResult
{
    public function __construct(
        public string $gateway,
        public string $gatewayTradeNo,
        public string $outTradeNo,
        public string $paidAmount,
        public array $rawPayload = [],
        public string $ackBody = 'success',
    ) {}
}
