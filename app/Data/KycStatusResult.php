<?php

declare(strict_types=1);

namespace App\Data;

use InvalidArgumentException;

readonly class KycStatusResult
{
    public function __construct(
        public string $externalId,
        public string $status,
        public array $maskedSummary,
    ) {
        if ($externalId === '' || ! in_array($status, ['pending', 'verified', 'failed'], true)) {
            throw new InvalidArgumentException('Invalid KYC protocol result.');
        }
    }
}
