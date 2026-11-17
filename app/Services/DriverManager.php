<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\FulfillmentDriver;
use App\Drivers\SelfHosted\RedemptionCodeDriver;
use App\Enums\DriverType;
use DomainException;

class DriverManager
{
    public function fulfillment(DriverType $type, ?int $upstreamApiId = null): FulfillmentDriver
    {
        return match ($type) {
            DriverType::REDEMPTION => app(RedemptionCodeDriver::class),
            default => throw new DomainException('This fulfillment driver is not available yet.'),
        };
    }
}
