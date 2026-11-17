<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\DeploymentRequirements;
use App\Support\InstallationState;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;

class CheckDeploymentCommand extends Command
{
    protected $signature = 'app:deployment-check {--production : Enforce production configuration even in a local environment}';

    protected $description = 'Read-only configuration, database, Redis, migration and published asset checks';

    public function handle(DeploymentRequirements $requirements, InstallationState $state, Migrator $migrator): int
    {
        try {
            if (! is_file(storage_path('installed.lock'))) {
                throw new DomainException('尚未完成安装。');
            }
            $state->assertKey(requireSeal: true);
            $requirements->assertInstallable((bool) $this->option('production'));
            $files = $migrator->getMigrationFiles([database_path('migrations')]);
            if (! $migrator->repositoryExists() || array_diff(array_keys($files), $migrator->getRepository()->getRan()) !== []) {
                throw new DomainException('存在未执行的迁移。');
            }
            $admin = User::find($state->read('installed.lock')['admin_id'] ?? 0);
            if ($admin === null || ! $admin->can('access.manage')) {
                throw new DomainException('初始权限管理员不可用，请从可信 CLI 核查。');
            }
            foreach (['js/filament/filament/app.js', 'css/filament/filament/app.css', 'js/classic-config.js'] as $asset) {
                if (! is_file(public_path($asset))) {
                    throw new DomainException('缺少发布资源：'.$asset);
                }
            }
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (\PDOException|\RedisException) {
            $this->error('数据库或 Redis 检查失败；请核对服务与服务器配置。');

            return self::FAILURE;
        }
        $this->info('PASS: '.(app()->environment('production') || $this->option('production') ? 'production' : 'local validation only')
            .' — sealed key, runtime, connections, queue ladder, migrations, access manager and assets.');
        $this->warn('Worker/scheduler process health, backups and real supplier acceptance require the deployment runbook.');

        return self::SUCCESS;
    }
}
