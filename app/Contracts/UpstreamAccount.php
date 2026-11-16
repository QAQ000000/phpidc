<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Instance;

interface UpstreamAccount
{
    /** @return array{credit: string, currency: array<string, mixed>} */
    public function checkBalance(): array;

    public function registerDownstreamCallback(Instance $instance): bool;
}
