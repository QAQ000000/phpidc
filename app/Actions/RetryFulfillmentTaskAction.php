<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Jobs\ExecuteFulfillmentTaskJob;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use DomainException;
use Illuminate\Support\Facades\DB;

class RetryFulfillmentTaskAction
{
    /** Trusted system-operator entry; no public HTTP route grants this authority. */
    public function execute(FulfillmentTask $task, string $reason): void
    {
        if (trim($reason) === '') {
            throw new DomainException('An operator reason is required.');
        }
        DB::transaction(function () use ($task, $reason): void {
            $instance = Instance::whereKey($task->instance_id)->lockForUpdate()->firstOrFail();
            $locked = FulfillmentTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== FulfillmentTaskStatus::FAILED || $instance->status->isTerminal()
                || $instance->driver_type !== $locked->driver_type || $instance->upstream_api_id !== $locked->upstream_api_id) {
                throw new DomainException('Only a failed task on the current live binding can be retried.');
            }
            $before = $instance->status->value;
            if ($instance->status === InstanceStatus::PROVISION_FAILED) {
                $instance->update(['status' => InstanceStatus::PENDING]);
            }
            $locked->update(['status' => FulfillmentTaskStatus::PENDING, 'attempts' => 0, 'available_at' => now(),
                'locked_by' => null, 'locked_at' => null, 'lease_expires_at' => null, 'error_message' => null]);
            DB::table('audit_logs')->insert(['actor_type' => 'system', 'action' => 'fulfillment.retry', 'subject_type' => Instance::class,
                'subject_id' => $instance->id, 'reason' => $reason, 'before' => json_encode(['status' => $before]),
                'after' => json_encode(['status' => $instance->status->value, 'task_id' => $task->id]), 'created_at' => now(), 'updated_at' => now()]);
            DB::afterCommit(fn () => ExecuteFulfillmentTaskJob::dispatch($locked->id));
        });
    }
}
