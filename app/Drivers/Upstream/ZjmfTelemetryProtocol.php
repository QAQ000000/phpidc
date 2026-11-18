<?php

declare(strict_types=1);

namespace App\Drivers\Upstream;

use App\Data\ProvisionResult;
use App\Enums\ProvisionCommand;
use App\Models\Instance;
use Illuminate\Validation\ValidationException;

class ZjmfTelemetryProtocol
{
    public function __construct(private readonly ZjmfClient $client) {}

    public function capabilityWidgets(array $header): array
    {
        $charts = $this->charts($header);
        $power = ($header['data']['module_power_status'] ?? false) === true;
        if (! $power && $charts === []) {
            return [];
        }

        return [['key' => 'cloud', 'title' => '云监控', 'type' => 'cloud_capabilities', 'data' => [
            'machine_status' => $power, 'charts' => array_column($charts, 'type'),
            'information' => collect($header['data']['module_client_area'] ?? [])->contains(fn ($area) => is_array($area) && ($area['key'] ?? null) === 'information'),
        ]]];
    }

    public function widgets(Instance $instance, array $header): array
    {
        $context = ['instance_id' => $instance->id];
        // The upstream default date calculation is inconsistent. Always supply an explicit 24h window in milliseconds.
        $end = now()->getTimestamp() * 1000;
        $charts = $this->charts($header);
        $power = ($header['data']['module_power_status'] ?? false) === true;
        $requests = [];
        if ($power) {
            $requests['power'] = ['method' => 'POST', 'path' => '/provision/default',
                'params' => ['id' => $instance->upstream_instance_id, 'func' => ProvisionCommand::STATUS->value]];
        }
        foreach ($charts as $chart) {
            $requests['chart:'.$chart['type']] = ['method' => 'GET', 'path' => '/provision/chart/'.$instance->upstream_instance_id,
                'params' => ['type' => $chart['type'], 'start' => $end - 86400000, 'end' => $end]];
        }
        if ($requests === []) {
            return [];
        }
        // Independent reads share authentication and a bounded pool; no chart waits for the preceding chart.
        $responses = $this->client->readBatch($requests, $context);
        $widgets = [];
        if ($power) {
            $widgets[] = $this->powerStatusResult($responses['power'])->rawResponse['machine_status_widget'];
        }
        // Preserve the advertised order even when requests complete in a different order.
        foreach ($charts as $chart) {
            $widgets[] = ['key' => $chart['type'], 'title' => $chart['title'], 'type' => 'chart',
                'data' => $this->chartData($responses['chart:'.$chart['type']])];
        }

        return $widgets;
    }

    public function powerStatus(Instance $instance): ProvisionResult
    {
        $response = $this->client->request('POST', '/provision/default', ['id' => $instance->upstream_instance_id,
            'func' => ProvisionCommand::STATUS->value], ['instance_id' => $instance->id]);

        return $this->powerStatusResult($response);
    }

    public function chart(Instance $instance, string $chart, array $readOptions): array
    {
        $titles = ['cpu' => 'CPU使用量', 'disk' => '硬盘IO', 'memory' => '内存用量', 'flow' => '网卡'];
        $advertised = $instance->active_config['upstream_panel']['charts'] ?? [];
        $start = $readOptions['start'] ?? null;
        $end = $readOptions['end'] ?? null;
        $now = now()->getTimestamp() * 1000;
        if (! isset($titles[$chart]) || ! is_array($advertised) || ! in_array($chart, $advertised, true)
            || ! is_int($start) || ! is_int($end) || $start < 0 || $end - $start !== 86400000
            || $end > $now || $end < $now - 300000) {
            throw ValidationException::withMessages(['chart' => '此监控类型或时间窗口不可用，请刷新页面。']);
        }
        $response = $this->client->request('GET', '/provision/chart/'.$instance->upstream_instance_id,
            ['type' => $chart, 'start' => $start, 'end' => $end], ['instance_id' => $instance->id]);

        return ['key' => $chart, 'title' => $titles[$chart], 'type' => 'chart', 'data' => $this->chartData($response)];
    }

    private function powerStatusResult(array $response): ProvisionResult
    {
        $state = $response['data']['status'] ?? null;
        $available = in_array($response['status'] ?? null, [200, 'success'], true) && is_string($state)
            && in_array($state, ['on', 'off', 'unknown'], true);
        // The existing opaque result payload carries a normalized widget; no frozen DTO fields change.
        $response['machine_status_widget'] = ['key' => 'power', 'title' => '机器状态', 'type' => 'machine_status',
            'data' => ['available' => $available, 'state' => $available ? $state : 'unavailable']];

        return new ProvisionResult(success: $available,
            errorMessage: $available ? null : '上游暂未返回可用的机器电源状态，请稍后手动重试。', rawResponse: $response);
    }

    private function charts(array $header): array
    {
        $charts = [];
        foreach ($header['data']['module_chart'] ?? [] as $chart) {
            if (is_array($chart) && in_array($chart['type'] ?? null, ['cpu', 'disk', 'memory', 'flow'], true)
                && is_string($chart['title'] ?? null) && empty($chart['select'])) {
                $charts[$chart['type']] = ['type' => $chart['type'], 'title' => $chart['title']];
            }
        }

        return array_values($charts);
    }

    private function chartData(array $response): array
    {
        $unavailable = ['available' => false];
        $data = $response['data'] ?? null;
        if (! in_array($response['status'] ?? null, [200, 'success'], true) || ! is_array($data)
            || ! in_array($data['chart_type'] ?? null, ['area', 'line', 'bar'], true) || ! is_string($data['unit'] ?? null)
            || ! is_array($data['label'] ?? null) || ! array_is_list($data['label'])
            || ! is_array($data['list'] ?? null) || ! array_is_list($data['list']) || count($data['label']) !== count($data['list'])) {
            return $unavailable;
        }
        $series = [];
        foreach ($data['list'] as $index => $points) {
            if (! is_string($data['label'][$index]) || ! is_array($points) || ! array_is_list($points)) {
                return $unavailable;
            }
            $normalized = [];
            foreach ($points as $point) {
                if (! is_array($point) || ! is_string($point['time'] ?? null) || ! array_key_exists('value', $point)
                    || ($point['value'] !== null && (! is_numeric($point['value']) || ! is_finite((float) $point['value'])))) {
                    return $unavailable;
                }
                $normalized[] = ['time' => $point['time'], 'value' => $point['value'] === null ? null : (string) $point['value']];
            }
            $series[] = ['label' => $data['label'][$index], 'points' => $normalized];
        }

        return ['available' => true, 'chart_type' => $data['chart_type'], 'unit' => $data['unit'], 'series' => $series];
    }
}
