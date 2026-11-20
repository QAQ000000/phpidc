<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\DriverType;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Services\DriverManager;
use App\Support\MachineOperation;
use App\Support\OperationsAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SyncAdminInstanceAction
{
    public function execute(Instance $instance, User $actor, ?string $reason = null): void
    {
        $reason = trim($reason ?? '');
        validator(['reason' => $reason], ['reason' => ['string', 'max:255']])->validate();
        $reason = $reason !== '' ? $reason : '管理员同步上游服务事实';
        $this->authorize(User::withTrashed()->find($actor->id));
        $source = Instance::with('upstreamApi')->findOrFail($instance->id);
        $owner = User::withTrashed()->find($source->user_id);
        $this->admit($source, $owner);
        $operations = app(MachineOperation::class);
        $binding = $operations->binding($source);
        $account = $operations->accountFingerprint($source);
        // Pure driver resolution and the explicit read happen before financial row locks.
        $driver = app(DriverManager::class)->fulfillment($source->driver_type, $source->upstream_api_id);
        $snapshot = app(CallUpstreamAction::class)->execute(fn () => $driver->syncInfo($source));

        DB::transaction(function () use ($source, $actor, $reason, $binding, $account, $operations, $snapshot): void {
            $users = User::withTrashed()->whereKey([$source->user_id, $actor->id])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $operator = $this->authorize($users->get($actor->id));
            $owner = $users->get($source->user_id);
            $current = Instance::whereKey($source->id)->lockForUpdate()->firstOrFail();
            if ($current->user_id !== $source->user_id || $current->upstream_api_id !== $source->upstream_api_id) {
                $this->changed();
            }
            $current->setRelation('upstreamApi', UpstreamApi::whereKey($source->upstream_api_id)->lockForUpdate()->first());
            $this->admit($current, $owner);
            if ($operations->binding($current) !== $binding || $operations->accountFingerprint($current) !== $account
                || $current->status !== $source->status) {
                $this->changed();
            }
            // An authenticated deletion may stale these tasks; retain the normal instance -> task order.
            FulfillmentTask::where('instance_id', $current->id)->orderBy('id')->lockForUpdate()->get(['id']);
            if (! app(ApplyInstanceSnapshotAction::class)->execute($source, $snapshot)) {
                $this->changed();
            }
            OperationsAudit::record($operator, 'instance.admin_sync', Instance::class, $source->id, $reason,
                ['status' => $source->status->value], ['status' => $current->refresh()->status->value,
                    'user_id' => $source->user_id, 'upstream_api_id' => $source->upstream_api_id]);
        });
    }

    private function authorize(?User $actor): User
    {
        abort_unless($actor !== null && $actor->isAccessEnabled(), 403);
        Gate::forUser($actor)->authorize('upstream.view');
        Gate::forUser($actor)->authorize('upstream.sync');

        return $actor;
    }

    private function admit(Instance $instance, ?User $owner): void
    {
        if ($owner === null || $owner->trashed() || $owner->anonymized_at !== null
            || $instance->driver_type !== DriverType::ZJMF_API || $instance->status->isTerminal()
            || $instance->upstream_api_id === null || $instance->upstream_instance_id === null
            || trim($instance->upstream_instance_id) === '' || ! $instance->upstreamApi?->is_active
            || $instance->upstreamApi->driver !== DriverType::ZJMF_API) {
            throw ValidationException::withMessages(['sync' => '仅可同步未注销客户的有效经典上游绑定，终态服务不会恢复。']);
        }
    }

    private function changed(): never
    {
        throw ValidationException::withMessages(['sync' => '同步期间服务归属、绑定、状态或连接凭据已变化，本次快照未应用。']);
    }
}
