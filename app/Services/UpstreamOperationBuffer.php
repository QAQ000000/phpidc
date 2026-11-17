<?php

declare(strict_types=1);

namespace App\Services;

use Closure;

class UpstreamOperationBuffer
{
    private array $operations = [];

    private ?Closure $writer = null;

    public function observe(?Closure $writer): ?Closure
    {
        $previous = $this->writer;
        $this->writer = $writer;

        return $previous;
    }

    public function append(array $operation): void
    {
        if ($this->writer !== null) {
            ($this->writer)($operation);
        } else {
            $this->operations[] = $operation;
        }
    }

    public function drain(): array
    {
        $operations = $this->operations;
        $this->operations = [];

        return $operations;
    }
}
