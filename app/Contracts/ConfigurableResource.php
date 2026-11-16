<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\ProvisionResult;
use App\Models\Instance;

interface ConfigurableResource
{
    /** @param array<string, mixed> $targetOptions */
    public function changeConfig(Instance $instance, array $targetOptions): ProvisionResult;
}
