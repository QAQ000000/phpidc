<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ExecuteFulfillmentTaskAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ExecuteFulfillmentTaskJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(public int $taskId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('fulfillment-task:'.$this->taskId))->releaseAfter(60)->expireAfter(210)];
    }

    public function handle(ExecuteFulfillmentTaskAction $execute): void
    {
        $execute->execute($this->taskId);
    }
}
