<?php

declare(strict_types=1);

namespace App\Actions;

use App\Contracts\RenewableResource;
use App\Data\InstanceInfoResult;
use App\Data\ProvisionResult;
use App\Drivers\Upstream\ZjmfFinanceDriver;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Services\DriverManager;
use App\Services\NotificationManager;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExecuteServiceTaskAction
{
    public function execute(int $id, string $worker): ProvisionResult
    {
        $task = FulfillmentTask::findOrFail($id);
        $binding = Instance::findOrFail($task->instance_id);
        $context = json_decode(Crypt::decryptString($task->payload['encrypted_operation']), true, flags: JSON_THROW_ON_ERROR);
        if (! $this->current($task, $binding, $context, $worker)) {
            return ProvisionResult::fail('Operation binding, payment or lease is obsolete.');
        }
        $driver = app(DriverManager::class)->fulfillment($binding->driver_type, $binding->upstream_api_id);
        if (! $driver instanceof RenewableResource || ! $driver instanceof ZjmfFinanceDriver) {
            return ProvisionResult::fail('This operation requires a classic renewable driver.');
        }
        $call = app(CallUpstreamAction::class);
        if ($task->action === 'register_callback') {
            return $call->execute(fn () => $driver->registerDownstreamCallback($binding)) ? ProvisionResult::ok() : ProvisionResult::fail('Callback registration failed.', retryable: true);
        }
        if (in_array($task->action, ['suspend', 'unsuspend', 'terminate'], true)) {
            return $this->lifecycle($task, $binding, $context, $worker, $driver);
        }
        $state = $task->payload['operation'] ?? null;
        if ($state === null) {
            if ($task->action === 'renew') {
                $before = $call->execute(fn () => $driver->syncInfo($binding));
                if ($before->nextDueDate === null || ! Carbon::parse($before->nextDueDate)->eq(Carbon::parse($context['due_boundary']))) {
                    return ProvisionResult::fail('Upstream due boundary changed before renewal; reconcile the existing payment first.');
                }
            }
            if (! $this->intent($task, $binding, $context, $worker, ['state' => 'create_requested'])) {
                return ProvisionResult::fail('Operation became obsolete before request.');
            }
            $result = $call->execute(fn () => $task->action === 'renew' ? $driver->renew($binding, $context['cycle'])
                : $driver->changeConfig($binding, $context['options'] + ['expected_cost' => $task->invoice->upstream_expected_cost, 'invoice_id' => $task->invoice_id]));
            if (! $result->success) {
                $this->evidence($task, ['state' => 'create_uncertain']);

                return ProvisionResult::fail($result->errorMessage ?? 'Upstream invoice requires manual reconciliation.');
            }
            $state = ['state' => ! empty($result->rawResponse['zero_paid']) ? 'paid' : 'invoice_created', 'invoice_id' => $result->rawResponse['invoice_id'] ?? null];
            $this->evidence($task, $state);
        }
        if (! in_array($state['state'], ['invoice_created', 'payment_requested', 'paid', 'delivered'], true)) {
            return ProvisionResult::fail('Existing upstream intent is unresolved; do not issue another invoice.');
        }
        if ($state['state'] === 'invoice_created') {
            $bill = $call->execute(fn () => $driver->operationBill($binding, $state['invoice_id'], $task->invoice_id));
            if (($bill['status'] ?? null) !== 200 || ($bill['data']['currency']['code'] ?? null) !== $task->invoice->currency
                || ! isset($bill['data']['total']) || ! Money::eq(Money::of($bill['data']['total']), $task->invoice->upstream_expected_cost)) {
                Log::error('Classic operation cost mismatch; payment blocked.', ['task_id' => $task->id]);

                return ProvisionResult::fail('Upstream operation cost or currency differs from authorized invoice.');
            }
            $state['state'] = 'payment_requested';
            if (! $this->intent($task, $binding, $context, $worker, $state)) {
                return ProvisionResult::fail('Operation changed before payment.');
            }
            $paid = $call->execute(fn () => $driver->payOperation($binding, $state['invoice_id'], $task->invoice_id));
            if ($paid->success) {
                $state['state'] = 'paid';
                $this->evidence($task, $state);
            }
        }
        // An unknown payment may only be reconciled through authenticated read-only state, never repaid.
        $snapshot = $call->execute(fn () => $driver->syncInfo($binding));
        $confirmed = $task->action === 'renew'
            ? $snapshot->nextDueDate !== null && Carbon::parse($snapshot->nextDueDate)->gte(Carbon::parse($context['renew_until']))
            : $call->execute(fn () => $driver->configurationMatches($binding, $context['options']));
        if (! $confirmed || ! in_array($snapshot->upstreamStatus, ['Active', 'Suspended'], true)) {
            return ProvisionResult::fail('Payment or fulfillment is not confirmed; reconcile this same operation.', retryable: true);
        }

        return $this->commit($task, $binding, $context, $worker, $snapshot);
    }

    private function lifecycle(FulfillmentTask $task, Instance $binding, array $context, string $worker, ZjmfFinanceDriver $driver): ProvisionResult
    {
        $call = app(CallUpstreamAction::class);
        $snapshot = $call->execute(fn () => $driver->syncInfo($binding));
        $target = ['suspend' => 'Suspended', 'unsuspend' => 'Active', 'terminate' => 'Deleted'][$task->action];
        if (in_array($snapshot->upstreamStatus, [$target, 'Deleted'], true)) {
            return $this->commit($task, $binding, $context, $worker, $snapshot);
        }
        if ($task->trigger_source === 'cron_overdue') {
            if ($task->action === 'unsuspend' && ($snapshot->nextDueDate === null || ! Carbon::parse($snapshot->nextDueDate)->isFuture()
                || $snapshot->suspendReason === null || $snapshot->suspendReason !== ($binding->active_config['overdue_suspend_reason'] ?? null))) {
                return ProvisionResult::fail('Automatic recovery cannot clear a manual or unverified suspension.');
            }
            if ($task->action === 'suspend' && ($snapshot->nextDueDate === null || Carbon::parse($snapshot->nextDueDate)->isFuture())) {
                return ProvisionResult::fail('Upstream service is no longer overdue; old suspension discarded.');
            }
            if ($task->action === 'suspend' && ($binding->next_due_at === null || $binding->next_due_at->isFuture())) {
                return ProvisionResult::fail('Local service was renewed; old suspension discarded.');
            }
        }
        if (isset($task->payload['operation'])) {
            return ProvisionResult::fail('Prior lifecycle request awaits authenticated confirmation; it will not be replayed.', retryable: true);
        }
        if (! $this->intent($task, $binding, $context, $worker, ['state' => 'control_requested'])) {
            return ProvisionResult::fail('Lifecycle binding or lease changed.');
        }
        $result = $call->execute(fn () => match ($task->action) {
            'suspend' => $driver->suspend($binding, $context['reason']),
            'unsuspend' => $driver->unsuspend($binding),
            'terminate' => $driver->terminate($binding, $context['reason']),
            default => ProvisionResult::fail('Unsupported lifecycle command.'),
        });
        $this->evidence($task, ['state' => $result->success ? 'control_accepted' : 'control_uncertain']);
        $snapshot = $call->execute(fn () => $driver->syncInfo($binding));
        if (! in_array($snapshot->upstreamStatus, [$target, 'Deleted'], true)) {
            return ProvisionResult::fail('Request retained; wait for upstream confirmation.', retryable: true);
        }

        return $this->commit($task, $binding, $context, $worker, $snapshot);
    }

    private function current(FulfillmentTask $task, Instance $instance, array $context, string $worker): bool
    {
        return $task->status === FulfillmentTaskStatus::RUNNING && $task->locked_by === $worker && $task->lease_expires_at?->isFuture()
            && $task->instance_id === $instance->id && $task->driver_type === $instance->driver_type && $task->upstream_api_id === $instance->upstream_api_id
            && $context['upstream_instance_id'] === $instance->upstream_instance_id && $context['upstream_api_id'] === $instance->upstream_api_id
            && $context['token_hash'] === hash('sha256', $instance->downstream_token ?? '') && $context['base_url'] === $instance->upstreamApi?->base_url
            && ! $instance->status->isTerminal() && ($task->invoice_id === null || ($task->invoice?->status === InvoiceStatus::PAID && $task->invoice->instance_id === $instance->id));
    }

    private function intent(FulfillmentTask $candidate, Instance $binding, array $context, string $worker, array $state): bool
    {
        return DB::transaction(function () use ($candidate, $binding, $context, $worker, $state): bool {
            if ($candidate->invoice_id !== null) {
                Invoice::whereKey($candidate->invoice_id)->lockForUpdate()->firstOrFail();
            }
            $instance = Instance::whereKey($binding->id)->lockForUpdate()->firstOrFail();
            $task = FulfillmentTask::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if (! $this->current($task, $instance, $context, $worker)) {
                return false;
            }
            $payload = $task->payload;
            $payload['operation'] = $state + ['requested_at' => now()->toIso8601String()];
            $task->update(['payload' => $payload]);

            return true;
        });
    }

    private function evidence(FulfillmentTask $candidate, array $state): void
    {
        DB::transaction(function () use ($candidate, $state): void {
            $task = FulfillmentTask::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            $payload = $task->payload;
            $payload['operation'] = $state + ['observed_at' => now()->toIso8601String()];
            $task->update(['payload' => $payload]);
        });
    }

    private function commit(FulfillmentTask $candidate, Instance $binding, array $context, string $worker, InstanceInfoResult $snapshot): ProvisionResult
    {
        return DB::transaction(function () use ($candidate, $binding, $context, $worker, $snapshot): ProvisionResult {
            if ($candidate->invoice_id !== null) {
                Invoice::whereKey($candidate->invoice_id)->lockForUpdate()->firstOrFail();
            }
            $instance = Instance::whereKey($binding->id)->lockForUpdate()->firstOrFail();
            $task = FulfillmentTask::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if (! $this->current($task, $instance, $context, $worker)) {
                return ProvisionResult::fail('Old operation result retained after payment, binding or lease change.');
            }
            $target = InstanceStatus::from($snapshot->upstreamStatus);
            if ($target !== $instance->status && ! $instance->status->canTransitionTo($target)) {
                return ProvisionResult::fail('Invalid observed lifecycle transition.');
            }
            $before = ['status' => $instance->status->value, 'recurring_amount' => $instance->recurring_amount];
            $changes = ['status' => $target, 'last_synced_at' => now()];
            if ($snapshot->nextDueDate !== null) {
                $changes['next_due_at'] = Carbon::parse($snapshot->nextDueDate);
            }
            if ($snapshot->suspendReason !== null && $snapshot->suspendReason !== '') {
                $changes['suspend_reason'] = $snapshot->suspendReason;
            }
            $config = $instance->active_config ?? [];
            if ($task->action === 'terminate' || $target === InstanceStatus::DELETED) {
                FulfillmentTask::where('instance_id', $instance->id)->whereKeyNot($task->id)->whereIn('status', [FulfillmentTaskStatus::PENDING, FulfillmentTaskStatus::RUNNING])->update(['status' => FulfillmentTaskStatus::STALE]);
            }
            if ($task->action === 'suspend' && $target === InstanceStatus::SUSPENDED) {
                $changes['suspended_at'] = now();
                if ($task->trigger_source === 'cron_overdue' && ($task->payload['operation']['state'] ?? null) === 'control_accepted') {
                    $config['overdue_suspension'] = true;
                    $config['overdue_suspend_reason'] = $snapshot->suspendReason;
                    $changes['overdue_since'] = Carbon::parse($context['due_boundary']);
                }
            }
            if ($task->action === 'unsuspend' && $target === InstanceStatus::ACTIVE) {
                unset($config['overdue_suspension']);
                $changes['overdue_since'] = null;
            }
            if (in_array($task->action, ['renew', 'upgrade'], true)) {
                $changes['recurring_amount'] = $context['recurring_after'];
                if ($task->action === 'upgrade') {
                    unset($config['billing_adjustment']);
                    $config['options'] = $context['options']['pairs'];
                    $config['upstream_configoption'] = $context['options']['configoption'];
                }
            }
            $changes['active_config'] = $config;
            if ($task->action === 'suspend' && $task->trigger_source === 'cron_overdue') {
                app(NotificationManager::class)->queue($instance->user_id, 'instance.overdue', ['instance_id' => $instance->id, 'status' => $target->value]);
            }
            $instance->update($changes);
            $payload = $task->payload;
            $payload['operation']['state'] = 'delivered';
            $task->update(['payload' => $payload]);
            DB::table('audit_logs')->insert(['actor_type' => 'system', 'action' => 'instance.'.$task->action.'.confirmed', 'subject_type' => Instance::class,
                'subject_id' => $instance->id, 'reason' => $context['reason'] ?? null, 'before' => json_encode($before),
                'after' => json_encode(['status' => $target->value, 'task_id' => $task->id]), 'created_at' => now(), 'updated_at' => now()]);

            return ProvisionResult::ok();
        });
    }
}
