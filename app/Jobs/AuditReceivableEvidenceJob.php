<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\NotificationManager;
use App\Services\ReceivableReconciliation;
use App\Services\ReceivableSnapshot;
use App\Support\MaintenanceLockWait;
use App\Support\MaintenanceState;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class AuditReceivableEvidenceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 86400;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $userId)
    {
        $this->onQueue('receivable-check');
    }

    public function uniqueId(): string
    {
        return 'audit:'.$this->userId;
    }

    public function uniqueVia(): Repository
    {
        return app(MaintenanceState::class)->store();
    }

    public function handle(ReceivableSnapshot $snapshot, ReceivableReconciliation $reconciliation): void
    {
        $scan = $snapshot->read($this->userId, 90);
        app(MaintenanceLockWait::class)->execute(fn () => $reconciliation->flagIfUnchanged($scan));
    }

    public function failed(?Throwable $error): void
    {
        Log::error('Independent receivable evidence audit failed; no incomplete result was applied.',
            ['user_id' => $this->userId, 'exception_class' => $error === null ? null : $error::class]);
        try {
            app(NotificationManager::class)->queueOperations('affiliate.receivable.check-failed',
                ['user_id' => $this->userId, 'status' => 'failed'], 'receivable-audit:'.$this->userId.':'.now()->format('YmdHi'));
        } catch (Throwable $notificationError) {
            Log::error('Independent receivable audit failure notification could not be persisted.',
                ['user_id' => $this->userId, 'exception_class' => $notificationError::class]);
        }
    }
}
