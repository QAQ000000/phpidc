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
use App\Models\InstanceUpstreamBinding;
use App\Models\Invoice;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Services\DriverManager;
use DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class HotSwapInstanceDriverAction
{
    public function execute(Instance $instance, int $accountId, string $hostId, string $pid, string $disposition, string $reason, User $actor, bool $confirmed): void
    {
        Gate::forUser($actor)->authorize('upstream.hotswap');
        if (! $confirmed || trim($reason) === '' || ! in_array($disposition, ['terminate', 'keep_orphan'], true) || ! ctype_digit($hostId) || (int) $hostId < 1 || ! ctype_digit($pid) || (int) $pid < 1) {
            throw new DomainException('Explicit confirmation, target IDs, old-resource disposition and reason are required.');
        }
        $instance = $instance->fresh();
        $fingerprint = $instance->getRawOriginal();
        if (! $instance->status->isBillable()) {
            throw new DomainException('Only a live billable instance can change binding.');
        }
        if ($instance->upstream_api_id === $accountId && $instance->upstream_instance_id === $hostId) {
            throw new DomainException('Choose a different upstream binding.');
        }
        $driver = app(DriverManager::class)->classicAccount($accountId);
        $target = clone $instance;
        $target->setRelation('upstreamApi', UpstreamApi::findOrFail($accountId));
        $target->forceFill(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $accountId, 'upstream_instance_id' => $hostId]);
        $snapshot = app(CallUpstreamAction::class)->execute(fn () => $driver->syncInfo($target));
        $host = $snapshot->rawResponse['data']['host_data'] ?? [];
        if (! in_array($snapshot->upstreamStatus, ['Active', 'Suspended'], true) || (string) ($host['productid'] ?? '') !== $pid
            || ($host['billingcycle'] ?? null) !== $instance->billing_cycle->value || ($host['currency_code'] ?? $snapshot->rawResponse['data']['currency']['code'] ?? null) !== $instance->currency) {
            throw new DomainException('Target host product, cycle, currency and live state must be authenticated and verified.');
        }
        $targetPid = filter_var($pid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($targetPid === false) {
            throw new DomainException('A positive target product identifier is required.');
        }
        $targetProduct = clone $instance->product;
        $targetProduct->upstream_pid = $targetPid;
        $targetCatalog = app(CallUpstreamAction::class)->execute(fn () => $driver->productConfiguration($targetProduct));
        $bindingPayType = $targetCatalog['products']['pay_type'] ?? [];
        $bindingPayType = is_string($bindingPayType) ? json_decode($bindingPayType, true, flags: JSON_THROW_ON_ERROR) : $bindingPayType;
        if (! is_array($bindingPayType)) {
            throw new DomainException('Target billing period rules could not be verified.');
        }
        if ($disposition === 'terminate') {
            $oldDriver = app(DriverManager::class)->fulfillment($instance->driver_type, $instance->upstream_api_id);
            $old = app(CallUpstreamAction::class)->execute(fn () => $oldDriver->syncInfo($instance));
            if ($old->upstreamStatus !== 'Deleted') {
                throw new DomainException('First terminate the old resource and confirm Deleted; an accepted cancellation is insufficient.');
            }
        }
        DB::transaction(function () use ($instance, $target, $snapshot, $accountId, $hostId, $pid, $disposition, $reason, $actor, $fingerprint, $bindingPayType): void {
            User::whereKey($instance->user_id)->lockForUpdate()->firstOrFail();
            $unpaid = Invoice::where('instance_id', $instance->id)->where('status', InvoiceStatus::UNPAID)->lockForUpdate()->exists();
            $locked = Instance::whereKey($instance->id)->lockForUpdate()->firstOrFail();
            if ($locked->getRawOriginal() !== $fingerprint || $unpaid || FulfillmentTask::where('instance_id', $locked->id)->whereNotNull('invoice_id')->whereIn('status', [FulfillmentTaskStatus::PENDING, FulfillmentTaskStatus::RUNNING, FulfillmentTaskStatus::FAILED])->exists()) {
                throw new DomainException('Binding changed or financial operations need reconciliation before hot swap.');
            }
            $before = ['driver_type' => $locked->driver_type->value, 'upstream_api_id' => $locked->upstream_api_id, 'upstream_instance_id' => $locked->upstream_instance_id];
            $old = InstanceUpstreamBinding::where('instance_id', $locked->id)->whereNull('unbound_at')->first();
            if ($old === null) {
                $old = new InstanceUpstreamBinding($before + ['instance_id' => $locked->id, 'bound_at' => $locked->created_at]);
            }
            $old->fill(['unbound_at' => now(), 'unbind_reason' => 'hot_swap', 'upstream_disposition' => $disposition === 'terminate' ? 'terminated' : 'kept_orphan', 'reason_detail' => $reason])->save();
            $token = bin2hex(random_bytes(16));
            $config = $locked->active_config ?? [];
            $config['binding_pay_type'] = $bindingPayType;
            $config['upstream_pid'] = $pid;
            $config['options'] = [];
            $config['upstream_configoption'] = [];
            $config['mapping_requires_review'] = true;
            unset($config['cancellation'], $config['overdue_suspension'], $config['overdue_suspend_reason'], $config['billing_adjustment']);
            $locked->update(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $accountId, 'upstream_instance_id' => $hostId, 'downstream_token' => $token,
                'status' => InstanceStatus::from($snapshot->upstreamStatus), 'primary_ip' => $snapshot->primaryIp, 'assigned_ips' => $snapshot->assignedIps,
                'encrypted_credentials' => array_filter(['username' => $snapshot->username, 'password' => $snapshot->password, 'port' => $snapshot->port, 'os' => $snapshot->os], fn ($v) => $v !== null),
                'suspend_reason' => $snapshot->suspendReason ?: $locked->suspend_reason, 'next_due_at' => $snapshot->nextDueDate,
                'active_config' => $config, 'last_synced_at' => now()]);
            InstanceUpstreamBinding::create(['instance_id' => $locked->id, 'driver_type' => $locked->driver_type, 'upstream_api_id' => $accountId,
                'upstream_instance_id' => $hostId, 'bound_at' => now()]);
            FulfillmentTask::where('instance_id', $locked->id)->whereIn('status', [FulfillmentTaskStatus::PENDING, FulfillmentTaskStatus::RUNNING])->update(['status' => FulfillmentTaskStatus::STALE]);
            $context = ['upstream_api_id' => $accountId, 'upstream_instance_id' => $hostId, 'base_url' => $target->upstreamApi->base_url, 'token_hash' => hash('sha256', $token), 'reason' => $reason];
            $task = FulfillmentTask::create(['user_id' => $locked->user_id, 'instance_id' => $locked->id, 'driver_type' => $locked->driver_type, 'upstream_api_id' => $accountId,
                'action' => 'register_callback', 'trigger_source' => 'admin_manual', 'status' => 'pending', 'idempotency_key' => 'binding:'.$locked->id.':'.$token,
                'payload' => ['encrypted_operation' => Crypt::encryptString(json_encode($context, JSON_THROW_ON_ERROR))], 'available_at' => now()]);
            DB::table('audit_logs')->insert(['actor_type' => 'user', 'actor_id' => $actor->id, 'action' => 'instance.hot_swap', 'subject_type' => Instance::class,
                'subject_id' => $locked->id, 'reason' => $reason, 'before' => json_encode($before), 'after' => json_encode(['upstream_api_id' => $accountId, 'upstream_instance_id' => $hostId, 'old_disposition' => $disposition]), 'created_at' => now(), 'updated_at' => now()]);
            DB::afterCommit(function () use ($task, $disposition, $instance): void {
                ExecuteFulfillmentTaskJob::dispatch($task->id);
                if ($disposition === 'keep_orphan') {
                    Log::warning('Old upstream resource explicitly retained as orphan.', ['instance_id' => $instance->id, 'upstream_api_id' => $instance->upstream_api_id, 'upstream_instance_id' => $instance->upstream_instance_id]);
                }
            });
        });
    }
}
