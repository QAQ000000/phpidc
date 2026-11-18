<?php

declare(strict_types=1);

namespace App\Support;

use Closure;

class MaintenanceBudget
{
    private readonly Closure $clock;

    private readonly float $deadline;

    private readonly int $limit;

    private int $used = 0;

    public function __construct(?Closure $clock = null, ?int $limit = null)
    {
        $this->clock = $clock ?? fn (): float => hrtime(true) / 1_000_000_000;
        $this->deadline = ($this->clock)() + min(5.0, max(0.01, (float) config('maintenance.budget_seconds', 5)));
        $this->limit = min(100, max(1, $limit ?? (int) config('maintenance.limit', 100)));
    }

    public function remaining(): int
    {
        return max(0, $this->limit - $this->used);
    }

    public function available(): bool
    {
        return $this->remaining() > 0 && ($this->clock)() < $this->deadline;
    }

    public function consume(int $units): void
    {
        $this->used += max(1, $units);
    }

    public function used(): int
    {
        return $this->used;
    }
}
