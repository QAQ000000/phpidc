<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Instance;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ReadInstanceTelemetryAction
{
    public function execute(User $user, Instance $instance): array
    {
        $widgets = app(OpenInstancePanelAction::class)->fetchWidgets($user, $instance, ['machine_status', 'chart']);
        $telemetry = array_values(array_filter($widgets, fn (array $widget) => in_array($widget['type'], ['machine_status', 'chart'], true)));
        if ($telemetry === []) {
            throw ValidationException::withMessages(['telemetry' => '上游尚未提供可用的机器状态或监控图表。']);
        }

        return $telemetry;
    }
}
