<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Services\NotificationManager;
use App\Services\ReceivableCheckRequests;
use App\Services\ReceivableReconciliation;
use App\Services\ReceivableSnapshot;
use App\Services\ReceivableVerificationCache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunReceivableCheckJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 180;

    public function __construct(public readonly int $userId, public readonly string $action, public readonly string $requestId, public readonly string $intentHmac)
    {
        $this->onQueue('receivable-check');
    }

    public function uniqueId(): string
    {
        return app(ReceivableCheckRequests::class)->key($this->userId, $this->action, $this->requestId).':'.$this->intentHmac;
    }

    public function handle(ReceivableCheckRequests $requests, ReceivableSnapshot $snapshot): void
    {
        $state = $requests->get($this->userId, $this->action, $this->requestId, $this->intentHmac);
        if ($state === null || ! in_array($state['status'] ?? null, ['queued', 'running'], true)) {
            return;
        }
        $startedAt = now()->toImmutable();
        $requests->put($this->userId, $this->action, $this->requestId, $this->intentHmac,
            ['status' => 'running', 'started_at' => $startedAt->toIso8601String(), 'capture_ids' => $state['capture_ids'] ?? []]);
        $scan = $snapshot->read($this->userId, (int) config('receivables.background_seconds', 90), $state['capture_ids'] ?? []);
        app(ReceivableReconciliation::class)->flagIfUnchanged($scan);
        $user = User::withTrashed()->findOrFail($this->userId);
        $unchanged = $scan['revision'] === (string) $user->receivable_revision
            && $scan['reconciliation_required'] === (bool) $user->receivable_reconciliation_required;
        $requests->complete($this->userId, $this->action, $this->requestId, $this->intentHmac, $scan, $startedAt,
            ! $scan['valid'] && $user->receivable_reconciliation_required ? 'invalid' : ($unchanged ? 'ready' : 'stale'));
        if ($unchanged) {
            app(ReceivableVerificationCache::class)->put($user, $scan, $startedAt);
        }
    }

    public function failed(?Throwable $error): void
    {
        $context = ['user_id' => $this->userId, 'action' => $this->action, 'request_id' => $this->requestId,
            'exception_class' => $error === null ? null : $error::class];
        Log::error('Receivable history verification failed; original financial operation remains blocked.', $context);
        try {
            $requests = app(ReceivableCheckRequests::class);
            $state = $requests->get($this->userId, $this->action, $this->requestId, $this->intentHmac);
            if ($state !== null && ($state['status'] ?? null) !== 'ready') {
                $requests->put($this->userId, $this->action, $this->requestId, $this->intentHmac,
                    ['status' => 'failed', 'failed_at' => now()->toIso8601String(), 'capture_ids' => $state['capture_ids'] ?? [],
                        'reason' => '完整核验超时、失败或证据无法读取，请人工核对原请求。']);
            }
        } catch (Throwable $statusError) {
            Log::error('Receivable check failure state could not be saved.', [...$context, 'status_exception_class' => $statusError::class]);
        }
        try {
            app(NotificationManager::class)->queueOperations('affiliate.receivable.check-failed', ['user_id' => $this->userId, 'status' => 'failed'],
                'receivable-check:'.hash('sha256', $this->uniqueId()).':failed');
        } catch (Throwable $notificationError) {
            Log::error('Receivable check failure event could not be persisted.', [...$context, 'notification_exception_class' => $notificationError::class]);
        }
    }
}
