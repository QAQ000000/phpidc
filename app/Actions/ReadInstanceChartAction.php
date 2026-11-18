<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Instance;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ReadInstanceChartAction
{
    private const CHARTS = ['cpu' => 'CPU使用量', 'disk' => '硬盘IO', 'memory' => '内存用量', 'flow' => '网卡'];

    public function describe(User $user, Instance $instance): array
    {
        if (! app(OpenInstancePanelAction::class)->available($user, $instance)) {
            throw ValidationException::withMessages(['telemetry' => '此服务暂不可查询机器监控。']);
        }
        $advertised = $instance->active_config['upstream_panel']['charts'] ?? [];
        $charts = is_array($advertised) ? array_intersect_key(self::CHARTS, array_flip(array_filter($advertised, 'is_string'))) : [];

        return ['charts' => $charts, 'power' => ($instance->active_config['upstream_panel']['machine_status'] ?? false) === true];
    }

    public function execute(User $user, Instance $instance, string $chart, int $start, int $end): array
    {
        $capabilities = $this->describe($user, $instance);
        if (! array_key_exists($chart, $capabilities['charts'])) {
            throw ValidationException::withMessages(['chart' => '此服务尚未提供该监控图表。']);
        }
        $now = now()->getTimestamp() * 1000;
        if ($start < 0 || $end - $start !== 86400000 || $end > $now || $end < $now - 300000) {
            throw ValidationException::withMessages(['chart' => '监控时间窗口已失效，请刷新页面后重试。']);
        }
        $widgets = app(OpenInstancePanelAction::class)->fetchWidgets($user, $instance, ['chart:'.$chart], ['start' => $start, 'end' => $end]);
        foreach ($widgets as $widget) {
            if ($widget['type'] === 'chart' && $widget['key'] === $chart) {
                return $widget;
            }
        }

        throw ValidationException::withMessages(['chart' => '上游尚未提供可用的监控图表。']);
    }
}
