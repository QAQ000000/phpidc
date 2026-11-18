<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\MaintenanceBatchWork;
use App\Support\MaintenanceLockWait;
use App\Support\MaintenanceState;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use LogicException;
use Throwable;

class RunMaintenanceBatchJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 25;

    public int $uniqueFor = 180;

    public bool $failOnTimeout = true;

    public function __construct(public string $kind)
    {
        app(MaintenanceState::class)->assertKind($kind);
        if (! in_array($kind, config('maintenance.kinds'), true)) {
            throw new LogicException('Only a complete maintenance family may be queued.');
        }
        $this->onQueue('maintenance');
    }

    public function uniqueId(): string
    {
        return $this->kind;
    }

    public function uniqueVia(): Repository
    {
        return app(MaintenanceState::class)->store();
    }

    public function handle(MaintenanceBatchWork $work, MaintenanceState $state, MaintenanceLockWait $lockWait): void
    {
        $repository = Cache::store(config('maintenance.cache_store'));
        if (! $repository instanceof \Illuminate\Cache\Repository || ! ($provider = $repository->getStore()) instanceof LockProvider) {
            throw new LogicException('Maintenance requires a cache store with atomic locks.');
        }
        $lock = $provider->lock('maintenance:execution:'.$this->kind, 30);
        if (! $lock->get()) {
            return;
        }
        try {
            $state->heartbeat($this->kind, 'started');
            $summary = $lockWait->execute(fn (): array => $work->execute($this->kind));
            $state->heartbeat($this->kind, 'completed', $summary);
        } catch (Throwable $error) {
            $state->heartbeat($this->kind, 'failed', ['error_code' => 'batch_failed']);
            throw $error;
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $error): void
    {
        app(MaintenanceState::class)->heartbeat($this->kind, 'failed', ['error_code' => 'queue_job_failed']);
    }
}
