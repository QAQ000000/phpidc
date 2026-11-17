<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ReconcileHostSyncAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ReconcileHostSyncJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(public int $instanceId, public ?int $upstreamApiId,
        public ?string $upstreamInstanceId, public string $token) {}

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('host-sync:'.$this->instanceId))->releaseAfter(60)->expireAfter(210)];
    }

    public function handle(ReconcileHostSyncAction $action): void
    {
        $action->execute($this->instanceId, $this->upstreamApiId, $this->upstreamInstanceId, $this->token);
    }
}
