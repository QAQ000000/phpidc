<?php

declare(strict_types=1);

namespace App\Drivers\Upstream;

use App\Data\ProvisionResult;
use App\Data\QuoteResult;
use App\Enums\BillingCycle;
use App\Models\Instance;
use App\Models\Product;
use App\Models\UpstreamApi;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class ZjmfPurchaseProtocol
{
    public function __construct(private readonly ZjmfClient $client, private readonly UpstreamApi $account) {}

    public function configuration(Product $product, ?string $pid = null): array
    {
        $response = $this->client->request('GET', '/cart/get_product_config', ['pid' => $pid ?? $product->upstream_pid]);
        if (($response['status'] ?? null) !== 200 || ! is_array($response['data']['products'] ?? null)) {
            throw ValidationException::withMessages(['product_id' => 'Upstream product configuration is unavailable.']);
        }

        return $response['data'];
    }

    public function validate(Product $product, array $params, ?string $pid = null): array
    {
        $configuration = $this->configuration($product, $pid);
        $remote = $configuration['products'];
        $password = $remote['password'] ?? null;
        $password = is_string($password) ? json_decode($password, true) : $password;
        if (! is_array($password) || ! array_key_exists('show', $password)) {
            throw ValidationException::withMessages(['password' => 'Upstream password rules are unavailable.']);
        }
        if ($password['show']) {
            $value = $params['password'] ?? '';
            if (strlen($value) < 6 || strlen($value) > 20 || ! is_array($password['rule'] ?? null)) {
                throw ValidationException::withMessages(['password' => 'Upstream password requires 6–20 bytes and known character rules.']);
            }
            foreach (['upper' => '/[A-Z]/', 'lower' => '/[a-z]/', 'num' => '/[0-9]/', 'special' => '/[|!@#$%&*\[\](){},;.:\/=?_+~^\\\\-]/'] as $key => $pattern) {
                if (! array_key_exists($key, $password['rule']) || ($password['rule'][$key] && ! preg_match($pattern, $value))) {
                    throw ValidationException::withMessages(['password' => 'Password does not satisfy the upstream character rules.']);
                }
            }
        }
        $host = $remote['host'] ?? null;
        $host = is_string($host) ? json_decode($host, true) : $host;
        if (is_array($host) && ($host['show'] ?? false) && strlen($params['hostname'] ?? '') < (int) ($host['rule']['len_num'] ?? 1)) {
            throw ValidationException::withMessages(['hostname' => 'Hostname does not satisfy the upstream length rule.']);
        }

        return $params;
    }

    public function quote(Product $product, string $cycle, array $options, int $qty): QuoteResult
    {
        BillingCycle::from($cycle);
        if ($qty !== 1 || $product->upstream_pid === null || ! ($product->upstream_base_prices[$cycle]['enabled'] ?? false)) {
            throw ValidationException::withMessages(['product_id' => 'A mapped upstream product, enabled cycle and quantity one are required.']);
        }
        $options = validator($options, ['configoption' => ['sometimes', 'array'], 'configoption.*' => ['integer', 'min:0'],
            'customfield' => ['sometimes', 'array'], 'customfield.*' => ['string', 'max:4096']])->validate();
        $user = $this->client->request('GET', '/user_info');
        $currencyId = $user['user']['currency'] ?? $user['data']['user']['currency'] ?? null;
        $stock = $this->client->request('GET', '/cart/stock_control', ['pid' => $product->upstream_pid]);
        $stock = ($stock['status'] ?? null) === 200 ? ($stock['data']['product'] ?? null) : null;
        if (($user['status'] ?? null) !== 200 || $currencyId === null || ! is_array($stock) || ! array_key_exists('hidden', $stock)
            || ! array_key_exists('stock_control', $stock) || $stock['hidden'] || ($stock['stock_control'] && (int) ($stock['qty'] ?? 0) < 1)) {
            throw ValidationException::withMessages(['product_id' => 'Upstream product is unavailable or out of stock.']);
        }
        $result = $this->client->request('POST', '/cart/get_total', ['pid' => $product->upstream_pid, 'billingcycle' => $cycle,
            'configoption' => $options['configoption'] ?? [], 'customfield' => $options['customfield'] ?? [], 'currencyid' => $currencyId, 'qty' => 1]);
        // Classic source returns products/currency at the top level; some integrations wrap data.
        $body = $result['data'] ?? $result;
        $pricing = $body['products'] ?? [];
        if (($result['status'] ?? null) !== 200 || ($body['currency']['code'] ?? null) !== $product->currency) {
            throw ValidationException::withMessages(['currency' => 'A matching-currency upstream quote is required.']);
        }
        $total = $pricing['total'] ?? null;
        $setup = $pricing['setupfee_total'] ?? null;
        if (! empty($pricing['type'])) {
            $total = $pricing['sale_price'] ?? null;
            $setup = $pricing['sale_setupfee_total'] ?? null;
        }
        if ((! is_string($total) && ! is_int($total)) || (! is_string($setup) && ! is_int($setup))) {
            throw ValidationException::withMessages(['product_id' => 'Explicit upstream period and setup amounts are required.']);
        }
        $total = Money::of($total);
        $setup = Money::of($setup);
        if (empty($pricing['type']) && Money::gt($setup, $total)) {
            throw ValidationException::withMessages(['product_id' => 'Upstream setup fee exceeds its total.']);
        }
        // Non-sale total includes setup; sale_price excludes sale_setupfee_total (C19).
        $period = empty($pricing['type']) ? Money::sub($total, $setup) : $total;
        $cost = Money::add($period, $setup);
        $price = Money::add($period, Money::percentOf($period, $product->markup_percent));
        $setupPrice = Money::add($setup, Money::percentOf($setup, $product->markup_percent));

        return new QuoteResult(upstreamBaseCost: $cost, downstreamPrice: $price, setupFee: $setupPrice, currency: $product->currency,
            breakdown: ['currency_id' => $currencyId, 'options' => $options]);
    }

    public function provision(Instance $instance, array $params): ProvisionResult
    {
        $lock = Cache::lock('zjmf:cart:'.$this->account->id, 210);
        if (! $lock->get()) {
            return ProvisionResult::fail('Upstream account cart is busy.', retryable: true);
        }
        try {
            return $this->purchase($instance, $params);
        } finally {
            $lock->release();
        }
    }

    private function purchase(Instance $instance, array $params): ProvisionResult
    {
        $context = ['instance_id' => $instance->id, 'invoice_id' => $params['invoice_id'] ?? null];
        $downstream = ['downstream_url' => rtrim(config('app.url'), '/'), 'downstream_token' => $instance->downstream_token,
            'downstream_id' => $instance->id];
        if (strlen($instance->downstream_token ?? '') !== 32 || ! isset($params['expected_cost'], $params['upstream_pid'])) {
            return ProvisionResult::fail('Persisted purchase context is incomplete.');
        }
        $user = $this->client->request('GET', '/user_info', [], $context);
        $currencyId = $user['user']['currency'] ?? $user['data']['user']['currency'] ?? null;
        if (($user['status'] ?? null) !== 200 || $currencyId === null) {
            return ProvisionResult::fail('Cannot resolve upstream purchase currency.', retryable: true);
        }
        $probe = $this->client->request('POST', '/cart/clear', $downstream, $context);
        if (($probe['status'] ?? null) === 400 && ($probe['domainstatus'] ?? null) === 'Active' && isset($probe['hostid'])) {
            return ProvisionResult::ok(upstreamInstanceId: (string) $probe['hostid'], rawResponse: $probe);
        }
        if (($probe['status'] ?? null) !== 200) {
            return ProvisionResult::fail('Upstream idempotency probe did not authorize a new order.', retryable: true, rawResponse: $probe);
        }
        $invoiceId = $probe['invoiceid'] ?? null;
        if ($invoiceId === null) {
            // Re-check current rules before P3. The original encrypted inputs survive retries.
            $product = $instance->product()->firstOrFail();
            $params = $this->validate($product, $params, (string) $params['upstream_pid']);
            $options = $params['options'] ?? [];
            $cart = ['pid' => $params['upstream_pid'], 'billingcycle' => $instance->billing_cycle->value,
                'host' => $params['hostname'] ?? '', 'password' => $params['password'] ?? '',
                'currencyid' => $currencyId, 'currecncyid' => $currencyId,
                'qty' => 1, 'configoption' => $options['configoption'] ?? [], 'customfield' => $options['customfield'] ?? []];
            $add = $this->client->request('POST', '/cart/add_to_shop', $cart, $context);
            if (($add['status'] ?? null) !== 200) {
                return ProvisionResult::fail('Upstream cart addition failed.', retryable: true, rawResponse: $add);
            }
            $cart['configoptions'] = $cart['configoption'];
            unset($cart['configoption']);
            $settled = $this->client->request('POST', '/cart/settle', $downstream + ['cart_data' => $cart], $context);
            if (($settled['status'] ?? null) === 1001 && isset($settled['data']['hostid'][0])) {
                return $this->confirmedHost((string) $settled['data']['hostid'][0], $settled, $context);
            }
            if (($settled['status'] ?? null) !== 200 || ! isset($settled['data']['invoiceid'])) {
                return ProvisionResult::fail('Ambiguous settlement; recover only through the idempotency probe.', retryable: true, rawResponse: $settled);
            }
            $invoiceId = $settled['data']['invoiceid'];
        }
        $bill = $this->client->request('GET', '/use_credit_page', ['invoiceid' => $invoiceId], $context);
        if (($bill['status'] ?? null) !== 200 || ! isset($bill['data']['total'])
            || ($bill['data']['currency']['code'] ?? null) !== $instance->currency
            || ! Money::eq(Money::of($bill['data']['total']), $params['expected_cost'])) {
            return ProvisionResult::fail('Upstream invoice amount or currency differs from the authorized cost.');
        }
        $pay = $this->client->request('POST', '/apply_credit', $downstream + ['invoiceid' => $invoiceId, 'use_credit' => 1, 'enough' => 1], $context);
        if (($pay['status'] ?? null) === 1001 && isset($pay['data']['hostid'][0])) {
            return $this->confirmedHost((string) $pay['data']['hostid'][0], $pay, $context);
        }
        // An unknown payment result is reconciled by P2, never by a second payment method.
        if (($pay['status'] ?? null) !== 400 || ! empty($pay['_transport_failure']) || ! ($this->account->config['allow_credit_limit'] ?? false)) {
            return ProvisionResult::fail('Upstream payment incomplete; reconcile before retrying.', retryable: true, rawResponse: $pay);
        }
        $limit = $this->client->request('POST', '/apply_credit_limit', $downstream + ['invoiceid' => $invoiceId, 'use_credit_limit' => 1, 'enough' => 0], $context);
        if (($limit['status'] ?? null) === 1001 && isset($limit['data']['hostid'][0])) {
            return $this->confirmedHost((string) $limit['data']['hostid'][0], $limit, $context);
        }
        if (! empty($limit['_transport_failure'])) {
            return ProvisionResult::fail('Ambiguous credit-limit payment; reconcile through P2.', retryable: true, rawResponse: $limit);
        }
        $rollback = $this->client->request('POST', '/apply_credit', $downstream + ['invoiceid' => $invoiceId, 'use_credit' => 0], $context);

        return ProvisionResult::fail(($rollback['status'] ?? null) === 200 ? 'Credit-limit failed; upstream credit application rolled back.' : 'Upstream rollback failed; operator intervention required.',
            retryable: ($rollback['status'] ?? null) === 200, rawResponse: $rollback);
    }

    private function confirmedHost(string $hostId, array $payment, array $context): ProvisionResult
    {
        $body = $this->client->request('GET', '/host/header', ['host_id' => $hostId, 'source' => 'API'], $context, 3);
        $host = $body['data']['host_data'] ?? [];
        if (($body['status'] ?? null) !== 200 || ($host['domainstatus'] ?? null) !== 'Active') {
            return ProvisionResult::fail('Upstream payment confirmed; wait for an Active host and recover through P2.', retryable: true, rawResponse: $payment);
        }

        return ProvisionResult::ok(upstreamInstanceId: $hostId, primaryIp: $host['dedicatedip'] ?? null,
            credentials: array_filter(['username' => $host['username'] ?? null, 'password' => $host['password'] ?? null], fn ($value) => $value !== null && $value !== ''),
            rawResponse: $payment);
    }
}
