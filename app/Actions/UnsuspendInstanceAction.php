<?php

declare(strict_types=1);

namespace App\Actions;

use App\Contracts\RenewableResource;
use App\Data\InstanceInfoResult;
use App\Data\ProvisionResult;
use App\Models\Instance;
use App\Services\DriverManager;
use DomainException;

class UnsuspendInstanceAction
{
    public function execute(Instance $instance, string $reason): ProvisionResult
    {
        if (trim($reason) === '') {
            throw new DomainException('An unsuspension reason is required.');
        }
        $instance = $instance->fresh();
        if ($instance->status->isTerminal()) {
            return ProvisionResult::fail('Terminal instances cannot be unsuspended.');
        }
        $driver = app(DriverManager::class)->fulfillment($instance->driver_type, $instance->upstream_api_id);
        if (! $driver instanceof RenewableResource) {
            return ProvisionResult::fail('This driver does not support unsuspension.');
        }
        $result = app(CallUpstreamAction::class)->execute(fn () => $driver->unsuspend($instance));
        if ($result->success) {
            $host = $result->rawResponse['data']['host_data'] ?? [];
            if (! app(ApplyInstanceSnapshotAction::class)->execute($instance, new InstanceInfoResult(
                upstreamStatus: $host['domainstatus'] ?? 'Active', suspendReason: $host['suspendreason'] ?? null), $reason, 'instance.unsuspend')) {
                return ProvisionResult::fail('Instance binding or state changed during unsuspension; reconcile again.', retryable: true);
            }
        }

        return $result;
    }
}
