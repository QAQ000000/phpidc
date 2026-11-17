<?php

declare(strict_types=1);

namespace App\Services;

class UpstreamOperationBuffer
{
    private array $operations = [];

    public function append(array $operation): void
    {
        $this->operations[] = $operation;
    }

    public function drain(): array
    {
        $operations = $this->operations;
        $this->operations = [];

        return $operations;
    }
}
