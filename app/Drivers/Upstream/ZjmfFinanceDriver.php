<?php

declare(strict_types=1);

namespace App\Drivers\Upstream;

use App\Contracts\BoundUpstreamEndpoint;
use App\Contracts\ConfigurableResource;
use App\Contracts\ConfigurationInspectable;
use App\Contracts\FulfillmentDriver;
use App\Contracts\HasCustomPanelWidgets;
use App\Contracts\LiveQuotable;
use App\Contracts\PowerControllable;
use App\Contracts\RenewableResource;
use App\Contracts\SyncableCatalog;
use App\Contracts\UpstreamAccount;
use App\Data\CatalogSnapshotResult;
use App\Data\InstanceInfoResult;
use App\Data\ProvisionResult;
use App\Data\QuoteResult;
use App\Enums\InstanceStatus;
use App\Enums\ProvisionCommand;
use App\Models\Instance;
use App\Models\Product;
use App\Models\UpstreamApi;
use App\Support\InstanceDueDate;
use App\Support\Money;
use App\Support\ProvisioningValidator;
use DomainException;
use Illuminate\Support\Carbon;

class ZjmfFinanceDriver implements BoundUpstreamEndpoint, ConfigurableResource, ConfigurationInspectable, FulfillmentDriver, HasCustomPanelWidgets, LiveQuotable, PowerControllable, RenewableResource, SyncableCatalog, UpstreamAccount
{
    public function __construct(private readonly ZjmfClient $client, private readonly UpstreamApi $account, private readonly array $panelWidgetTypes = []) {}

    public function endpointIdentity(): array
    {
        return ['upstream_api_id' => $this->account->id, 'base_url' => rtrim($this->account->base_url, '/'), 'protocol' => 'classic_zjmf'];
    }

    public function validateParams(Product $product, array $input): array
    {
        return (new ZjmfPurchaseProtocol($this->client, $this->account))->validate($product, app(ProvisioningValidator::class)->validate($product, $input));
    }

    public function provision(Instance $instance, array $params): ProvisionResult
    {
        return (new ZjmfPurchaseProtocol($this->client, $this->account))->provision($instance, $params);
    }

    public function productConfiguration(Product $product): array
    {
        return (new ZjmfPurchaseProtocol($this->client, $this->account))->configuration($product);
    }

    public function inspectConfiguration(Instance $instance, Product $product): array
    {
        if ($instance->upstream_api_id !== $this->account->id || $product->upstream_api_id !== $this->account->id) {
            throw new DomainException('Configuration inspection requires the authenticated current account.');
        }

        return (new ZjmfConfigurationInspectionProtocol($this->client))->inspect($instance, $product);
    }

    public function fetchPanelWidgets(Instance $instance): array
    {
        if ($instance->upstream_api_id !== $this->account->id || $instance->upstream_instance_id === null) {
            return [];
        }

        return (new ZjmfPanelProtocol($this->client))->widgets($instance, $this->panelWidgetTypes);
    }

    public function catalog(): array
    {
        $body = $this->client->request('GET', '/cart/all');
        if (($body['status'] ?? null) !== 200 || ! is_array($body['data']['products'] ?? null)) {
            throw new DomainException('Classic catalog is unavailable.');
        }

        return $body['data'];
    }

    public function quotePurchase(Product $product, string $cycle, array $options, int $qty = 1): QuoteResult
    {
        return (new ZjmfPurchaseProtocol($this->client, $this->account))->quote($product, $cycle, $options, $qty);
    }

    public function quoteUpgrade(Instance $instance, array $targetOptions): QuoteResult
    {
        return (new ZjmfLifecycleProtocol($this->client, $this->account))->quoteUpgrade($instance, $targetOptions);
    }

    public function quoteRenewal(Instance $instance, string $billingCycle): QuoteResult
    {
        return (new ZjmfLifecycleProtocol($this->client, $this->account))->quoteRenewal($instance, $billingCycle);
    }

    public function probeVersions(array $upstreamPids): array
    {
        $body = $this->client->request('GET', '/api/product/proinfo', ['pids' => $upstreamPids]);
        if (($body['status'] ?? null) !== 200 || ! is_array($body['data']['info'] ?? null)) {
            throw new DomainException('Catalog version probe is unavailable.');
        }

        return collect($body['data']['info'])->keyBy('id')->all();
    }

    public function syncProductConfig(Product $product): CatalogSnapshotResult
    {
        $data = $this->productConfiguration($product);

        return new CatalogSnapshotResult((string) $product->upstream_pid, (int) ($data['products']['location_version'] ?? 0),
            $data['products'], $data['product_pricings'] ?? [], $data['config_groups'] ?? [], $data['customfields'] ?? [], $data['advanced'] ?? []);
    }

    public function checkBalance(): array
    {
        $body = $this->client->request('GET', '/cart/credit');
        if (($body['status'] ?? null) !== 200 || ! isset($body['data']['credit'], $body['data']['currency'])) {
            throw new DomainException('Upstream balance is unavailable.');
        }

        return ['credit' => Money::of($body['data']['credit']), 'currency' => $body['data']['currency']];
    }

    public function registerDownstreamCallback(Instance $instance): bool
    {
        $body = $this->client->request('POST', '/host/setdownstream', ['id' => $instance->upstream_instance_id,
            'pid' => $instance->active_config['upstream_pid'] ?? $instance->product()->firstOrFail()->upstream_pid,
            'downstream_url' => rtrim(config('app.url'), '/'), 'downstream_token' => $instance->downstream_token,
            'downstream_id' => $instance->id], ['instance_id' => $instance->id]);

        return ($body['status'] ?? null) === 200;
    }

    public function syncInfo(Instance $instance): InstanceInfoResult
    {
        $body = $this->client->request('GET', '/host/header', ['host_id' => $instance->upstream_instance_id, 'source' => 'API'], ['instance_id' => $instance->id], 3);
        $host = $body['data']['host_data'] ?? null;
        if (($body['status'] ?? null) !== 200 || ! is_array($host) || ! is_string($host['domainstatus'] ?? null)
            || (isset($host['id']) && (string) $host['id'] !== $instance->upstream_instance_id)) {
            throw new DomainException('Upstream host snapshot is unavailable.');
        }
        $ips = $host['assignedips'] ?? [];
        if (is_string($ips)) {
            $ips = preg_split('/[\s,]+/', trim($ips), -1, PREG_SPLIT_NO_EMPTY);
        }
        $due = $host['nextduedate'] ?? null;
        $due = is_numeric($due)
            ? ((int) $due > 0 ? Carbon::createFromTimestampUTC((int) $due)->toIso8601String() : null)
            : (is_string($due) && trim($due) !== '' ? InstanceDueDate::parse($due)->toIso8601String() : null);

        return new InstanceInfoResult(upstreamStatus: $host['domainstatus'], domainStatus: $host['domainstatus'],
            primaryIp: $host['dedicatedip'] ?? null, assignedIps: is_array($ips) ? $ips : [],
            username: $host['username'] ?? null, password: $host['password'] ?? null, port: isset($host['port']) ? (int) $host['port'] : null,
            os: $host['os'] ?? null, nextDueDate: $due, suspendReason: $host['suspendreason'] ?? null,
            customFields: $body['data']['custom_field_data'] ?? [],
            passwordRule: isset($host['password_rule']) ? json_encode($host['password_rule'], JSON_THROW_ON_ERROR) : null,
            allowUpgradeConfig: (bool) ($host['allow_upgrade_config'] ?? false),
            panelWidgets: array_merge((new ZjmfPanelProtocol($this->client))->deliveryWidgets($instance, $body),
                (new ZjmfTelemetryProtocol($this->client))->capabilityWidgets($body)), rawResponse: $body);
    }

    public function supportedCommands(Instance $instance): array
    {
        return array_map(fn (ProvisionCommand $command) => $command->value, ProvisionCommand::cases());
    }

    public function executePowerCommand(Instance $instance, ProvisionCommand $command, array $payload = []): ProvisionResult
    {
        if ($instance->upstream_api_id !== $this->account->id || $instance->upstream_instance_id === null) {
            return ProvisionResult::fail('服务绑定不匹配。');
        }
        if ($command === ProvisionCommand::STATUS) {
            if ($instance->status !== InstanceStatus::ACTIVE || ($instance->active_config['upstream_panel']['machine_status'] ?? false) !== true) {
                return ProvisionResult::fail('此服务尚未提供机器电源状态查询。');
            }

            // Read-only: use the authenticated capability snapshot; status itself rechecks remote access.
            // Unlike mutations, this path does not load header, panel HTML, controls or historical charts.
            return (new ZjmfTelemetryProtocol($this->client))->powerStatus($instance);
        }

        return (new ZjmfCloudControlProtocol($this->client))->execute($instance, $command, $payload);
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
        // One durable stage: create the upstream invoice. The Action records its ID before payment.
        $body = $this->client->request('POST', '/host/renew', ['hostid' => $instance->upstream_instance_id, 'billingcycles' => $billingCycle], ['instance_id' => $instance->id]);

        return ($body['status'] ?? null) === 200 && isset($body['data']['invoiceid'])
            ? ProvisionResult::ok(rawResponse: ['invoice_id' => (string) $body['data']['invoiceid']])
            : ProvisionResult::fail('Renewal invoice result requires manual reconciliation.');
    }

    public function changeConfig(Instance $instance, array $targetOptions): ProvisionResult
    {
        return (new ZjmfLifecycleProtocol($this->client, $this->account))->createUpgrade($instance, $targetOptions);
    }

    public function operationBill(Instance $instance, string $invoiceId, int $localInvoiceId): array
    {
        return $this->client->request('GET', '/use_credit_page', ['invoiceid' => $invoiceId], ['instance_id' => $instance->id, 'invoice_id' => $localInvoiceId]);
    }

    public function payOperation(Instance $instance, string $invoiceId, int $localInvoiceId): ProvisionResult
    {
        $body = $this->client->request('POST', '/apply_credit', ['invoiceid' => $invoiceId, 'use_credit' => 1, 'enough' => 1], ['instance_id' => $instance->id, 'invoice_id' => $localInvoiceId]);

        return ($body['status'] ?? null) === 1001 ? ProvisionResult::ok() : ProvisionResult::fail('Payment result unresolved; only read-only reconciliation is allowed.', retryable: true);
    }

    public function configurationMatches(Instance $instance, array $target): bool
    {
        return (new ZjmfLifecycleProtocol($this->client, $this->account))->configurationMatches($instance, $target);
    }

    public function terminate(Instance $instance, string $reason = ''): ProvisionResult
    {
        $body = $this->client->request('POST', '/host/cancel', ['id' => $instance->upstream_instance_id, 'type' => 'Immediate', 'reason' => $reason], ['instance_id' => $instance->id]);

        // Acknowledgement is only a cancellation request, never confirmation of deletion (INV-R1).
        return ($body['status'] ?? null) === 200 ? ProvisionResult::ok(rawResponse: ['requested' => true])
            : ProvisionResult::fail('Cancellation request unresolved; reconcile manually.');
    }
}
