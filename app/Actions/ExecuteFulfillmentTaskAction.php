<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Jobs\ExecuteFulfillmentTaskJob;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Services\NotificationManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ExecuteFulfillmentTaskAction
{
    public function execute(int $taskId): void
    {
        $candidate = FulfillmentTask::findOrFail($taskId);
        if ($candidate->instance_id === null) {
            return;
        }
        $worker = (string) Str::uuid();
        $claimed = DB::transaction(function () use ($candidate, $worker): bool {
            $invoice = $candidate->invoice_id === null ? null : Invoice::whereKey($candidate->invoice_id)->lockForUpdate()->firstOrFail();
            $instance = Instance::whereKey($candidate->instance_id)->lockForUpdate()->firstOrFail();
            $task = FulfillmentTask::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if ($task->status !== FulfillmentTaskStatus::PENDING || ($task->available_at !== null && $task->available_at->isFuture())) {
                return false;
            }
            if ($task->instance_id !== $instance->id || $task->invoice_id !== $candidate->invoice_id
                || ($invoice !== null && ($invoice->status !== InvoiceStatus::PAID || $invoice->instance_id !== $instance->id))
                || $task->driver_type !== $instance->driver_type || $task->upstream_api_id !== $instance->upstream_api_id || $instance->status->isTerminal()) {
                $task->update(['status' => FulfillmentTaskStatus::STALE]);

                return false;
            }
            if ($task->attempts >= $task->max_attempts || ! in_array($task->action, ['provision', 'renew', 'upgrade', 'suspend', 'unsuspend', 'terminate', 'register_callback'], true)) {
                $task->update(['status' => FulfillmentTaskStatus::FAILED, 'error_message' => 'Attempts exhausted or unsupported task action.']);
                DB::afterCommit(fn () => Log::error('Fulfillment task requires attention.', ['task_id' => $task->id]));

                return false;
            }
            // C21 / INV-T2: the claim consumes one attempt even if this worker later crashes.
            $task->update(['status' => FulfillmentTaskStatus::RUNNING, 'attempts' => $task->attempts + 1,
                'locked_by' => $worker, 'locked_at' => now(), 'lease_expires_at' => now()->addSeconds(210)]);

            return true;
        });
        if (! $claimed) {
            return;
        }
        $result = $candidate->action === 'provision'
            ? app(ProvisionInstanceAction::class)->execute($candidate->instance()->firstOrFail(), $candidate->payload ?? [], $taskId, $worker)
            : app(ExecuteServiceTaskAction::class)->execute($taskId, $worker);
        DB::transaction(function () use ($candidate, $worker, $result): void {
            $instance = Instance::whereKey($candidate->instance_id)->lockForUpdate()->firstOrFail();
            $task = FulfillmentTask::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if ($task->status !== FulfillmentTaskStatus::RUNNING || $task->locked_by !== $worker || $task->lease_expires_at->isPast()) {
                return;
            }
            $changes = ['locked_by' => null, 'locked_at' => null, 'lease_expires_at' => null];
            if ($result->success) {
                if ($task->action === 'provision') {
                    app(NotificationManager::class)->queue($instance->user_id, 'instance.delivered', ['instance_id' => $instance->id, 'status' => $instance->status->value]);
                }
                $changes += ['status' => FulfillmentTaskStatus::SUCCESS, 'executed_at' => now(), 'summary' => $task->action.' completed.'];
            } else {
                $attempts = $task->attempts;
                $retry = $result->retryable && $attempts < $task->max_attempts;
                $available = now()->addSeconds([60, 300, 900][min(max($attempts - 1, 0), 2)]);
                $changes += ['status' => $retry ? FulfillmentTaskStatus::PENDING : FulfillmentTaskStatus::FAILED,
                    'available_at' => $available,
                    'error_message' => $result->errorMessage];
                if ($retry) {
                    DB::afterCommit(fn () => ExecuteFulfillmentTaskJob::dispatch($task->id)->delay($available));
                }
                if (! $retry) {
                    if ($instance->status === InstanceStatus::PENDING) {
                        $instance->update(['status' => InstanceStatus::PROVISION_FAILED]);
                        DB::table('audit_logs')->insert(['actor_type' => 'system', 'action' => 'instance.provision_failed',
                            'subject_type' => Instance::class, 'subject_id' => $instance->id,
                            'before' => json_encode(['status' => InstanceStatus::PENDING->value]), 'after' => json_encode(['status' => InstanceStatus::PROVISION_FAILED->value]),
                            'created_at' => now(), 'updated_at' => now()]);
                    }
                    Log::error('Fulfillment task failed.', ['task_id' => $task->id]);
                }
            }
            $task->update($changes);
        });
    }
}
