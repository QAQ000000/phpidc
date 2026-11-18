<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ProbeClassicCatalogAction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProbeClassicProductJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public int $uniqueFor = 3600;

    public array $backoff = [60, 300, 900];

    public function __construct(public int $productId) {}

    public function uniqueId(): string
    {
        return (string) $this->productId;
    }

    public function handle(ProbeClassicCatalogAction $action): void
    {
        $action->execute([$this->productId]);
    }
}
