<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class MaintenanceState
{
    public function store(): Repository
    {
        return Cache::store(config('maintenance.cache_store'));
    }

    public function assertKind(string $kind): void
    {
        if (! in_array($kind, [...config('maintenance.kinds'), ...config('maintenance.subkinds', [])], true)) {
            throw new InvalidArgumentException('Unknown maintenance batch kind.');
        }
    }

    public function heartbeat(string $kind, string $phase, array $summary = []): void
    {
        $this->assertKind($kind);
        $state = $this->snapshot($kind);
        $state[$phase.'_at'] = now()->timestamp;
        $this->store()->forever('maintenance:heartbeat:'.$kind, [...$state, ...$summary]);
    }

    public function snapshot(string $kind): array
    {
        $this->assertKind($kind);

        return $this->store()->get('maintenance:heartbeat:'.$kind, []);
    }

    public function cursor(string $kind): int
    {
        return (int) $this->store()->get('maintenance:cursor:'.$kind, 0);
    }

    public function advance(string $kind, int $id): void
    {
        $this->store()->forever('maintenance:cursor:'.$kind, $id);
    }

    public function retryDue(string $kind, int $id): bool
    {
        return (int) ($this->store()->get($this->retryKey($kind, $id), [])['retry_at'] ?? 0) <= now()->timestamp;
    }

    public function succeeded(string $kind, int $id): void
    {
        $this->store()->forget($this->retryKey($kind, $id));
    }

    /** Only safe identifiers/codes are retained; exception messages can contain SQL or secrets. */
    public function failed(string $kind, int $id, Throwable $error): array
    {
        $key = $this->retryKey($kind, $id);
        $attempt = min(100, (int) ($this->store()->get($key, [])['attempts'] ?? 0) + 1);
        $code = match (true) {
            $error instanceof QueryException && ($error->errorInfo[1] ?? null) === 1205 => 'db_lock_timeout',
            $error instanceof QueryException && ($error->errorInfo[1] ?? null) === 1213 => 'db_deadlock',
            $error instanceof QueryException => 'database_error',
            $error instanceof \DomainException || $error instanceof ValidationException => 'invalid_evidence',
            default => 'maintenance_exception',
        };
        $record = ['kind' => $kind, 'id' => $id, 'attempts' => $attempt, 'code' => $code,
            'failed_at' => now()->timestamp, 'retry_at' => now()->addSeconds([60, 300, 900][min($attempt - 1, 2)])->timestamp];
        $this->store()->put($key, $record, 3600);
        $samples = $this->store()->get('maintenance:failures:'.$kind, []);
        $samples[$id] = $record;
        $this->store()->put('maintenance:failures:'.$kind, array_slice($samples, -100, null, true), 3600);
        Log::warning('Maintenance item deferred.', $record);

        return $record;
    }

    public function failures(string $kind): array
    {
        return array_values(array_filter($this->store()->get('maintenance:failures:'.$kind, []),
            fn (array $sample): bool => $this->store()->has($this->retryKey($kind, $sample['id']))));
    }

    private function retryKey(string $kind, int $id): string
    {
        return 'maintenance:retry:'.$kind.':'.$id;
    }
}
