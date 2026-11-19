<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Services\SettingService;
use App\Support\AdminAuditContext;
use App\Support\SiteSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SaveSiteSettingsAction
{
    public function execute(User $actor, array $settings, ?string $reason = null): void
    {
        $reason = trim($reason ?? '');
        $reason = $reason === '' ? '更新站点信息与展示配置' : $reason;
        validator(compact('reason'), ['reason' => ['required', 'string', 'max:255']])->validate();
        $fields = SiteSettings::fields();
        foreach ($settings as $key => $value) {
            if (! isset($fields[$key])) {
                throw ValidationException::withMessages(['settings' => '存在不支持的站点配置键。']);
            }
            $limit = match ($key) {
                'site.logo_url', 'site.support_url' => 2048,
                'site.announcement' => 5000,
                'site.seo_description' => 1000,
                'site.llms_description' => 2000,
                default => 255,
            };
            validator(['value' => $value], ['value' => [
                $key === 'site.name' ? 'required' : 'nullable', 'string', 'max:'.$limit,
            ]])->validate();
            $value ??= '';
            if (in_array($key, ['site.logo_url', 'site.support_url'], true) && $value !== '' && ! $this->safeUrl($value)) {
                throw ValidationException::withMessages(['settings' => 'Logo 和客服入口只支持 HTTP(S) 地址或单斜杠开头的站内路径。']);
            }
            $settings[$key] = $key === 'site.name' ? trim($value) : $value;
        }
        DB::transaction(function () use ($actor, $settings, $reason): void {
            $operator = User::whereKey($actor->id)->whereNull('anonymized_at')->lockForUpdate()->first();
            if ($operator === null) {
                throw new AuthorizationException('管理员账户已停用。');
            }
            Gate::forUser($operator)->authorize('settings.view');
            Gate::forUser($operator)->authorize('settings.site.manage');
            foreach ($settings as $key => $value) {
                [, $name] = explode('.', $key, 2);
                DB::table('system_settings')->updateOrInsert(['group' => 'site', 'key' => $name], [
                    'value' => $value, 'is_encrypted' => false, 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::afterCommit(fn () => app(SettingService::class)->forget($key));
            }
            DB::table('audit_logs')->insert([
                'actor_type' => 'user', 'actor_id' => $operator->id, 'action' => 'site.configure',
                'subject_type' => 'system_settings', 'reason' => $reason,
                'after' => json_encode(['keys' => array_keys($settings)], JSON_THROW_ON_ERROR),
                ...AdminAuditContext::fields(true), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    private function safeUrl(string $value): bool
    {
        if (str_contains($value, '\\') || preg_match('/[\x00-\x20\x7f]/', $value)) {
            return false;
        }
        if (str_starts_with($value, '/')) {
            return ! str_starts_with($value, '//');
        }

        return filter_var($value, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
