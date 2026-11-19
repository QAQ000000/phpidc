<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Services\SettingService;
use App\Support\AdminAuditContext;
use App\Support\PolicySettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SaveBillingSettingsAction
{
    public function execute(User $actor, array $settings, ?string $reason = null): void
    {
        $settings = PolicySettings::validate('billing', $settings);
        $reason = trim($reason ?? '');
        $reason = $reason === '' ? '保存计费策略配置' : $reason;
        validator(['reason' => $reason], ['reason' => ['required', 'string', 'max:255']])->validate();
        DB::transaction(function () use ($actor, $settings, $reason): void {
            $operator = User::whereKey($actor->id)->whereNull('anonymized_at')->lockForUpdate()->first();
            if ($operator === null) {
                throw new AuthorizationException('管理员账户已停用。');
            }
            Gate::forUser($operator)->authorize('settings.view');
            Gate::forUser($operator)->authorize('settings.billing.manage');
            foreach ($settings as $key => $value) {
                [$group, $name] = explode('.', $key, 2);
                DB::table('system_settings')->updateOrInsert(['group' => $group, 'key' => $name], [
                    'value' => (string) $value, 'is_encrypted' => false, 'created_at' => now(), 'updated_at' => now()]);
                DB::afterCommit(fn () => app(SettingService::class)->forget($key));
            }
            DB::table('audit_logs')->insert(['actor_type' => 'user', 'actor_id' => $operator->id,
                'action' => 'settings.billing.configure', 'subject_type' => 'system_settings', 'reason' => $reason,
                'after' => json_encode(['keys' => array_keys($settings)], JSON_THROW_ON_ERROR),
                ...AdminAuditContext::fields(true), 'created_at' => now(), 'updated_at' => now()]);
        });
    }
}
