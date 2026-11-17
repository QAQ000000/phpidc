<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\InstallationState;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class DeploymentRequirements
{
    /** These checks deliberately do not contact a database or Redis. */
    public function runtime(): array
    {
        // Composer's platform_check.php has already enforced the PHP version before application boot.
        $checks = ['PHP '.PHP_VERSION.' (Composer platform gate)' => true];
        foreach (['bcmath', 'ctype', 'curl', 'dom', 'fileinfo', 'intl', 'mbstring', 'openssl', 'pdo', 'xml'] as $extension) {
            $checks['extension:'.$extension] = extension_loaded($extension);
        }
        if (app()->runningInConsole()) {
            $checks['extension:pcntl (worker timeout)'] = extension_loaded('pcntl');
        }
        foreach ([storage_path(), storage_path('framework/views'), storage_path('framework/sessions'), storage_path('framework/cache/data'), base_path('bootstrap/cache')] as $path) {
            $checks[$path.' writable'] = is_dir($path) && is_writable($path);
        }

        return $checks;
    }

    public function assertInstallable(bool $production = false): void
    {
        foreach ($this->runtime() as $name => $ok) {
            if (! $ok) {
                throw new DomainException('环境检查未通过：'.$name);
            }
        }
        app(InstallationState::class)->encrypter(config('app.key') ?? '');
        if (($production || app()->environment('production')) && ! $this->productionConfiguration()) {
            throw new DomainException('生产环境要求 HTTPS、关闭调试、MySQL 和 Redis 队列/缓存/会话。');
        }
        $connection = DB::connection();
        if ($connection->getDriverName() === 'mysql') {
            $version = (string) $connection->selectOne('select version() as version')->version;
            if (str_contains(strtolower($version), 'mariadb') || version_compare($version, '8.0.0', '<')) {
                throw new DomainException('生产数据库契约要求 MySQL 8.0+，不接受 MySQL 5.7 或未验收的兼容实现。');
            }
        } elseif ($connection->getDriverName() !== 'sqlite' || ! app()->environment(['local', 'testing'])) {
            throw new DomainException('仅 local/testing 隔离验收允许 SQLite；生产必须使用 MySQL 8.0+。');
        }
        $connection->getPdo();
        if (config('queue.default') === 'redis' || config('cache.default') === 'redis'
            || config('installation.session_driver', config('session.driver')) === 'redis') {
            Redis::connection()->ping();
            Redis::connection('cache')->ping();
        }
        $queue = config('queue.connections.'.config('queue.default'));
        if (! is_array($queue) || ! ($queue['after_commit'] ?? false) || ($queue['retry_after'] ?? 0) <= 180) {
            throw new DomainException('异步队列必须 after_commit，retry_after 必须大于 180 秒（INV-Q1）。');
        }
    }

    private function productionConfiguration(): bool
    {
        return ! config('app.debug') && parse_url(config('app.url'), PHP_URL_SCHEME) === 'https'
            && config('session.secure') === true
            && config('database.default') === 'mysql' && config('queue.default') === 'redis'
            && config('cache.default') === 'redis'
            && config('installation.session_driver', config('session.driver')) === 'redis';
    }
}
