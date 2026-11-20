<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Jobs\ExecuteFulfillmentTaskJob;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\User;
use App\Support\AdminAuditContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RetryFulfillmentTaskAction
{
    /** A null actor is reserved for trusted CLI callers; web actors require the matching operational authority. */
    public function execute(FulfillmentTask $task, string $reason, ?User $actor = null): void
    {
        $candidate = FulfillmentTask::findOrFail($task->id);
        $classic = $candidate->driver_type === DriverType::ZJMF_API;
        if (trim($reason) === '' || mb_strlen($reason) > 255) {
            throw new DomainException('An operator reason of 1–255 characters is required.');
        }
        DB::transaction(function () use ($candidate, $reason, $actor, $classic): void {
            $users = User::withTrashed()->whereKey(array_filter([$candidate->user_id, $actor?->id]))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $owner = $users->get($candidate->user_id);
            $operator = $actor === null ? null : $users->get($actor->id);
            if ($actor !== null) {
                abort_unless($operator !== null && $operator->isAccessEnabled(), 403);
                Gate::forUser($operator)->authorize($classic ? 'upstream.lifecycle' : 'inventory.retry');
                Gate::forUser($operator)->authorize($classic ? 'upstream.view' : 'inventory.view');
            }
            if ($owner === null || $owner->trashed() || $owner->anonymized_at !== null) {
                throw new DomainException('A closed original owner cannot start another fulfillment attempt.');
            }
            // Serialize with refund before locking the instance (INV-L1); retained services may still be active.
            $invoice = $candidate->invoice_id === null ? null : Invoice::whereKey($candidate->invoice_id)->lockForUpdate()->firstOrFail();
            if ($invoice?->status === InvoiceStatus::REFUNDED) {
                throw new DomainException('A refunded invoice cannot restart fulfillment.');
            }
            $instance = Instance::whereKey($candidate->instance_id)->lockForUpdate()->firstOrFail();
            $locked = FulfillmentTask::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== FulfillmentTaskStatus::FAILED || $instance->status->isTerminal()
                || $locked->user_id !== $owner->id || $instance->user_id !== $owner->id
                || ($invoice !== null && ($invoice->user_id !== $owner->id || $invoice->instance_id !== $instance->id))
                || $locked->invoice_id !== $candidate->invoice_id || $locked->instance_id !== $candidate->instance_id
                || $locked->driver_type !== $candidate->driver_type || $locked->upstream_api_id !== $candidate->upstream_api_id
                || $instance->driver_type !== $locked->driver_type || $instance->upstream_api_id !== $locked->upstream_api_id) {
                throw new DomainException('Only a failed task on the current live binding can be retried.');
            }
            if ($actor !== null && ! $classic && ($invoice?->status !== InvoiceStatus::PAID || $invoice->instance_id !== $instance->id
                || ! in_array($instance->driver_type, [DriverType::REDEMPTION, DriverType::SUB2API], true)
                || $instance->upstream_api_id !== null || $locked->action !== 'provision'
                || ! in_array($instance->status, [InstanceStatus::PENDING, InstanceStatus::PROVISION_FAILED], true)
                || $instance->encrypted_credentials !== null)) {
                throw new DomainException('Only a paid, undelivered local card purchase can be retried here.');
            }
            if ($actor !== null && $classic && ((! in_array($locked->action, ['provision', 'renew', 'upgrade', 'suspend', 'unsuspend', 'terminate', 'register_callback'], true))
                || ($locked->invoice_id !== null && ($invoice?->status !== InvoiceStatus::PAID || $invoice->instance_id !== $instance->id)))) {
                throw new DomainException('Only current paid classic tasks or explicit lifecycle tasks may be retried.');
            }
            $before = $instance->status->value;
            $taskBefore = ['task_status' => $locked->status->value, 'attempts' => $locked->attempts, 'task_id' => $locked->id];
            if ($instance->status === InstanceStatus::PROVISION_FAILED) {
                $instance->update(['status' => InstanceStatus::PENDING]);
            }
            $locked->update(['status' => FulfillmentTaskStatus::PENDING, 'attempts' => 0, 'available_at' => now(),
                'locked_by' => null, 'locked_at' => null, 'lease_expires_at' => null, 'error_message' => null]);
            DB::table('audit_logs')->insert(['actor_type' => $operator === null ? 'system' : 'user', 'actor_id' => $operator?->id, 'action' => 'fulfillment.retry', 'subject_type' => Instance::class,
                ...AdminAuditContext::fields($actor !== null),
                'subject_id' => $instance->id, 'reason' => $reason, 'before' => json_encode(['status' => $before, ...$taskBefore]),
                'after' => json_encode(['status' => $instance->status->value, 'task_id' => $locked->id,
                    'task_status' => $locked->status->value, 'attempts' => $locked->attempts]), 'created_at' => now(), 'updated_at' => now()]);
            DB::afterCommit(fn () => ExecuteFulfillmentTaskJob::dispatch($locked->id));
        });
    }
}
