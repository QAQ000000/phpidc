<?php

declare(strict_types=1);

namespace App\Drivers\Upstream;

use App\Models\UpstreamApi;
use App\Services\UpstreamOperationBuffer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ZjmfClient
{
    private string $correlation;

    public function __construct(private readonly UpstreamApi $account, private readonly UpstreamOperationBuffer $operations)
    {
        $this->correlation = (string) Str::uuid();
    }

    public function request(string $method, string $path, array $params = [], array $context = [], int $timeout = 30): array
    {
        $cacheKey = 'zjmf:jwt:'.hash('sha256', $this->account->id.'|'.$this->account->base_url.'|'.$this->account->username.'|'.$this->account->encrypted_secret);
        $cached = Cache::get($cacheKey);
        $jwt = is_string($cached) ? Crypt::decryptString($cached) : null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            if ($jwt === null) {
                $login = $this->send('POST', '/zjmf_api_login', ['username' => $this->account->username, 'password' => $this->account->encrypted_secret], null, $context, $timeout);
                if (($login['status'] ?? null) !== 200 || ! is_string($login['jwt'] ?? null) || $login['jwt'] === '') {
                    return ['status' => 400, 'msg' => 'Upstream authentication failed.', '_transport_failure' => true];
                }
                $jwt = $login['jwt'];
                Cache::put($cacheKey, Crypt::encryptString($jwt), 6900);
            }
            $result = $this->send($method, $path, $params + ['is_api' => 1], $jwt, $context, $timeout);
            if (($result['status'] ?? null) !== 405) {
                return $result;
            }
            Cache::forget($cacheKey);
            $jwt = null;
        }

        return ['status' => 405, 'msg' => 'Upstream authentication failed after refresh.'];
    }

    private function send(string $method, string $path, array $params, ?string $jwt, array $context, int $timeout): array
    {
        $started = hrtime(true);
        $httpStatus = null;
        $secrets = array_filter([$this->account->encrypted_secret, $jwt]);
        array_walk_recursive($params, function ($value, $key) use (&$secrets): void {
            if (is_string($value) && preg_match('/password|token|secret|customfield/i', (string) $key)) {
                $secrets[] = $value;
            }
        });
        try {
            $request = Http::asForm()->timeout($timeout)->withoutRedirecting();
            if ($jwt !== null) {
                $request = $request->withHeader('Authorization', $this->account->auth_header_mode === 'raw_jwt' ? $jwt : 'Bearer '.$jwt);
            }
            $response = $request->send($method, rtrim($this->account->base_url, '/').$path, [$method === 'GET' ? 'query' : 'form_params' => $params]);
            $httpStatus = $response->status();
            $body = $httpStatus === 200 ? json_decode(preg_replace('/("(?:total|subtotal|amount|credit|sale_price|sale_setupfee_total|setupfee_total)"\s*:\s*)(\d+(?:\.\d+)?)(?=\s*[,}])/', '$1"$2"', $response->body()), true) : null;
            $result = is_array($body) ? $body : ['status' => 400, 'msg' => 'Invalid upstream HTTP response.', '_transport_failure' => true];
            $secrets[] = is_string($result['jwt'] ?? null) ? $result['jwt'] : null;
        } catch (ConnectionException) {
            $result = ['status' => 400, 'msg' => 'Upstream connection failed.', '_transport_failure' => true];
        }
        $outcome = in_array($result['status'] ?? null, [200, 1001, 'success'], true) ? 'ok' : 'failed';
        if (in_array($path, ['/cart/settle', '/apply_credit', '/apply_credit_limit'], true) && ! empty($result['_transport_failure'])) {
            $outcome = 'ambiguous';
        }
        if ($path === '/apply_credit' && ($params['use_credit'] ?? null) === 0 && $outcome === 'ok') {
            $outcome = 'rolled_back';
        }
        $this->operations->append(['upstream_api_id' => $this->account->id, 'instance_id' => $context['instance_id'] ?? null,
            'invoice_id' => $context['invoice_id'] ?? null, 'method' => $method, 'path' => $path, 'http_status' => $httpStatus,
            'request_payload' => json_encode($this->redact($params, $secrets), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            'response_body' => mb_strcut(json_encode($this->redact($result, $secrets), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), 0, 16384, 'UTF-8'),
            'duration_ms' => (int) ((hrtime(true) - $started) / 1000000), 'correlation_key' => $this->correlation,
            'outcome' => $outcome]);

        return $result;
    }

    private function redact(mixed $value, array $secrets): mixed
    {
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $key => $item) {
                $safe[$key] = preg_match('/password|token|secret|jwt|authorization|ippassword|customfield/i', (string) $key) ? '[REDACTED]' : $this->redact($item, $secrets);
            }

            return $safe;
        }

        return is_string($value) ? str_replace(array_values(array_filter($secrets)), '[REDACTED]', $value) : $value;
    }
}
