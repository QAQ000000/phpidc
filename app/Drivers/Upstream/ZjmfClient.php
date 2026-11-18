<?php

declare(strict_types=1);

namespace App\Drivers\Upstream;

use App\Enums\ProvisionCommand;
use App\Models\UpstreamApi;
use App\Services\UpstreamOperationBuffer;
use GuzzleHttp\Promise\EachPromise;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class ZjmfClient
{
    private string $correlation;

    public function __construct(private readonly UpstreamApi $account, private readonly UpstreamOperationBuffer $operations)
    {
        $this->correlation = (string) Str::uuid();
    }

    public function request(string $method, string $path, array $params = [], array $context = [], int $timeout = 30): array
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $jwt = $this->token($context, $timeout);
            if ($jwt === null) {
                return $this->authenticationFailure();
            }
            $result = $this->send($method, $path, $params + ['is_api' => 1], $jwt, $context, $timeout);
            if (($result['status'] ?? null) !== 405) {
                return $result;
            }
            Cache::forget($this->tokenKey());
        }

        return ['status' => 405, 'msg' => 'Upstream authentication failed after refresh.'];
    }

    /** Independent authenticated reads only; cart creation, payment and machine mutations stay sequential. */
    public function readBatch(array $requests, array $context = []): array
    {
        foreach ($requests as $request) {
            $method = $request['method'] ?? null;
            $path = $request['path'] ?? '';
            $read = $method === 'GET' && (in_array($path, ['/user_info', '/cart/stock_control'], true)
                || preg_match('~^/provision/chart/[0-9]+$~D', $path) === 1);
            $status = $method === 'POST' && $path === '/provision/default'
                && ($request['params']['func'] ?? null) === ProvisionCommand::STATUS->value;
            if (! $read && ! $status) {
                throw new InvalidArgumentException('Only independent upstream reads may run concurrently.');
            }
        }
        $results = [];
        $pending = $requests;
        for ($attempt = 0; $pending !== [] && $attempt < 2; $attempt++) {
            $jwt = $this->token($context, 30);
            if ($jwt === null) {
                foreach ($pending as $key => $request) {
                    $results[$key] = $this->authenticationFailure();
                }
                break;
            }
            $responses = $this->sendReadBatch($pending, $jwt, $context);
            $pending = [];
            foreach ($responses as $key => $result) {
                if (($result['status'] ?? null) === 405) {
                    Cache::forget($this->tokenKey());
                    if ($attempt === 0) {
                        $pending[$key] = $requests[$key];

                        continue;
                    }
                }
                $results[$key] = $result;
            }
        }

        $ordered = [];
        foreach ($requests as $key => $request) {
            $ordered[$key] = $results[$key];
        }

        return $ordered;
    }

    private function tokenKey(): string
    {
        return 'zjmf:jwt:'.hash('sha256', $this->account->id.'|'.$this->account->base_url.'|'.$this->account->username.'|'.$this->account->encrypted_secret);
    }

    private function token(array $context, int $timeout): ?string
    {
        $cached = Cache::get($this->tokenKey());
        if (is_string($cached)) {
            return Crypt::decryptString($cached);
        }
        $login = $this->send('POST', '/zjmf_api_login', ['username' => $this->account->username, 'password' => $this->account->encrypted_secret], null, $context, $timeout);
        if (($login['status'] ?? null) !== 200 || ! is_string($login['jwt'] ?? null) || $login['jwt'] === '') {
            return null;
        }
        Cache::put($this->tokenKey(), Crypt::encryptString($login['jwt']), 6900);

        return $login['jwt'];
    }

    private function authenticationFailure(): array
    {
        return ['status' => 400, 'msg' => 'Upstream authentication failed.', '_transport_failure' => true];
    }

    private function sendReadBatch(array $requests, string $jwt, array $context): array
    {
        // One handler is shared by all promises, so cURL can actually overlap their network waits.
        $pool = new Pool(Http::getFacadeRoot());
        $promises = function () use ($pool, $requests, $jwt, $context) {
            foreach ($requests as $key => $request) {
                $started = hrtime(true);
                $method = $request['method'];
                $path = $request['path'];
                $params = ($request['params'] ?? []) + ['is_api' => 1];
                $http = $pool->as((string) $key)->async()->asForm()->timeout(30)->withoutRedirecting()
                    ->withHeader('Authorization', $this->account->auth_header_mode === 'raw_jwt' ? $jwt : 'Bearer '.$jwt);
                $promise = $http->send($method, rtrim($this->account->base_url, '/').$path, [$method === 'GET' ? 'query' : 'form_params' => $params]);
                yield $key => $promise->buildPromise()->then(function (Response|Throwable $response) use ($method, $path, $params, $jwt, $context, $started) {
                    if ($response instanceof Throwable && ! $response instanceof ConnectionException) {
                        throw $response;
                    }

                    return $this->complete($method, $path, $params, $jwt, $context, $started, $response);
                });
            }
        };
        $results = [];
        $errors = [];
        (new EachPromise($promises(), [
            'concurrency' => 4,
            'fulfilled' => function (array $result, $key) use (&$results): void {
                $results[$key] = $result;
            },
            'rejected' => function (Throwable $error) use (&$errors): void {
                $errors[] = $error;
            },
        ]))->promise()->wait();
        if ($errors !== []) {
            throw $errors[0];
        }

        return $results;
    }

    private function send(string $method, string $path, array $params, ?string $jwt, array $context, int $timeout): array
    {
        $started = hrtime(true);
        try {
            $request = Http::asForm()->timeout($timeout)->withoutRedirecting();
            if ($jwt !== null) {
                $request = $request->withHeader('Authorization', $this->account->auth_header_mode === 'raw_jwt' ? $jwt : 'Bearer '.$jwt);
            }
            $response = $request->send($method, rtrim($this->account->base_url, '/').$path, [$method === 'GET' ? 'query' : 'form_params' => $params]);
        } catch (ConnectionException $error) {
            $response = $error;
        }

        return $this->complete($method, $path, $params, $jwt, $context, $started, $response);
    }

    private function complete(string $method, string $path, array $params, ?string $jwt, array $context, int $started, Response|ConnectionException $response): array
    {
        $httpStatus = $response instanceof Response ? $response->status() : null;
        $secrets = array_filter([$this->account->encrypted_secret, $jwt]);
        array_walk_recursive($params, function ($value, $key) use (&$secrets): void {
            if (is_string($value) && preg_match('/password|token|secret|customfield|^pass$|temp_pass/i', (string) $key)) {
                $secrets[] = $value;
            }
        });
        if ($response instanceof Response) {
            $body = $httpStatus === 200 ? json_decode(preg_replace('/("(?:total|subtotal|amount|amount_total|credit|price|sale_price|sale_setupfee_total|setupfee_total|[a-z]*setupfee|ontrialfee|onetime|hour|day|ontrial|monthly|quarterly|semiannually|annually|biennially|triennially|fourly|fively|sixly|sevenly|eightly|ninely|tenly)"\s*:\s*)(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)(?=\s*[,}])/', '$1"$2"', $response->body()), true) : null;
            $result = is_array($body) ? $body : ['status' => 400, 'msg' => 'Invalid upstream HTTP response.', '_transport_failure' => true];
            $secrets[] = is_string($result['jwt'] ?? null) ? $result['jwt'] : null;
        } else {
            $result = ['status' => 400, 'msg' => 'Upstream connection failed.', '_transport_failure' => true];
        }
        $outcome = in_array($result['status'] ?? null, [200, 1001, 'success'], true) ? 'ok' : 'failed';
        if (in_array($path, ['/cart/settle', '/apply_credit', '/apply_credit_limit', '/host/renew', '/host/cancel', '/upgrade/checkout_config_upgrade', '/provision/default'], true)
            && ! ($path === '/provision/default' && ($params['func'] ?? null) === ProvisionCommand::STATUS->value) && ! empty($result['_transport_failure'])) {
            $outcome = 'ambiguous';
        }
        if ($path === '/apply_credit' && ($params['use_credit'] ?? null) === 0 && $outcome === 'ok') {
            $outcome = 'rolled_back';
        }
        $this->operations->append(['upstream_api_id' => $this->account->id, 'instance_id' => $context['instance_id'] ?? null,
            'invoice_id' => $context['invoice_id'] ?? null, 'method' => $method, 'path' => $path, 'http_status' => $httpStatus,
            'request_payload' => json_encode($this->redact($params, $secrets), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            'response_body' => mb_strcut(json_encode($this->redact($path === '/provision/default' && ($params['func'] ?? null) === 'vnc'
                ? array_replace($result, ['data' => '[REDACTED VNC connection]']) : $result, $secrets), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), 0, 16384, 'UTF-8'),
            'duration_ms' => (int) ((hrtime(true) - $started) / 1000000), 'correlation_key' => $this->correlation,
            'outcome' => $outcome]);

        return $result;
    }

    private function redact(mixed $value, array $secrets): mixed
    {
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $key => $item) {
                $safe[$key] = preg_match('/password|token|secret|jwt|authorization|ippassword|customfield|^html$|^pass$|temp_pass/i', (string) $key) ? '[REDACTED]' : $this->redact($item, $secrets);
            }

            return $safe;
        }

        return is_string($value) ? str_replace(array_values(array_filter($secrets)), '[REDACTED]', $value) : $value;
    }
}
