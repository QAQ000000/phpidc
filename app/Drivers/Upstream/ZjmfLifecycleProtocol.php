<?php

declare(strict_types=1);

namespace App\Drivers\Upstream;

use App\Data\ProvisionResult;
use App\Data\QuoteResult;
use App\Enums\BillingCycle;
use App\Models\Instance;
use App\Models\UpstreamApi;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Facades\Cache;

class ZjmfLifecycleProtocol
{
    public function __construct(private readonly ZjmfClient $client, private readonly UpstreamApi $account) {}

    public function quoteRenewal(Instance $instance, string $cycle): QuoteResult
    {
        if (! BillingCycle::from($cycle)->isRenewable() || $instance->upstream_instance_id === null) {
            throw new DomainException('A renewable bound instance is required.');
        }
        $body = $this->client->request('GET', '/host/renewpage', ['hostid' => $instance->upstream_instance_id, 'billingcycles' => $cycle], ['instance_id' => $instance->id]);
        $data = $body['data'] ?? [];
        if (($body['status'] ?? null) !== 200 || ($data['currency']['code'] ?? null) !== $instance->currency
            || ($data['host']['billingcycle'] ?? null) !== $cycle || ! isset($data['host']['amount'])) {
            throw new DomainException('Matching renewal cycle, currency and amount are required.');
        }
        $cost = Money::of($data['host']['amount']);
        $price = $instance->is_price_locked ? $instance->recurring_amount : $this->marked($instance, $cost);

        return new QuoteResult($cost, $price, currency: $instance->currency);
    }

    public function quoteUpgrade(Instance $instance, array $target): QuoteResult
    {
        $lock = Cache::lock('zjmf:config:'.$this->account->id.':'.$instance->upstream_instance_id, 210);
        if (! $lock->get()) {
            throw new DomainException('Upstream configuration session is busy.');
        }
        try {
            return $this->prepare($instance, $target);
        } finally {
            $lock->release();
        }
    }

    private function prepare(Instance $instance, array $target): QuoteResult
    {
        $params = ['hid' => $instance->upstream_instance_id, 'configoption' => $target['changes'] ?? $target['configoption'] ?? []];
        $body = $this->client->request('POST', '/upgrade/upgrade_config_post', $params, ['instance_id' => $instance->id]);
        if (($body['status'] ?? null) !== 200) {
            throw new DomainException('Upstream did not accept the upgrade selection.');
        }
        $body = $this->client->request('GET', '/upgrade/upgrade_config_page', ['hid' => $instance->upstream_instance_id], ['instance_id' => $instance->id]);
        $data = $body['data'] ?? [];
        if (($body['status'] ?? null) !== 200 || ($data['currency']['code'] ?? null) !== $instance->currency || ! isset($data['total'])
            || str_starts_with((string) $data['total'], '-')) {
            throw new DomainException('A nonnegative matching-currency upgrade quote is required.');
        }
        $cost = Money::of($data['total']);

        return new QuoteResult($cost, $this->marked($instance, $cost), currency: $instance->currency);
    }

    public function createUpgrade(Instance $instance, array $target): ProvisionResult
    {
        $lock = Cache::lock('zjmf:config:'.$this->account->id.':'.$instance->upstream_instance_id, 210);
        if (! $lock->get()) {
            return ProvisionResult::fail('Configuration session busy; no request sent.', retryable: true, rawResponse: ['not_sent' => true]);
        }
        try {
            $quote = $this->prepare($instance, $target);
            if (! isset($target['expected_cost']) || ! Money::eq($quote->upstreamBaseCost, $target['expected_cost'])) {
                return ProvisionResult::fail('Upgrade cost changed before checkout; operator review required.');
            }
            $body = $this->client->request('POST', '/upgrade/checkout_config_upgrade', ['hid' => $instance->upstream_instance_id], ['instance_id' => $instance->id, 'invoice_id' => $target['invoice_id'] ?? null]);
            if (($body['status'] ?? null) === 200 && isset($body['data']['invoiceid'])) {
                return ProvisionResult::ok(rawResponse: ['invoice_id' => (string) $body['data']['invoiceid']]);
            }
            if (($body['status'] ?? null) === 1001 && Money::isZero($quote->upstreamBaseCost)) {
                return ProvisionResult::ok(rawResponse: ['zero_paid' => true]);
            }

            return ProvisionResult::fail('Upgrade checkout outcome unresolved; reconcile manually.');
        } finally {
            $lock->release();
        }
    }

    public function configurationMatches(Instance $instance, array $target): bool
    {
        $body = $this->client->request('GET', '/upgrade/index/'.$instance->upstream_instance_id, [], ['instance_id' => $instance->id]);
        if (($body['status'] ?? null) !== 200 || ! is_array($body['data']['host'] ?? null)) {
            return false;
        }
        $actual = [];
        foreach ($body['data']['host'] as $row) {
            $quantity = in_array((int) ($row['option_type'] ?? 0), [4, 7, 9, 11, 14, 15, 16, 17, 18, 19], true);
            $actual[(int) $row['oid']] = (int) ($quantity ? $row['qty'] : $row['subid']);
        }
        foreach ($target['changes'] ?? $target['configoption'] ?? [] as $key => $value) {
            if (! isset($actual[$key]) || $actual[$key] !== (int) $value) {
                return false;
            }
        }

        return $actual !== [];
    }

    private function marked(Instance $instance, string $cost): string
    {
        return Money::add($cost, Money::percentOf($cost, $instance->product->markup_percent));
    }
}
