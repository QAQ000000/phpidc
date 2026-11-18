<?php

declare(strict_types=1);

namespace App\Actions;

use App\Contracts\PowerControllable;
use App\Enums\InstanceStatus;
use App\Enums\ProvisionCommand;
use App\Models\Instance;
use App\Models\User;
use App\Services\DriverManager;
use Illuminate\Validation\ValidationException;

class ReadInstancePowerStatusAction
{
    public function execute(User $user, Instance $instance): array
    {
        abort_unless($instance->user_id === $user->id, 403);
        if ($instance->status !== InstanceStatus::ACTIVE || $instance->upstream_instance_id === null || $instance->upstream_api_id === null
            || ($instance->active_config['upstream_panel']['machine_status'] ?? false) !== true
            || ! $instance->upstreamApi()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['power_status' => '此服务暂不可查询机器电源状态。']);
        }
        $driver = app(DriverManager::class)->fulfillment($instance->driver_type, $instance->upstream_api_id);
        abort_unless($driver instanceof PowerControllable, 422);
        $binding = $instance->only(['user_id', 'status', 'driver_type', 'upstream_api_id', 'upstream_instance_id', 'downstream_token']);
        $result = app(CallUpstreamAction::class)->execute(fn () => $driver->executePowerCommand($instance, ProvisionCommand::STATUS));
        $current = $instance->fresh();
        if ($current === null || $current->only(array_keys($binding)) !== $binding
            || ! $current->upstreamApi()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['power_status' => '服务绑定或状态已变化，请刷新后重试。']);
        }
        if (! $result->success) {
            return ['available' => false, 'state' => 'unavailable'];
        }
        // Only the driver's normalized widget is public; never expose the upstream raw response.
        $data = $result->rawResponse['machine_status_widget']['data'] ?? [];
        if (($data['available'] ?? false) !== true || ! in_array($data['state'] ?? null, ['on', 'off', 'unknown'], true)) {
            return ['available' => false, 'state' => 'unavailable'];
        }

        return ['available' => true, 'state' => $data['state']];
    }
}
