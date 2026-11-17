<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use DomainException;
use Illuminate\Encryption\Encrypter;
use RuntimeException;

class InstallationState
{
    public function exclusive(Closure $callback): mixed
    {
        $handle = fopen(storage_path('installation.mutex'), 'c');
        if ($handle === false) {
            throw new RuntimeException('Cannot open installation mutex.');
        }
        chmod(storage_path('installation.mutex'), 0600);
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new DomainException('另一个安装或换钥进程正在执行，请稍后重试。');
        }
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function read(string $name): array
    {
        $path = storage_path($name);
        if (! is_file($path)) {
            return [];
        }
        $contents = file_get_contents($path);
        if (! is_string($contents) || ! str_starts_with(trim($contents), '{')) {
            return []; // Legacy locks still close the installer; readiness rejects unsealed locks.
        }

        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }

    public function write(string $name, array $value): void
    {
        $temporary = tempnam(storage_path(), '.installation-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot prepare installation state.');
        }
        try {
            chmod($temporary, 0600);
            if (file_put_contents($temporary, json_encode($value, JSON_THROW_ON_ERROR), LOCK_EX) === false
                || ! rename($temporary, storage_path($name))) {
                throw new RuntimeException('Cannot publish installation state.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function encrypter(string $key): Encrypter
    {
        $decoded = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        if (! is_string($decoded) || ! Encrypter::supported($decoded, config('app.cipher'))) {
            throw new DomainException('APP_KEY 无效，请先在服务器生成并备份密钥。');
        }

        return new Encrypter($decoded, config('app.cipher'));
    }

    public function remove(string $name): void
    {
        if (is_file(storage_path($name)) && ! unlink(storage_path($name))) {
            throw new RuntimeException('Cannot remove installation state.');
        }
    }

    public function seal(array $metadata, string $key): array
    {
        return array_replace($metadata, ['key_check' => $this->encrypter($key)->encryptString('cloud-cms-installed-v1')]);
    }

    public function assertKey(?string $key = null, bool $requireSeal = false): void
    {
        $metadata = $this->read('installed.lock');
        if (! isset($metadata['key_check'])) {
            if ($requireSeal) {
                throw new DomainException('安装锁没有密钥校验信息，不能判定部署就绪。');
            }

            return;
        }
        if ($this->encrypter($key ?? config('app.key'))->decryptString($metadata['key_check']) !== 'cloud-cms-installed-v1') {
            throw new RuntimeException('Installation key check failed.');
        }
    }
}
