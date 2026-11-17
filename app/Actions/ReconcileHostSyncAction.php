<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\DriverType;
use App\Models\Instance;
use App\Services\DriverManager;
use Illuminate\Support\Facades\Log;

class ReconcileHostSyncAction
{
    public function execute(int $id, ?int $apiId, ?string $hostId, string $token): void
    {
        $instance = Instance::findOrFail($id);
        if ($instance->driver_type !== DriverType::ZJMF_API || $instance->upstream_api_id !== $apiId
            || $instance->upstream_instance_id !== $hostId || $instance->downstream_token !== $token || $instance->status->isTerminal()) {
            return;
        }
        if ($apiId === null || $hostId === null) {
            Log::warning('Host sync requires a completed upstream binding.', ['instance_id' => $id]);

            return;
        }
        $snapshot = app(CallUpstreamAction::class)->execute(fn () => app(DriverManager::class)->fulfillment($instance->driver_type, $apiId)->syncInfo($instance));
        app(ApplyInstanceSnapshotAction::class)->execute($instance, $snapshot);
    }
}
