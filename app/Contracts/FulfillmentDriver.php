<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\InstanceInfoResult;
use App\Data\ProvisionResult;
use App\Models\Instance;
use App\Models\Product;

/**
 * Base contract every provisioning driver must implement.
 * Drivers are pure: they return DTOs and mutate no models (INV-L4).
 */
interface FulfillmentDriver
{
    /** @return array<string, mixed> Normalized/validated parameter bag. */
    public function validateParams(Product $product, array $input): array;

    public function provision(Instance $instance, array $params): ProvisionResult;

    public function syncInfo(Instance $instance): InstanceInfoResult;

    /** @return list<string> */
    public function supportedCommands(Instance $instance): array;
}
