<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads global singleton settings from `system_settings` (charter §3.6, F07/F09).
 *
 * Pre-install bypass: before `storage/installed.lock` exists the database and
 * Redis may not be reachable at all, so every read short-circuits to the
 * default value and no connection is opened.
 *
 * Values flagged `is_encrypted` are decrypted per read. Only the ciphertext is
 * stored in Redis, never the plaintext (charter §3.6).
 */
class SettingService
{
    /** @var array<string, string|null> */
    private array $memory = [];

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    public function isInstalled(): bool
    {
        return file_exists(storage_path('installed.lock'));
    }

    public function get(string $key, ?string $default = null): ?string
    {
        if (! $this->isInstalled()) {
            return $default;
        }

        if (array_key_exists($key, $this->memory)) {
            return $this->memory[$key] ?? $default;
        }

        [$group, $name] = $this->splitKey($key);
        $cacheKey = "setting:{$group}:{$name}";

        /** @var array{value: string|null, is_encrypted: bool}|null $cached */
        $cached = $this->cache->get($cacheKey);

        if ($cached === null) {
            if (! Schema::hasTable('system_settings')) {
                return $default;
            }

            $row = DB::table('system_settings')
                ->where('group', $group)
                ->where('key', $name)
                ->first(['value', 'is_encrypted']);

            $cached = $row === null
                ? ['value' => null, 'is_encrypted' => false]
                : ['value' => $row->value, 'is_encrypted' => (bool) $row->is_encrypted];

            $this->cache->put($cacheKey, $cached, now()->addHour());
        }

        $value = $cached['value'];

        if ($value !== null && $cached['is_encrypted']) {
            try {
                $value = Crypt::decryptString($value);
            } catch (DecryptException) {
                // A mismatched APP_KEY is a boot-time error, not a silent null:
                // treating it as missing would let an order run with no gateway.
                throw new \RuntimeException(
                    "Stored setting [{$key}] could not be decrypted. APP_KEY changed without app:reencrypt-secrets."
                );
            }
        }

        $this->memory[$key] = $value;

        return $value ?? $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return $value === null ? $default : (int) $value;
    }

    public function forget(string $key): void
    {
        [$group, $name] = $this->splitKey($key);
        $this->cache->forget("setting:{$group}:{$name}");
        unset($this->memory[$key]);
    }

    /** @return array{0: string, 1: string} */
    private function splitKey(string $key): array
    {
        $parts = explode('.', $key, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new \InvalidArgumentException("Setting key [{$key}] must be in 'group.key' form.");
        }

        return [$parts[0], $parts[1]];
    }
}
