<?php

declare(strict_types=1);

namespace App\Contracts;

interface MaintenanceBatchWork
{
    /** Execute one bounded local batch; supplier work is dispatched to its existing queue. */
    public function execute(string $kind): array;
}
