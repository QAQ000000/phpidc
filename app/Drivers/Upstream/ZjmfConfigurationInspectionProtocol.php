<?php

declare(strict_types=1);

namespace App\Drivers\Upstream;

use App\Models\Instance;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

class ZjmfConfigurationInspectionProtocol
{
    public function __construct(private readonly ZjmfClient $client) {}

    public function inspect(Instance $instance, Product $product): array
    {
        $header = $this->client->request('GET', '/host/header', ['host_id' => $instance->upstream_instance_id, 'source' => 'API'], ['instance_id' => $instance->id]);
        $host = $header['data']['host_data'] ?? [];
        if (($header['status'] ?? null) !== 200 || ! is_array($host)
            || (string) ($host['id'] ?? '') !== $instance->upstream_instance_id
            || (string) ($host['productid'] ?? '') !== (string) $product->upstream_pid
            || ($host['billingcycle'] ?? null) !== $instance->billing_cycle->value
            || ($host['currency_code'] ?? $header['data']['currency']['code'] ?? null) !== 'CNY'
            || ! in_array($host['domainstatus'] ?? null, ['Active', 'Suspended'], true)) {
            $this->invalid('目标主机的归属、商品、周期、币种或状态无法认证。');
        }
        $response = $this->client->request('GET', '/cart/get_product_config', ['pid' => $product->upstream_pid]);
        $catalog = $response['data'] ?? [];
        if (($response['status'] ?? null) !== 200 || ! is_array($catalog['products'] ?? null)
            || (string) ($catalog['products']['id'] ?? '') !== (string) $product->upstream_pid
            || ! is_array($catalog['config_groups'] ?? null)) {
            $this->invalid('无法认证完整目标商品目录，请先核对并同步目录。');
        }
        $types = [];
        foreach ($catalog['config_groups'] as $container) {
            foreach ($container['options'] ?? [] as $option) {
                $id = $this->integer($option['id'] ?? null, 1);
                if (isset($types[$id])) {
                    $this->invalid('目标目录存在重复配置组。');
                }
                $types[$id] = $this->integer($option['option_type'] ?? null, 1);
            }
        }
        $actual = [];
        if ($types === []) {
            if (! array_key_exists('config_options', $header['data']) || $header['data']['config_options'] !== []) {
                $this->invalid('零配置商品也必须具有明确的空配置证据。');
            }
        } else {
            $response = $this->client->request('GET', '/upgrade/index/'.$instance->upstream_instance_id, [], ['instance_id' => $instance->id]);
            $data = $response['data'] ?? [];
            if (($response['status'] ?? null) !== 200 || (string) ($data['pid'] ?? '') !== (string) $product->upstream_pid
                || ($data['currency']['code'] ?? null) !== 'CNY' || ! is_array($data['host'] ?? null)) {
                $this->invalid('供应商未提供可认证的实际配置 ID；不能依据展示名称猜测映射。');
            }
            foreach (['host', 'host_config_options'] as $source) {
                $seen = [];
                foreach ($data[$source] ?? [] as $row) {
                    $id = $this->integer($row[$source === 'host' ? 'oid' : 'configid'] ?? null, 1);
                    if (isset($seen[$id]) || ! isset($types[$id])) {
                        $this->invalid('实际配置包含重复或未知配置组。');
                    }
                    $seen[$id] = true;
                    $type = $types[$id];
                    if ($source === 'host' && $this->integer($row['option_type'] ?? null, 1) !== $type) {
                        $this->invalid('实际配置类型与目标目录不一致。');
                    }
                    $sub = $this->integer($row[$source === 'host' ? 'subid' : 'optionid'] ?? null, 0);
                    $qty = $this->integer($row['qty'] ?? null, 0);
                    // Classic renders disabled toggles as subid=0, but its raw row retains optionid.
                    if ($type === 3 && $qty === 0) {
                        $sub = 0;
                    }
                    $fact = ['group_id' => $id, 'sub_id' => $sub, 'qty' => $qty, 'option_type' => $type];
                    if (isset($actual[$id]) && $actual[$id] !== $fact) {
                        $this->invalid('供应商的两组实际配置证据相互矛盾。');
                    }
                    $actual[$id] = $fact;
                }
            }
            if (count($actual) !== count($types)) {
                $this->invalid('供应商仅返回部分实际配置，缺少不可升级或隐藏项目；需要补齐直接 ID 证据。');
            }
        }
        ksort($actual);
        $response = $this->client->request('GET', '/host/renewpage', ['hostid' => $instance->upstream_instance_id,
            'billingcycles' => $instance->billing_cycle->value], ['instance_id' => $instance->id]);
        $renewal = $response['data'] ?? [];
        if (($response['status'] ?? null) !== 200 || ($renewal['currency']['code'] ?? null) !== 'CNY'
            || ($renewal['host']['billingcycle'] ?? null) !== $instance->billing_cycle->value
            || ! isset($renewal['host']['amount']) || ! is_string($renewal['host']['amount']) && ! is_int($renewal['host']['amount'])) {
            $this->invalid('无法认证当前周期的续费成本。');
        }

        return ['host' => ['id' => $instance->upstream_instance_id, 'pid' => (string) $product->upstream_pid,
            'status' => $host['domainstatus'], 'billing_cycle' => $host['billingcycle'], 'currency' => 'CNY'],
            'actual_options' => array_values($actual), 'catalog' => $catalog, 'renewal_cost' => Money::of($renewal['host']['amount'])];
    }

    private function integer(mixed $value, int $minimum): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum]]);
        if ($integer === false) {
            $this->invalid('供应商配置 ID 或数量不是可验证的整数。');
        }

        return $integer;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['mapping' => $message]);
    }
}
