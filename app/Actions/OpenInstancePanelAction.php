<?php

declare(strict_types=1);

namespace App\Actions;

use App\Contracts\HasCustomPanelWidgets;
use App\Enums\InstanceStatus;
use App\Models\Instance;
use App\Models\User;
use App\Services\DriverManager;
use App\Support\MachineOperation;
use Illuminate\Validation\ValidationException;

class OpenInstancePanelAction
{
    public function __construct(private readonly DriverManager $drivers) {}

    public function available(User $user, Instance $instance): bool
    {
        abort_unless($instance->user_id === $user->id, 403);
        if ($instance->status !== InstanceStatus::ACTIVE || $instance->upstream_instance_id === null
            || $instance->upstream_api_id === null || ! $instance->upstreamApi()->where('is_active', true)->exists()) {
            return false;
        }

        return $this->drivers->fulfillment($instance->driver_type, $instance->upstream_api_id) instanceof HasCustomPanelWidgets;
    }

    public function execute(User $user, Instance $instance): array
    {
        foreach ($this->fetchWidgets($user, $instance, ['login_form']) as $widget) {
            if ($widget['type'] === 'login_form') {
                return $widget['data'];
            }
        }

        throw ValidationException::withMessages(['panel' => '上游尚未提供可用的面板登录入口。']);
    }

    public function fetchWidgets(User $user, Instance $instance, array $types = [], array $readOptions = []): array
    {
        if (! $this->available($user, $instance)) {
            throw ValidationException::withMessages(['panel' => '该服务暂不可登录控制面板。']);
        }
        $driver = $this->drivers->fulfillment($instance->driver_type, $instance->upstream_api_id, $types, $readOptions);
        abort_unless($driver instanceof HasCustomPanelWidgets, 422);
        $binding = app(MachineOperation::class)->binding($instance);
        $accountAuth = app(MachineOperation::class)->accountFingerprint($instance);
        $before = $instance->only(['user_id', 'status', 'driver_type', 'upstream_api_id', 'upstream_instance_id', 'downstream_token']);
        // Live panel data is fetched only on an authenticated POST and never persisted or cached here.
        $widgets = app(CallUpstreamAction::class)->execute(fn () => $driver->fetchPanelWidgets($instance));
        $current = Instance::findOrFail($instance->id);
        if ($current->only(['user_id', 'status', 'driver_type', 'upstream_api_id', 'upstream_instance_id', 'downstream_token']) !== $before
            || ! $current->upstreamApi?->is_active || app(MachineOperation::class)->binding($current) !== $binding
            || app(MachineOperation::class)->accountFingerprint($current) !== $accountAuth) {
            throw ValidationException::withMessages(['panel' => '服务绑定或状态已变化，请刷新后重试。']);
        }

        return $widgets;
    }
}
