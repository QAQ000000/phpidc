<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\DriverType;
use App\Models\Instance;
use App\Models\UpstreamApi;
use App\Services\DriverManager;
use App\Support\MachineOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReconcileHostSyncAction
{
    public function execute(int $id, ?int $apiId, ?string $hostId, string $token): void
    {
        $instance = Instance::with('upstreamApi')->findOrFail($id);
        if ($instance->driver_type !== DriverType::ZJMF_API || $instance->upstream_api_id !== $apiId
            || $instance->upstream_instance_id !== $hostId || $instance->downstream_token !== $token || $instance->status->isTerminal()) {
            return;
        }
        if ($apiId === null || $hostId === null) {
            Log::warning('Host sync requires a completed upstream binding.', ['instance_id' => $id]);

            return;
        }
        $operations = app(MachineOperation::class);
        $binding = $operations->binding($instance);
        $accountAuth = $operations->accountFingerprint($instance);
        $snapshot = app(CallUpstreamAction::class)->execute(fn () => app(DriverManager::class)->fulfillment($instance->driver_type, $apiId)->syncInfo($instance));
        DB::transaction(function () use ($instance, $snapshot, $apiId, $operations, $binding, $accountAuth): void {
            $current = Instance::whereKey($instance->id)->lockForUpdate()->firstOrFail();
            $account = UpstreamApi::whereKey($apiId)->lockForUpdate()->first();
            $current->setRelation('upstreamApi', $account);
            if (! $account?->is_active || $operations->binding($current) !== $binding
                || $operations->accountFingerprint($current) !== $accountAuth) {
                Log::warning('Host sync discarded after upstream account or binding changed.', ['instance_id' => $instance->id]);

                return;
            }
            app(ApplyInstanceSnapshotAction::class)->execute($instance, $snapshot);
        });
    }
}
