<?php

declare(strict_types=1);

namespace App\Drivers\SelfHosted;

use App\Models\UpstreamApi;
use App\Services\UpstreamOperationBuffer;
use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class Sub2ApiClient
{
    public function __construct(private readonly UpstreamApi $account, private readonly UpstreamOperationBuffer $operations) {}

    public function baseUrl(): string
    {
        $url = preg_replace('#/api/v1$#', '', rtrim($this->account->base_url, '/'));
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['https', 'http'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new DomainException('Invalid Sub2API base URL.');
        }

        return $url;
    }

    public function request(string $method, string $path, array $params = [], ?string $idempotency = null, array $context = []): array
    {
        $started = hrtime(true);
        $status = null;
        $body = null;
        try {
            $request = Http::asJson()->acceptJson()->withUserAgent('Laravel13idc-Sub2API-Integration/1.0')
                ->withHeader('x-api-key', $this->account->encrypted_secret)->connectTimeout(8)->timeout(30)->withoutRedirecting();
            if ($idempotency !== null) {
                $request = $request->withHeader('Idempotency-Key', $idempotency);
            }
            $response = $request->send($method, $this->baseUrl().$path, [$method === 'GET' ? 'query' : 'json' => $params]);
            $status = $response->status();
            // Preserve upstream numeric face values as strings; never parse money through a PHP float.
            $body = json_decode(preg_replace('/("value"\s*:\s*)(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)(?=\s*[,}])/', '$1"$2"', $response->body()), true);
        } catch (ConnectionException) {
            // Connection errors are ambiguous; exception text may contain credentials, so return a fixed diagnostic.
        }
        $ok = $status !== null && $status >= 200 && $status < 300 && is_array($body) && ($body['code'] ?? null) === 0;
        $rejected = in_array($status, [400, 401, 403, 404, 422], true) && is_array($body) && is_int($body['code'] ?? null) && $body['code'] !== 0;
        // Allow-list response diagnostics: never persist upstream strings, which may echo keys or issued cards.
        $this->operations->append(['upstream_api_id' => $this->account->id, 'instance_id' => $context['instance_id'] ?? null,
            'invoice_id' => $context['invoice_id'] ?? null, 'method' => $method, 'path' => $path, 'http_status' => $status,
            'request_payload' => json_encode($params, JSON_THROW_ON_ERROR),
            'response_body' => json_encode(['business_code' => is_int($body['code'] ?? null) ? $body['code'] : null,
                'data_is_array' => is_array($body['data'] ?? null), 'data_count' => is_array($body['data'] ?? null) ? count($body['data']) : null,
                'content' => '[REDACTED]'], JSON_THROW_ON_ERROR),
            'duration_ms' => (int) ((hrtime(true) - $started) / 1000000), 'correlation_key' => $idempotency ?? (string) Str::uuid(),
            'outcome' => $ok ? 'ok' : ($rejected ? 'failed' : 'ambiguous')]);

        return ['ok' => $ok, 'rejected' => $rejected, 'http_status' => $status, 'body' => is_array($body) ? $body : []];
    }
}
