<?php

declare(strict_types=1);

namespace App\Actions;

use App\Contracts\BoundUpstreamEndpoint;
use App\Contracts\FulfillmentDriver;
use App\Data\ProvisionResult;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Jobs\ReconcileHostSyncJob;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\InstanceUpstreamBinding;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ProvisionUpstreamInstanceAction
{
    public function execute(Instance $instance, FulfillmentDriver $driver, array $payload, ?int $taskId, ?string $worker): ProvisionResult
    {
        $instance = DB::transaction(function () use ($instance, $taskId, $worker): ?Instance {
            $locked = Instance::whereKey($instance->id)->lockForUpdate()->firstOrFail();
            if ($locked->driver_type !== $instance->driver_type || $locked->upstream_api_id !== $instance->upstream_api_id
                || ! in_array($locked->status, [InstanceStatus::PENDING, InstanceStatus::ACTIVE], true) || ! $this->ownsTask($locked, $taskId, $worker)) {
                return null;
            }
            if ($locked->downstream_token === null) {
                $locked->update(['downstream_token' => bin2hex(random_bytes(16))]);
            }

            return $locked;
        });
        if ($instance === null) {
            return ProvisionResult::fail('Upstream task or instance binding is no longer current.');
        }
        if ($instance->status === InstanceStatus::ACTIVE && $instance->upstream_instance_id !== null) {
            return ProvisionResult::ok(upstreamInstanceId: $instance->upstream_instance_id);
        }
        if (! is_string($payload['encrypted_purchase'] ?? null)) {
            return ProvisionResult::fail('Encrypted purchase parameters are missing.');
        }
        $params = json_decode(Crypt::decryptString($payload['encrypted_purchase']), true, flags: JSON_THROW_ON_ERROR);
        $endpoint = $driver instanceof BoundUpstreamEndpoint ? $driver->endpointIdentity() : null;
        $result = app(CallUpstreamAction::class)->execute(fn () => $driver->provision($instance, $params));
        if (! $result->success || $result->upstreamInstanceId === null) {
            return $result;
        }
        $applied = DB::transaction(function () use ($instance, $result, $taskId, $worker, $endpoint): bool {
            $locked = Instance::whereKey($instance->id)->lockForUpdate()->firstOrFail();
            if ($locked->driver_type !== $instance->driver_type || $locked->upstream_api_id !== $instance->upstream_api_id
                || $locked->downstream_token !== $instance->downstream_token || $locked->status !== $instance->status
                || ! $this->ownsTask($locked, $taskId, $worker)) {
                return false;
            }
            $locked->update(['upstream_instance_id' => $result->upstreamInstanceId, 'status' => InstanceStatus::ACTIVE, 'primary_ip' => $result->primaryIp ?? $locked->primary_ip,
                'encrypted_credentials' => $result->credentials === [] ? $locked->encrypted_credentials : $result->credentials]);
            if ($endpoint !== null && $endpoint['upstream_api_id'] === $locked->upstream_api_id
                && ! InstanceUpstreamBinding::where('instance_id', $locked->id)->whereNull('unbound_at')->exists()) {
                InstanceUpstreamBinding::create(['instance_id' => $locked->id, 'driver_type' => $locked->driver_type,
                    'upstream_api_id' => $locked->upstream_api_id, 'upstream_instance_id' => $result->upstreamInstanceId,
                    'bound_at' => now(), 'source_snapshot' => $endpoint + ['upstream_instance_id' => $result->upstreamInstanceId]]);
            }
            DB::table('audit_logs')->insert(['actor_type' => 'system', 'action' => 'instance.upstream_provision', 'subject_type' => Instance::class,
                'subject_id' => $locked->id, 'before' => json_encode(['status' => $instance->status->value]),
                'after' => json_encode(['status' => InstanceStatus::ACTIVE->value, 'upstream_instance_id' => $result->upstreamInstanceId]),
                'created_at' => now(), 'updated_at' => now()]);

            return true;
        });
        if (! $applied) {
            return ProvisionResult::fail('Upstream provisioned but the local lease or binding changed; reconcile before retry.', retryable: true);
        }
        // P4/P5 already register the same downstream identity. Re-query credentials asynchronously.
        $fresh = $instance->fresh();
        DB::afterCommit(fn () => ReconcileHostSyncJob::dispatch($fresh->id, $fresh->upstream_api_id,
            $fresh->upstream_instance_id, $fresh->downstream_token)->onConnection(config('queue.default') === 'sync' ? 'database' : config('queue.default')));

        return $result;
    }

    private function ownsTask(Instance $instance, ?int $taskId, ?string $worker): bool
    {
        if ($taskId === null) {
            return true;
        }
        $task = FulfillmentTask::whereKey($taskId)->lockForUpdate()->firstOrFail();

        return $task->instance_id === $instance->id && $task->status === FulfillmentTaskStatus::RUNNING
            && $task->locked_by === $worker && $task->lease_expires_at !== null && $task->lease_expires_at->isFuture()
            && $task->driver_type === $instance->driver_type && $task->upstream_api_id === $instance->upstream_api_id;
    }
}
