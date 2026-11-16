<?php

declare(strict_types=1);

namespace App\Data;

readonly class KycInitiateResult
{
    public function __construct(
        public string $externalId,
        public ?string $redirectUrl = null,
        public ?string $qrCodeContent = null,
        public string $status = 'pending',
    ) {}
}
