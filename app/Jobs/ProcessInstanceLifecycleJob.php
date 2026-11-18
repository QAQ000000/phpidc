<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ProcessInstanceLifecycleAction;
use App\Models\Instance;
use App\Support\MachineOperation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ProcessInstanceLifecycleJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public int $uniqueFor = 3600;

    public array $backoff = [60, 300, 900];

    public string $bindingFingerprint;

    public function __construct(public int $instanceId, array $binding)
    {
        // A queued read needs identity comparison, never the token or account credentials themselves.
        $this->bindingFingerprint = hash('sha256', json_encode($binding, JSON_THROW_ON_ERROR));
    }

    public function uniqueId(): string
    {
        return (string) $this->instanceId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('host-sync:'.$this->instanceId))->shared()->releaseAfter(60)->expireAfter(210)];
    }

    public function handle(ProcessInstanceLifecycleAction $action): void
    {
        $instance = Instance::with('upstreamApi')->find($this->instanceId);
        if ($instance === null || ! $instance->status->isBillable() || hash('sha256', json_encode(app(MachineOperation::class)->binding($instance), JSON_THROW_ON_ERROR)) !== $this->bindingFingerprint) {
            return;
        }
        $action->process($instance);
    }
}
