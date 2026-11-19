<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/** Ephemeral permissions for one original request; Redis never owns money facts. */
class ReceivableCheckRequests
{
    public function intent(int $userId, string $action, string $requestId, array $intent): string
    {
        return hash_hmac('sha256', json_encode(Arr::sortRecursive(['algorithm' => $this->algorithm(), 'user_id' => $userId,
            'action' => $action, 'request_id' => $requestId, 'intent' => $intent]), JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function key(int $userId, string $action, string $requestId): string
    {
        return 'receivable-check:'.hash('sha256', json_encode([$this->keyVersion(), $userId, $action, $requestId], JSON_THROW_ON_ERROR));
    }

    public function get(int $userId, string $action, string $requestId, ?string $intentHmac = null): ?array
    {
        $value = $this->cache()->get($this->key($userId, $action, $requestId));
        if (! is_array($value) || ($value['algorithm'] ?? null) !== $this->algorithm() || ($value['key_version'] ?? null) !== $this->keyVersion()
            || ($value['user_id'] ?? null) !== $userId || ($value['action'] ?? null) !== $action || ($value['request_id'] ?? null) !== $requestId) {
            return null;
        }
        if ($intentHmac !== null && (! is_string($value['intent_hmac'] ?? null) || ! hash_equals($value['intent_hmac'], $intentHmac))) {
            throw ValidationException::withMessages(['request_id' => '同一待核对请求不能改变原用户、动作或参数，请使用原操作继续。']);
        }
        if (($value['status'] ?? null) === 'ready'
            && (! is_int($value['scan_started_at'] ?? null) || ! is_int($value['expires_at'] ?? null)
                || $value['scan_started_at'] > now()->getTimestamp() || $value['expires_at'] <= now()->getTimestamp()
                || $value['expires_at'] <= $value['scan_started_at']
                || $value['expires_at'] > $this->expiresAt(CarbonImmutable::createFromTimestamp($value['scan_started_at']))->getTimestamp())) {
            // Legacy completion-time TTLs cannot prove the scan-start bound and must be scanned again.
            return null;
        }

        return $value;
    }

    public function put(int $userId, string $action, string $requestId, string $intentHmac, array $state, ?DateTimeInterface $expiresAt = null): array
    {
        $value = ['user_id' => $userId, 'action' => $action, 'request_id' => $requestId, 'intent_hmac' => $intentHmac,
            'algorithm' => $this->algorithm(), 'key_version' => $this->keyVersion(), ...$state];
        $this->cache()->put($this->key($userId, $action, $requestId), $value, $expiresAt ?? (int) config('receivables.result_ttl_seconds', 600));

        return $value;
    }

    /** A completed proof expires from the full scan's start, never from a cache hit. */
    public function complete(int $userId, string $action, string $requestId, string $intentHmac, array $scan, CarbonImmutable $startedAt, string $status = 'ready'): void
    {
        $expiresAt = $this->expiresAt($startedAt);
        $this->put($userId, $action, $requestId, $intentHmac, ['status' => $status,
            'started_at' => $startedAt->toIso8601String(), 'scan_started_at' => $startedAt->getTimestamp(), 'expires_at' => $expiresAt->getTimestamp(),
            'completed_at' => now()->toIso8601String(), 'scan' => $scan], $expiresAt);
    }

    public function expiresAt(CarbonImmutable $startedAt): CarbonImmutable
    {
        return $startedAt->addSeconds(max(0, min(600, (int) config('receivables.result_ttl_seconds', 600))));
    }

    public function statusUrl(int $userId, string $action, string $requestId): string
    {
        return '/console/receivable-checks/'.$userId.'/'.rawurlencode($action).'/'.rawurlencode($requestId);
    }

    public function algorithm(): int
    {
        return (int) config('receivables.algorithm_version', 1);
    }

    private function keyVersion(): string
    {
        return hash('sha256', (string) config('app.key'));
    }

    private function cache(): Repository
    {
        return Cache::store((string) config('receivables.cache_store', 'redis'));
    }
}
