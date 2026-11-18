<?php

declare(strict_types=1);

namespace App\Drivers\Upstream;

use App\Data\ProvisionResult;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\ProvisionCommand;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Support\MachineOperation;
use Illuminate\Validation\ValidationException;

class ZjmfCloudControlProtocol
{
    public function __construct(private readonly ZjmfClient $client) {}

    public static function commands(): array
    {
        return [ProvisionCommand::POWER_ON, ProvisionCommand::POWER_OFF, ProvisionCommand::REBOOT,
            ProvisionCommand::HARD_OFF, ProvisionCommand::HARD_REBOOT, ProvisionCommand::REINSTALL,
            ProvisionCommand::RESCUE_SYSTEM, ProvisionCommand::VNC];
    }

    public static function wire(ProvisionCommand $command): string
    {
        // The frozen enum is a domain identifier. Classic's verified wire spelling differs.
        return $command === ProvisionCommand::RESCUE_SYSTEM ? 'rescue_system' : $command->value;
    }

    public function widgets(array $header): array
    {
        $data = $header['data'] ?? [];
        if (($header['status'] ?? null) !== 200 || ($data['host_data']['domainstatus'] ?? null) !== 'Active') {
            return [];
        }
        $advertised = array_merge($data['module_button']['control'] ?? [], $data['module_button']['console'] ?? []);
        $commands = [];
        foreach (self::commands() as $command) {
            if (collect($advertised)->contains(fn ($button) => is_array($button) && ($button['type'] ?? null) === 'default'
                && ($button['func'] ?? null) === self::wire($command))) {
                $commands[] = $command->value;
            }
        }
        if ($commands === []) {
            return [];
        }
        $groups = [];
        foreach ($data['cloud_os_group'] ?? [] as $group) {
            if (is_array($group) && is_string($group['id'] ?? null) && is_string($group['name'] ?? null)) {
                $groups[] = ['id' => $group['id'], 'name' => $group['name']];
            }
        }
        $systems = [];
        foreach ($data['cloud_os'] ?? [] as $os) {
            if (is_array($os) && ctype_digit((string) ($os['id'] ?? '')) && (int) $os['id'] > 0
                && is_string($os['name'] ?? null) && in_array($os['group'] ?? null, array_column($groups, 'id'), true)) {
                $systems[] = ['id' => (int) $os['id'], 'name' => $os['name'], 'group' => $os['group']];
            }
        }

        return [['key' => 'cloud_controls', 'title' => '机器管理', 'type' => 'cloud_controls', 'data' => [
            'commands' => $commands, 'groups' => $groups, 'systems' => $systems,
            'port' => (bool) ($data['reinstall_random_port'] ?? false),
            'format_data_disk' => (bool) ($data['reinstall_format_data_disk'] ?? false),
            'password_rule' => $data['host_data']['password_rule']['rule'] ?? null,
        ]]];
    }

    public function execute(Instance $instance, ProvisionCommand $command, array $input): ProvisionResult
    {
        if (! in_array($command, self::commands(), true)) {
            return ProvisionResult::fail('不支持此客户操作。');
        }
        $header = $this->client->request('GET', '/host/header', ['host_id' => $instance->upstream_instance_id, 'source' => 'API'], ['instance_id' => $instance->id]);
        $controls = $this->widgets($header)[0]['data'] ?? [];
        if (! in_array($command->value, $controls['commands'] ?? [], true)
            || (isset($header['data']['host_data']['id']) && (string) $header['data']['host_data']['id'] !== $instance->upstream_instance_id)) {
            return ProvisionResult::fail('上游状态或可用操作已变化，请刷新。');
        }
        $payload = [];
        if ($command === ProvisionCommand::REINSTALL) {
            $payload = validator($input, ['os_group' => ['required', 'string'], 'os' => ['required', 'integer', 'min:1'],
                'port' => ['sometimes', 'integer', 'between:1,65535'], 'format_data_disk' => ['sometimes', 'boolean']])->validate();
            if (! collect($controls['systems'])->contains(fn ($os) => $os['id'] === (int) $payload['os'] && $os['group'] === $payload['os_group'])
                || (isset($payload['port']) && ! $controls['port']) || (! empty($payload['format_data_disk']) && ! $controls['format_data_disk'])) {
                throw ValidationException::withMessages(['os' => '系统版本、端口或磁盘选项已失效，请重新选择。']);
            }
            unset($payload['os_group']);
            $payload['format_data_disk'] = (int) ($payload['format_data_disk'] ?? 0);
        }
        if ($command === ProvisionCommand::RESCUE_SYSTEM) {
            $payload = validator($input, ['system' => ['required', 'integer', 'in:1,2'], 'temp_pass' => ['required', 'string', 'min:6', 'max:20']])->validate();
            $rule = $controls['password_rule'];
            $password = $payload['temp_pass'];
            if (! is_array($rule) || strlen($password) < max(6, (int) ($rule['len_num'] ?? 6)) || strlen($password) > 20) {
                throw ValidationException::withMessages(['temp_pass' => '无法确认上游密码规则，或临时密码长度不符。']);
            }
            foreach (['upper' => '/[A-Z]/', 'lower' => '/[a-z]/', 'num' => '/[0-9]/', 'special' => '/[^A-Za-z0-9]/'] as $key => $pattern) {
                if (! array_key_exists($key, $rule) || ($rule[$key] && ! preg_match($pattern, $password))) {
                    throw ValidationException::withMessages(['temp_pass' => '临时密码不满足上游字符规则。']);
                }
            }
            $local = $instance->product->provisioning_rules ?? [];
            if (strlen($password) < ($local['password_min_len'] ?? 0) || strlen($password) > ($local['password_max_len'] ?? 20)
                || (isset($local['password_regex']) && ! preg_match('/'.str_replace('/', '\\/', $local['password_regex']).'/', $password))) {
                throw ValidationException::withMessages(['temp_pass' => '临时密码不满足商品密码规则。']);
            }
        }
        // Header I/O can race a local rebind. Revalidate immediately before submitting, without holding locks (INV-L3).
        $fresh = $instance->fresh();
        if ($fresh === null || $fresh->status !== InstanceStatus::ACTIVE || ! $fresh->upstreamApi?->is_active
            || isset($fresh->active_config['cancellation'])
            || ($fresh->active_config['cloud_operation']['request_id'] ?? null) !== ($instance->active_config['cloud_operation']['request_id'] ?? null)
            || isset($fresh->active_config['cloud_operation']['reconciliation'])
            || app(MachineOperation::class)->binding($fresh) !== app(MachineOperation::class)->binding($instance)
            || FulfillmentTask::where('instance_id', $instance->id)->whereIn('status', [FulfillmentTaskStatus::PENDING, FulfillmentTaskStatus::RUNNING, FulfillmentTaskStatus::FAILED])->exists()
            || $fresh->only(['user_id', 'driver_type', 'upstream_api_id', 'upstream_instance_id', 'downstream_token'])
                !== $instance->only(['user_id', 'driver_type', 'upstream_api_id', 'upstream_instance_id', 'downstream_token'])) {
            return ProvisionResult::fail('服务绑定或状态已变化，操作已拦截。');
        }
        $body = $this->client->request('POST', '/provision/default', ['id' => $instance->upstream_instance_id,
            'func' => self::wire($command)] + $payload, ['instance_id' => $instance->id]);
        if (! in_array($body['status'] ?? null, [200, 'success'], true)) {
            return ProvisionResult::fail(! empty($body['_transport_failure']) ? '上游结果不确定，请联系管理员核对，勿重复提交。' : '上游拒绝此操作，请核对后再试。',
                retryable: ! empty($body['_transport_failure']), rawResponse: $body);
        }

        return ProvisionResult::ok(rawResponse: $body);
    }
}
