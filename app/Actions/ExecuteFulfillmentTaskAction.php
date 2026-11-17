<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Models\FulfillmentTask;
use App\Models\Instance;
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
            $instance = Instance::whereKey($candidate->instance_id)->lockForUpdate()->firstOrFail();
            $task = FulfillmentTask::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if ($task->status !== FulfillmentTaskStatus::PENDING || ($task->available_at !== null && $task->available_at->isFuture())) {
                return false;
            }
            if ($task->driver_type !== $instance->driver_type || $task->upstream_api_id !== $instance->upstream_api_id || $instance->status->isTerminal()) {
                $task->update(['status' => FulfillmentTaskStatus::STALE]);

                return false;
            }
            if ($task->attempts >= $task->max_attempts || $task->action !== 'provision') {
                $task->update(['status' => FulfillmentTaskStatus::FAILED, 'error_message' => 'Attempts exhausted or unsupported task action.']);
                Log::error('Fulfillment task requires attention.', ['task_id' => $task->id]);

                return false;
            }
            $task->update(['status' => FulfillmentTaskStatus::RUNNING, 'locked_by' => $worker, 'locked_at' => now(), 'lease_expires_at' => now()->addSeconds(210)]);

            return true;
        });
        if (! $claimed) {
            return;
        }
        $result = app(ProvisionInstanceAction::class)->execute($candidate->instance()->firstOrFail(), $candidate->payload ?? [], $taskId, $worker);
        DB::transaction(function () use ($candidate, $worker, $result): void {
            $instance = Instance::whereKey($candidate->instance_id)->lockForUpdate()->firstOrFail();
            $task = FulfillmentTask::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if ($task->status !== FulfillmentTaskStatus::RUNNING || $task->locked_by !== $worker || $task->lease_expires_at->isPast()) {
                return;
            }
            $changes = ['locked_by' => null, 'locked_at' => null, 'lease_expires_at' => null];
            if ($result->success) {
                $changes += ['status' => FulfillmentTaskStatus::SUCCESS, 'executed_at' => now(), 'summary' => 'Card delivered.'];
            } else {
                $attempts = $task->attempts + 1;
                $retry = $result->retryable && $attempts < $task->max_attempts;
                $changes += ['status' => $retry ? FulfillmentTaskStatus::PENDING : FulfillmentTaskStatus::FAILED,
                    'attempts' => $attempts, 'available_at' => now()->addSeconds([60, 300, 900][min($attempts - 1, 2)]),
                    'error_message' => $result->errorMessage];
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
