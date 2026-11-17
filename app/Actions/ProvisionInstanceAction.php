<?php

declare(strict_types=1);

namespace App\Actions;

use App\Contracts\UpstreamAccount;
use App\Data\ProvisionResult;
use App\Drivers\SelfHosted\Sub2ApiRemoteDriver;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\RedemptionCode;
use App\Services\DriverManager;
use Illuminate\Support\Facades\DB;

class ProvisionInstanceAction
{
    public function __construct(private readonly DriverManager $drivers) {}

    public function execute(Instance $instance, array $params, ?int $taskId = null, ?string $worker = null): ProvisionResult
    {
        $driver = $this->drivers->fulfillment($instance->driver_type, $instance->upstream_api_id);
        if ($driver instanceof Sub2ApiRemoteDriver) {
            return app(ProvisionRemoteCardAction::class)->execute($instance, $driver, $params, $taskId, $worker);
        }
        if ($driver instanceof UpstreamAccount) {
            return app(ProvisionUpstreamInstanceAction::class)->execute($instance, $driver, $params, $taskId, $worker);
        }
        $reserved = DB::transaction(function () use ($instance, $taskId, $worker): bool {
            $locked = Instance::whereKey($instance->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [InstanceStatus::PENDING, InstanceStatus::ACTIVE], true)) {
                return false;
            }
            $code = RedemptionCode::where('instance_id', $locked->id)->lockForUpdate()->first();
            if ($code === null) {
                $code = RedemptionCode::where('product_id', $locked->product_id)->where('status', 'available')
                    ->whereNull('instance_id')->where(fn ($q) => $q->whereNull('reserved_until')->orWhere('reserved_until', '<', now()))
                    ->orderBy('id')->lockForUpdate()->first();
            }
            if ($code === null || $code->product_id !== $locked->product_id) {
                return false;
            }
            if (! $this->ownsTask($locked, $taskId, $worker)) {
                return false;
            }
            if ($code->status !== 'sold') {
                $code->update(['instance_id' => $locked->id, 'status' => 'reserved', 'reserved_until' => now()->addMinutes(15)]);
            }

            return true;
        });
        if (! $reserved) {
            return ProvisionResult::fail('No available card or instance is not provisionable.', retryable: true);
        }
        $instance = $instance->fresh();
        $result = $driver->provision($instance, $params);
        if (! $result->success) {
            return $result;
        }

        return DB::transaction(function () use ($instance, $result, $taskId, $worker): ProvisionResult {
            $locked = Instance::whereKey($instance->id)->lockForUpdate()->firstOrFail();
            $code = RedemptionCode::where('instance_id', $locked->id)->lockForUpdate()->first();
            if (! $this->ownsTask($locked, $taskId, $worker)) {
                return ProvisionResult::fail('Task lease changed before delivery.', retryable: true);
            }
            if ($code === null || $code->product_id !== $locked->product_id
                || ! in_array($locked->status, [InstanceStatus::PENDING, InstanceStatus::ACTIVE], true)
                || $locked->driver_type !== $instance->driver_type || $locked->upstream_api_id !== $instance->upstream_api_id
                || ($code->status !== 'sold' && ($code->reserved_until === null || $code->reserved_until->isPast()))
                || ($result->credentials['card_key'] ?? null) !== $code->code) {
                return ProvisionResult::fail('Card reservation changed; retry without delivering credentials.', retryable: true);
            }
            if ($code->status === 'sold') {
                return ProvisionResult::ok(credentials: $locked->encrypted_credentials ?? []);
            }
            $code->update(['status' => 'sold', 'sold_at' => now(), 'reserved_until' => null]);
            $before = $locked->status->value;
            $locked->update(['encrypted_credentials' => $result->credentials, 'status' => InstanceStatus::ACTIVE]);
            DB::table('audit_logs')->insert([
                'actor_type' => 'system', 'action' => 'instance.provision', 'subject_type' => Instance::class,
                'subject_id' => $locked->id, 'before' => json_encode(['status' => $before]),
                'after' => json_encode(['status' => InstanceStatus::ACTIVE->value]), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $result;
        });
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
