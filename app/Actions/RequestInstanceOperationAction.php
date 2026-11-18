<?php

declare(strict_types=1);

namespace App\Actions;

use App\Contracts\RenewableResource;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Jobs\ExecuteFulfillmentTaskJob;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Services\DriverManager;
use DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RequestInstanceOperationAction
{
    public function execute(Instance $instance, string $action, string $reason, string $requestId, ?User $actor = null, string $source = 'admin_manual'): FulfillmentTask
    {
        if ($actor !== null && ! ($actor->id === $instance->user_id && $action === 'terminate')) {
            Gate::forUser($actor)->authorize('upstream.lifecycle');
        }
        if (! in_array($action, ['suspend', 'unsuspend', 'terminate'], true) || trim($reason) === '' || ! preg_match('/^[A-Za-z0-9:_-]{1,80}$/D', $requestId)) {
            throw new DomainException('A supported action, stable request identifier and reason are required.');
        }
        $driver = app(DriverManager::class)->fulfillment($instance->driver_type, $instance->upstream_api_id);
        if (! $driver instanceof RenewableResource) {
            throw new DomainException('This service is not renewable.');
        }

        return DB::transaction(function () use ($instance, $action, $reason, $requestId, $actor, $source): FulfillmentTask {
            $user = User::whereKey($instance->user_id)->lockForUpdate()->firstOrFail();
            $unpaid = Invoice::where('instance_id', $instance->id)->where('status', InvoiceStatus::UNPAID)->orderBy('id')->lockForUpdate()->get();
            if ($action === 'terminate') {
                PaymentAttempt::whereIn('invoice_id', $unpaid->modelKeys())->orderBy('id')->lockForUpdate()->get();
            }
            $locked = Instance::whereKey($instance->id)->lockForUpdate()->firstOrFail();
            $key = 'control:'.$locked->id.':'.$action.':'.$requestId;
            $existing = FulfillmentTask::where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return $existing;
            }
            $blockingInvoices = in_array($action, ['suspend', 'terminate'], true) ? $unpaid->contains(fn ($invoice) => $invoice->type !== InvoiceType::RENEW) : $unpaid->isNotEmpty();
            if ($locked->user_id !== $user->id || ! $locked->status->isBillable() || $locked->upstream_instance_id === null || $blockingInvoices || FulfillmentTask::where('instance_id', $locked->id)->whereIn('status', [FulfillmentTaskStatus::PENDING, FulfillmentTaskStatus::RUNNING, FulfillmentTaskStatus::FAILED])->exists()) {
                throw new DomainException('Resolve billing and earlier operations on this live binding first.');
            }
            if ($action === 'terminate') {
                foreach ($unpaid as $invoice) {
                    app(ExpireUnpaidInvoicesAction::class)->cancelRenewalForTermination($user, $invoice, $reason, $actor);
                }
            }
            $context = ['upstream_api_id' => $locked->upstream_api_id, 'upstream_instance_id' => $locked->upstream_instance_id,
                'base_url' => $locked->upstreamApi?->base_url, 'token_hash' => hash('sha256', $locked->downstream_token ?? ''), 'reason' => $reason,
                'due_boundary' => $locked->next_due_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z')];
            $task = FulfillmentTask::create(['user_id' => $locked->user_id, 'instance_id' => $locked->id, 'driver_type' => $locked->driver_type,
                'upstream_api_id' => $locked->upstream_api_id, 'action' => $action, 'trigger_source' => $source, 'status' => 'pending',
                'idempotency_key' => $key, 'payload' => ['encrypted_operation' => Crypt::encryptString(json_encode($context, JSON_THROW_ON_ERROR))], 'available_at' => now()]);
            if ($action === 'terminate') {
                $config = $locked->active_config ?? [];
                $config['cancellation'] = ['task_id' => $task->id, 'requested_at' => now()->toIso8601String()];
                $locked->update(['active_config' => $config]);
            }
            DB::table('audit_logs')->insert(['actor_type' => $actor ? 'user' : 'system', 'actor_id' => $actor?->id, 'action' => 'instance.'.$action.'.request',
                'subject_type' => Instance::class, 'subject_id' => $locked->id, 'reason' => $reason, 'after' => json_encode(['task_id' => $task->id]), 'created_at' => now(), 'updated_at' => now()]);
            DB::afterCommit(fn () => ExecuteFulfillmentTaskJob::dispatch($task->id));

            return $task;
        });
    }
}
