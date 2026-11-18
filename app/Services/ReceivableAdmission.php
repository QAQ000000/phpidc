<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ReceivableScanBudgetExceeded;
use App\Exceptions\ReceivableVersionConflict;
use App\Jobs\RunReceivableCheckJob;
use App\Models\User;
use Closure;
use Illuminate\Validation\ValidationException;

class ReceivableAdmission
{
    public function __construct(private readonly ReceivableCheckRequests $requests, private readonly ReceivableSnapshot $snapshot) {}

    /** The callback starts its own ordinary money transaction and checks its permit after locking users. */
    public function run(User $user, string $action, string $requestId, array $intent, Closure $operation, bool $requireClear = true, bool $allowFlag = false): mixed
    {
        return $this->runMany([$user], $action, $requestId, $intent,
            fn (array $permits): mixed => $operation($permits[$user->id]), $requireClear, $allowFlag);
    }

    /** Independent snapshots are rechecked together under the financial transaction's ordered user locks. */
    public function runMany(array $users, string $action, string $requestId, array $intent, Closure $operation, bool $requireClear = true, bool $allowFlag = false, array $captureIds = []): mixed
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $permits = [];
            foreach ($users as $user) {
                $permits[$user->id] = $this->prepare($user, $action, $requestId, $intent, $requireClear, $allowFlag, $captureIds[$user->id] ?? []);
            }
            try {
                return $operation($permits);
            } catch (ReceivableVersionConflict) {
                // The financial transaction has rolled back; recompute outside every user lock.
            }
        }
        throw ValidationException::withMessages(['operation' => '应收证据连续变化，本次操作待核对；请使用同一原请求稍后继续。']);
    }

    public function prepare(User $user, string $action, string $requestId, array $intent, bool $requireClear = true, bool $allowFlag = false, array $captureIds = [], ?int $foregroundSeconds = null): array
    {
        $captureIds = array_values(array_unique(array_map('intval', $captureIds)));
        sort($captureIds);
        $captureLimit = $action === 'receivable-view' ? 20 : 2;
        if (count($captureIds) > $captureLimit) {
            throw new \LogicException('Receivable root capture exceeds the limit for this action.');
        }
        $hmac = $this->requests->intent($user->id, $action, $requestId, ['intent' => $intent, 'capture_ids' => $captureIds]);
        $state = $this->requests->get($user->id, $action, $requestId, $hmac);
        if ($state !== null && in_array($state['status'] ?? null, ['queued', 'running', 'failed'], true)) {
            $this->pending($user->id, $action, $requestId, $state['status'] === 'failed');
        }
        $current = User::withTrashed()->findOrFail($user->id);
        if (! $allowFlag && $current->receivable_reconciliation_required) {
            throw ValidationException::withMessages(['operation' => '应收证据失效标志尚未解除，请由授权人员完整复核。']);
        }
        if ($state !== null && ($state['status'] ?? null) === 'invalid'
            && ($current->receivable_reconciliation_required || ($state['scan']['revision'] ?? null) === (string) $current->receivable_revision)) {
            throw ValidationException::withMessages(['operation' => '完整核验发现应收证据异常，请由授权人员复核原证据。']);
        }
        if ($state !== null && ($state['status'] ?? null) === 'ready' && is_array($state['scan'] ?? null)
            && $state['scan']['revision'] === (string) $current->receivable_revision
            && $state['scan']['reconciliation_required'] === (bool) $current->receivable_reconciliation_required) {
            return $this->permit($state['scan'], $requireClear, $allowFlag);
        }
        if ($state !== null && in_array($state['status'] ?? null, ['ready', 'stale', 'invalid'], true)) {
            return $this->queue($user->id, $action, $requestId, $hmac, $captureIds);
        }
        try {
            $scan = $this->snapshot->read($user->id, min((int) config('receivables.foreground_seconds', 2), $foregroundSeconds ?? (int) config('receivables.foreground_seconds', 2)), $captureIds);
        } catch (ReceivableScanBudgetExceeded) {
            return $this->queue($user->id, $action, $requestId, $hmac, $captureIds);
        }
        app(ReceivableReconciliation::class)->flagIfUnchanged($scan);

        return $this->permit($scan, $requireClear, $allowFlag);
    }

    public function assertLocked(User $user, array $permit, bool $allowFlag = false): void
    {
        if (($permit['user_id'] ?? null) !== $user->id || $permit['algorithm'] !== $this->requests->algorithm()
            || $permit['revision'] !== (string) $user->receivable_revision
            || $permit['reconciliation_required'] !== (bool) $user->receivable_reconciliation_required) {
            throw new ReceivableVersionConflict('Receivable evidence changed before the financial user lock.');
        }
        if (! $permit['valid'] || (! $allowFlag && $user->receivable_reconciliation_required)) {
            throw ValidationException::withMessages(['operation' => '应收证据待人工核对，当前操作未获准。']);
        }
    }

    private function permit(array $scan, bool $requireClear, bool $allowFlag): array
    {
        if (! $scan['valid'] || (! $allowFlag && $scan['reconciliation_required'])) {
            throw ValidationException::withMessages(['operation' => '应收证据不完整或不一致，请由授权人员完整复核。']);
        }
        if ($requireClear && $scan['has_outstanding']) {
            throw ValidationException::withMessages(['operation' => '存在未清偿退款应收，本次资金操作暂不可用。']);
        }

        return $scan;
    }

    private function queue(int $userId, string $action, string $requestId, string $hmac, array $captureIds): never
    {
        $this->requests->put($userId, $action, $requestId, $hmac, ['status' => 'queued', 'queued_at' => now()->toIso8601String(), 'capture_ids' => $captureIds]);
        RunReceivableCheckJob::dispatch($userId, $action, $requestId, $hmac)->onQueue('receivable-check');
        $this->pending($userId, $action, $requestId);
    }

    private function pending(int $userId, string $action, string $requestId, bool $failed = false): never
    {
        throw ValidationException::withMessages(['receivable_check' => $failed ? '长历史核对未完成，已记录失败，请等待运营核对后使用原请求继续。' : '应收历史正在独立队列完整核对，请保留原请求并在完成后继续。',
            'receivable_check_url' => $this->requests->statusUrl($userId, $action, $requestId)]);
    }
}
