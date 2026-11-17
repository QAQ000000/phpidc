<?php

declare(strict_types=1);

namespace App\Drivers\SelfHosted;

use App\Data\ProvisionResult;
use App\Models\Instance;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/** A remote issuer retains local price authority; it does not reserve from the local card pool. */
class Sub2ApiRemoteDriver extends RedemptionCodeDriver
{
    public function __construct(private readonly Sub2ApiClient $client) {}

    public function validateParams(Product $product, array $input): array
    {
        $params = parent::validateParams($product, $input);
        $amount = $product->provisioning_rules['sub2api_face_value'] ?? null;
        if (! is_string($amount) || ! preg_match('/^[1-9]\d{0,7}$/D', $amount)) {
            throw ValidationException::withMessages(['product_id' => 'A positive integer Sub2API face value (up to 99999999) is required.']);
        }

        return $params + ['sub2api_face_value' => Money::of($amount), 'sub2api_base_url' => $this->client->baseUrl()];
    }

    public function provision(Instance $instance, array $params): ProvisionResult
    {
        $intent = $params['remote_delivery'] ?? [];
        if (($intent['base_url'] ?? null) !== $this->client->baseUrl() || ! is_string($intent['idempotency_key'] ?? null)
            || ! is_string($intent['face_value'] ?? null) || ! preg_match('/^[1-9]\d{0,7}\.00$/', $intent['face_value'])) {
            return ProvisionResult::fail('Remote delivery requires a committed, matching request intent.');
        }
        $result = $this->client->request('POST', '/api/v1/admin/redeem-codes/generate',
            ['count' => 1, 'type' => 'balance', 'value' => (int) strstr($intent['face_value'], '.', true)], $intent['idempotency_key'],
            ['instance_id' => $instance->id, 'invoice_id' => $params['invoice_id'] ?? null]);
        $codes = $result['body']['data'] ?? null;
        if (! $result['ok'] || ! is_array($codes) || ! array_is_list($codes) || count($codes) !== 1) {
            return ProvisionResult::fail('Sub2API issuance was rejected or is uncertain; reconcile before any further issuance.', rawResponse: ['rejected' => $result['rejected']]);
        }

        return $this->validatedCode($codes[0], $intent['face_value'], false);
    }

    public function recover(Instance $instance, int $codeId, string $amount): ProvisionResult
    {
        $response = $this->client->request('GET', '/api/v1/admin/redeem-codes/'.$codeId, context: ['instance_id' => $instance->id]);
        if (! $response['ok'] || ! is_array($response['body']['data'] ?? null) || ($response['body']['data']['id'] ?? null) !== $codeId) {
            return ProvisionResult::fail('Cannot verify the selected upstream code.');
        }

        return $this->validatedCode($response['body']['data'], $amount, true);
    }

    private function validatedCode(array $code, string $amount, bool $allowUsed): ProvisionResult
    {
        $value = $code['value'] ?? null;
        if (! is_int($code['id'] ?? null) || $code['id'] < 1 || ! is_string($code['code'] ?? null) || trim($code['code']) === '' || strlen($code['code']) > 128
            || ($code['type'] ?? null) !== 'balance' || ! is_string($value) || ! preg_match('/^\d+(?:\.0+)?$/', $value)
            || ! Money::eq(Money::of($value), $amount) || ! in_array($code['status'] ?? null, $allowUsed ? ['unused', 'used'] : ['unused'], true)) {
            return ProvisionResult::fail('Sub2API code shape, amount or status mismatch; manual reconciliation required.');
        }

        return ProvisionResult::ok(upstreamInstanceId: (string) $code['id'], credentials: ['card_key' => $code['code'],
            'face_value' => $amount, 'redeem_url' => $this->client->baseUrl().'/redeem']);
    }
}
