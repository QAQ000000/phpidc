<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\InstanceInfoResult;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ApplyInstanceSnapshotAction
{
    public function execute(Instance $binding, InstanceInfoResult $snapshot): bool
    {
        return DB::transaction(function () use ($binding, $snapshot): bool {
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
                $changes['next_due_at'] = Carbon::parse($snapshot->nextDueDate);
            }
            $credentials = $instance->encrypted_credentials ?? [];
            foreach (['username' => $snapshot->username, 'password' => $snapshot->password, 'port' => $snapshot->port, 'os' => $snapshot->os] as $key => $value) {
                if ($value !== null && $value !== '') {
                    $credentials[$key] = $value;
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
                DB::table('audit_logs')->insert(['actor_type' => 'system', 'action' => 'instance.upstream_sync',
                    'subject_type' => Instance::class, 'subject_id' => $instance->id,
                    'before' => json_encode(['status' => $instance->status->value]), 'after' => json_encode(['status' => $target->value]),
                    'created_at' => now(), 'updated_at' => now()]);
                if ($target->isTerminal()) {
                    FulfillmentTask::where('instance_id', $instance->id)->whereIn('status', [FulfillmentTaskStatus::PENDING, FulfillmentTaskStatus::RUNNING])
                        ->update(['status' => FulfillmentTaskStatus::STALE]);
                }
            }
            $instance->update($changes);

            return true;
        });
    }
}
