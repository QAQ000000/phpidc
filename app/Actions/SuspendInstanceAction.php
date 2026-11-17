<?php

declare(strict_types=1);

namespace App\Actions;

use App\Contracts\RenewableResource;
use App\Data\InstanceInfoResult;
use App\Data\ProvisionResult;
use App\Models\Instance;
use App\Services\DriverManager;
use DomainException;

class SuspendInstanceAction
{
    public function execute(Instance $instance, string $reason): ProvisionResult
    {
        if (trim($reason) === '') {
            throw new DomainException('A suspension reason is required.');
        }
        $instance = $instance->fresh();
        if ($instance->status->isTerminal()) {
            return ProvisionResult::fail('Terminal instances cannot be suspended.');
        }
        $driver = app(DriverManager::class)->fulfillment($instance->driver_type, $instance->upstream_api_id);
        if (! $driver instanceof RenewableResource) {
            return ProvisionResult::fail('This driver does not support suspension.');
        }
        $result = app(CallUpstreamAction::class)->execute(fn () => $driver->suspend($instance, $reason));
        if ($result->success) {
            $host = $result->rawResponse['data']['host_data'] ?? [];
            $applied = app(ApplyInstanceSnapshotAction::class)->execute($instance, new InstanceInfoResult(
                upstreamStatus: $host['domainstatus'] ?? 'Suspended', suspendReason: $host['suspendreason'] ?? $reason), $reason, 'instance.suspend');
            if (! $applied) {
                return ProvisionResult::fail('Instance binding or state changed during suspension; reconcile again.', retryable: true);
            }
        }

        return $result;
    }
}
