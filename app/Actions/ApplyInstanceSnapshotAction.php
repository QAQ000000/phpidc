<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\InstanceInfoResult;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Support\InstanceDueDate;
use Illuminate\Support\Facades\DB;

class ApplyInstanceSnapshotAction
{
    public function execute(Instance $binding, InstanceInfoResult $snapshot, ?string $reason = null, string $action = 'instance.upstream_sync'): bool
    {
        return DB::transaction(function () use ($binding, $snapshot, $reason, $action): bool {
            $instance = Instance::whereKey($binding->id)->lockForUpdate()->firstOrFail();
            if ($instance->driver_type !== $binding->driver_type || $instance->upstream_api_id !== $binding->upstream_api_id
                || $instance->upstream_instance_id !== $binding->upstream_instance_id || $instance->downstream_token !== $binding->downstream_token
                || $instance->status !== $binding->status || $instance->status->isTerminal()) {
                return false;
            }
            $target = InstanceStatus::tryFrom($snapshot->upstreamStatus);
            $changes = ['last_synced_at' => now()];
            if ($snapshot->primaryIp !== null) {
                $changes['primary_ip'] = $snapshot->primaryIp;
            }
            if ($snapshot->assignedIps !== []) {
                $changes['assigned_ips'] = $snapshot->assignedIps;
            }
            if ($snapshot->suspendReason !== null && trim($snapshot->suspendReason) !== '') {
                $changes['suspend_reason'] = $snapshot->suspendReason;
            }
            if ($snapshot->nextDueDate !== null) {
                $changes['next_due_at'] = InstanceDueDate::parse($snapshot->nextDueDate);
            }
            $credentials = $instance->encrypted_credentials ?? [];
            foreach (['username' => $snapshot->username, 'password' => $snapshot->password, 'port' => $snapshot->port, 'os' => $snapshot->os] as $key => $value) {
                if ($value !== null && $value !== '') {
                    $credentials[$key] = $value;
                }
            }
            foreach ($snapshot->panelWidgets as $widget) {
                if (($widget['type'] ?? null) === 'cloud_capabilities' && is_array($widget['data'] ?? null)) {
                    $config = $instance->active_config ?? [];
                    $config['upstream_panel'] = $widget['data'];
                    $changes['active_config'] = $config;
                }
                if (($widget['type'] ?? null) === 'redemption_code' && is_array($widget['data'] ?? null)
                    && is_string($widget['data']['card_key'] ?? null) && trim($widget['data']['card_key']) !== ''
                    && $snapshot->upstreamStatus === 'Active') {
                    $credentials['card_key'] = $widget['data']['card_key'];
                    // Only structured fields from the verified protocol enter the encrypted cast.
                    unset($credentials['redeem_url']);
                    if (is_string($widget['data']['redeem_url'] ?? null)) {
                        $credentials['redeem_url'] = $widget['data']['redeem_url'];
                    }
                }
            }
            if ($credentials !== []) {
                $changes['encrypted_credentials'] = $credentials;
            }
            if ($target !== null && $target !== $instance->status && $instance->status->canTransitionTo($target)) {
                $changes['status'] = $target;
                if ($target === InstanceStatus::SUSPENDED) {
                    $changes['suspended_at'] = now();
                }
                if ($target->isTerminal()) {
                    FulfillmentTask::where('instance_id', $instance->id)->whereIn('status', [FulfillmentTaskStatus::PENDING, FulfillmentTaskStatus::RUNNING])
                        ->update(['status' => FulfillmentTaskStatus::STALE]);
                }
            }
            if (isset($changes['status']) || $reason !== null) {
                DB::table('audit_logs')->insert(['actor_type' => 'system', 'action' => $action, 'reason' => $reason,
                    'subject_type' => Instance::class, 'subject_id' => $instance->id,
                    'before' => json_encode(['status' => $instance->status->value]),
                    'after' => json_encode(['status' => ($changes['status'] ?? $instance->status)->value]),
                    'created_at' => now(), 'updated_at' => now()]);
            }
            $instance->update($changes);

            return true;
        });
    }
}
