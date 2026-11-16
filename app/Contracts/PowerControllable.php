<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\ProvisionResult;
use App\Enums\ProvisionCommand;
use App\Models\Instance;

interface PowerControllable
{
    /** @param array<string, mixed> $payload */
    public function executePowerCommand(Instance $instance, ProvisionCommand $command, array $payload = []): ProvisionResult;
}
