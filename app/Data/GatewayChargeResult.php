<?php

declare(strict_types=1);

namespace App\Data;

readonly class GatewayChargeResult
{
    public function __construct(
        public bool $success,
        public ?string $paymentUrl = null,
        public ?string $qrCodeUrl = null,
        public ?string $htmlForm = null,
        public ?string $errorMessage = null,
        public ?string $outTradeNo = null,
    ) {}
}
