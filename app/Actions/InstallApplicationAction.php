<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Services\DeploymentRequirements;
use App\Support\AdminAuditContext;
use App\Support\InstallationState;
use DomainException;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class InstallApplicationAction
{
    public function execute(array $input, bool $web = false): int
    {
        $state = app(InstallationState::class);

        return $state->exclusive(function () use ($state, $input, $web): int {
            if (is_file(storage_path('installed.lock'))) {
                throw new DomainException('安装已完成。');
            }
            $token = $state->read('installation.token');
            if (! is_string($input['install_token'] ?? null) || ! isset($token['hash'], $token['expires_at'])
                || $token['expires_at'] <= now()->timestamp || ! hash_equals($token['hash'], hash('sha256', $input['install_token']))) {
                throw new DomainException('安装令牌无效或已过期，请通过服务器命令重新生成。');
            }
            $validated = validator($input, [
                'admin_name' => ['required', 'string', 'max:255'],
                'admin_email' => ['required', 'email', 'max:255'],
                'admin_password' => ['required', 'string', 'max:255', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()->symbols()],
                'reason' => ['required', 'string', 'max:255'],
            ])->validate();
            if (app()->configurationIsCached()) {
                throw new DomainException('首次安装前请在服务器执行 config:clear；安装完成后再建立配置缓存。');
            }
            app(DeploymentRequirements::class)->assertInstallable();
            // No credentials are stored in the manifest. Bind retries to this exact bootstrap connection and key.
            $fingerprint = hash('sha256', json_encode([DB::connection()->getConfig(), config('app.key')], JSON_THROW_ON_ERROR));
            $progress = $state->read('installation.progress');
            if ($progress === []) {
                if (DB::connection()->getSchemaBuilder()->getTableListing() !== []) {
                    throw new DomainException('目标数据库不是空库，拒绝接管。升级请按部署手册执行 migrate。');
                }
                $progress = ['id' => (string) Str::uuid(), 'database' => $fingerprint,
                    'admin_email' => strtolower($validated['admin_email']), 'started_at' => now()->toIso8601String()];
                $state->write('installation.progress', $progress);
            }
            if (! hash_equals($progress['database'], $fingerprint) || $progress['admin_email'] !== strtolower($validated['admin_email'])) {
                throw new DomainException('重试必须使用同一数据库、APP_KEY 和初始管理员邮箱。');
            }
            // MySQL DDL is not transactional. Laravel records finished migrations; the manifest authorizes only this attempt's retry.
            $migrator = app(Migrator::class);
            if (! $migrator->repositoryExists()) {
                $migrator->getRepository()->createRepository();
            }
            $migrator->run([database_path('migrations')], ['force' => true]);
            $id = DB::transaction(function () use ($validated, $progress, $web): int {
                $existing = User::where('email', $progress['admin_email'])->lockForUpdate()->first();
                if ($existing !== null) {
                    if (! DB::table('audit_logs')->where('action', 'installation.bootstrap')->where('subject_id', $existing->id)
                        ->where('after->installation_id', $progress['id'])->exists()) {
                        throw new DomainException('初始管理员已存在但不属于本次安装，拒绝覆盖。');
                    }

                    return $existing->id;
                }
                $admin = User::create(['name' => $validated['admin_name'], 'email' => $progress['admin_email'], 'password' => $validated['admin_password']]);
                // Keep finance/refund/payout permissions separate. access-manager is the existing trusted bootstrap role.
                app(AssignAdminRoleAction::class)->execute($admin, 'access-manager', $validated['reason']);
                DB::table('audit_logs')->insert(['actor_type' => 'system', 'actor_id' => null,
                    'action' => 'installation.bootstrap', 'subject_type' => User::class, 'subject_id' => $admin->id,
                    'reason' => $validated['reason'], 'before' => '{}',
                    'after' => json_encode(['installation_id' => $progress['id'], 'roles' => ['access-manager']], JSON_THROW_ON_ERROR),
                    ...AdminAuditContext::fields($web), 'created_at' => now(), 'updated_at' => now()]);

                return $admin->id;
            });
            $state->write('installed.lock', $state->seal(['format' => 1, 'installation_id' => $progress['id'],
                'installed_at' => now()->toIso8601String(), 'admin_id' => $id,
                'version' => trim((string) file_get_contents(base_path('VERSION')))], config('app.key')));
            unlink(storage_path('installation.token'));
            unlink(storage_path('installation.progress'));

            return $id;
        });
    }
}
