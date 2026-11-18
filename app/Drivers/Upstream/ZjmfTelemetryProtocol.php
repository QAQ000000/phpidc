<?php

declare(strict_types=1);

namespace App\Drivers\Upstream;

use App\Data\ProvisionResult;
use App\Enums\ProvisionCommand;
use App\Models\Instance;

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
        $widgets = [];
        if (($header['data']['module_power_status'] ?? false) === true) {
            $widgets[] = $this->powerStatus($instance)->rawResponse['machine_status_widget'];
        }
        // The upstream default date calculation is inconsistent. Always supply an explicit 24h window in milliseconds.
        $end = now()->getTimestamp() * 1000;
        foreach ($this->charts($header) as $chart) {
            $response = $this->client->request('GET', '/provision/chart/'.$instance->upstream_instance_id,
                ['type' => $chart['type'], 'start' => $end - 86400000, 'end' => $end], $context);
            $data = $this->chartData($response);
            $widgets[] = ['key' => $chart['type'], 'title' => $chart['title'], 'type' => 'chart', 'data' => $data];
        }

        return $widgets;
    }

    public function powerStatus(Instance $instance): ProvisionResult
    {
        $response = $this->client->request('POST', '/provision/default', ['id' => $instance->upstream_instance_id,
            'func' => ProvisionCommand::STATUS->value], ['instance_id' => $instance->id]);
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
