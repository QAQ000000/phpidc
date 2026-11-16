<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Instance;

interface LocalInventory
{
    /** @return array<string, mixed> Reserved resource descriptor written into the instance. */
    public function allocateResource(Instance $instance): array;

    /** Compensating release; MUST be safe to call twice (INV-C3). */
    public function releaseResource(Instance $instance): void;
}
