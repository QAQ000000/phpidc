<?php

declare(strict_types=1);

namespace App\Drivers\Upstream;

use App\Contracts\FulfillmentDriver;
use App\Contracts\RenewableResource;
use App\Data\InstanceInfoResult;
use App\Data\ProvisionResult;
use App\Enums\ProvisionCommand;
use App\Models\Instance;
use App\Models\Product;
use App\Support\ProvisioningValidator;
use DomainException;
use Illuminate\Support\Carbon;

class ZjmfFinanceDriver implements FulfillmentDriver, RenewableResource
{
    public function __construct(private readonly ZjmfClient $client) {}

    public function validateParams(Product $product, array $input): array
    {
        return app(ProvisioningValidator::class)->validate($product, $input);
    }

    public function provision(Instance $instance, array $params): ProvisionResult
    {
        return ProvisionResult::fail('Classic purchase provisioning is not enabled yet.');
    }

    public function syncInfo(Instance $instance): InstanceInfoResult
    {
        $body = $this->client->request('GET', '/host/header', ['host_id' => $instance->upstream_instance_id, 'source' => 'API'], ['instance_id' => $instance->id], 3);
        $host = $body['data']['host_data'] ?? null;
        if (($body['status'] ?? null) !== 200 || ! is_array($host) || ! is_string($host['domainstatus'] ?? null)) {
            throw new DomainException('Upstream host snapshot is unavailable.');
        }
        $ips = $host['assignedips'] ?? [];
        if (is_string($ips)) {
            $ips = preg_split('/[\s,]+/', trim($ips), -1, PREG_SPLIT_NO_EMPTY);
        }
        $due = $host['nextduedate'] ?? null;
        $due = is_numeric($due) && (int) $due > 0 ? Carbon::createFromTimestampUTC((int) $due)->toDateTimeString() : (is_string($due) && $due !== '' && $due !== '0' ? $due : null);

        return new InstanceInfoResult(upstreamStatus: $host['domainstatus'], domainStatus: $host['domainstatus'],
            primaryIp: $host['dedicatedip'] ?? null, assignedIps: is_array($ips) ? $ips : [],
            username: $host['username'] ?? null, password: $host['password'] ?? null, port: isset($host['port']) ? (int) $host['port'] : null,
            os: $host['os'] ?? null, nextDueDate: $due, suspendReason: $host['suspendreason'] ?? null,
            customFields: $body['data']['custom_field_data'] ?? [],
            passwordRule: isset($host['password_rule']) ? json_encode($host['password_rule'], JSON_THROW_ON_ERROR) : null,
            allowUpgradeConfig: (bool) ($host['allow_upgrade_config'] ?? false), rawResponse: $body);
    }

    public function supportedCommands(Instance $instance): array
    {
        return array_map(fn (ProvisionCommand $command) => $command->value, ProvisionCommand::cases());
    }

    public function suspend(Instance $instance, string $reason = ''): ProvisionResult
    {
        return $this->changePowerState($instance, 'Suspended', ProvisionCommand::SUSPEND, $reason);
    }

    public function unsuspend(Instance $instance): ProvisionResult
    {
        return $this->changePowerState($instance, 'Active', ProvisionCommand::UNSUSPEND);
    }

    private function changePowerState(Instance $instance, string $target, ProvisionCommand $command, string $reason = ''): ProvisionResult
    {
        $snapshot = $this->syncInfo($instance);
        if (in_array($snapshot->upstreamStatus, [$target, 'Deleted'], true)) {
            return ProvisionResult::reconciled(rawResponse: $snapshot->rawResponse);
        }
        $body = $this->client->request('POST', '/provision/default', ['id' => $instance->upstream_instance_id,
            'func' => $command->value, 'reason' => $reason], ['instance_id' => $instance->id]);
        if (! in_array($body['status'] ?? null, ['success', 200], true)) {
            return ProvisionResult::fail('Upstream lifecycle operation failed.', retryable: true, rawResponse: $body);
        }
        $host = $snapshot->rawResponse['data']['host_data'];
        $host['domainstatus'] = $target;
        if ($reason !== '') {
            $host['suspendreason'] = $reason;
        }

        return ProvisionResult::ok(rawResponse: ['status' => 200, 'data' => ['host_data' => $host]]);
    }

    public function renew(Instance $instance, string $billingCycle): ProvisionResult
    {
        return ProvisionResult::fail('Renewal settlement is not enabled yet.');
    }

    public function terminate(Instance $instance, string $reason = ''): ProvisionResult
    {
        return ProvisionResult::fail('Upstream termination requires an explicit cancellation workflow.');
    }
}
