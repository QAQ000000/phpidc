<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Services\SettingService;
use App\Support\AdminAuditContext;
use App\Support\IdentitySettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ConfigureIdentityAction
{
    /** A null actor is reserved for trusted CLI callers. Credentials never enter audit output. */
    public function execute(array $settings, string $reason, ?User $actor = null): void
    {
        $reason = $actor !== null && trim($reason) === '' ? '更新账户与通知接口配置' : $reason;
        validator(['reason' => trim($reason)], ['reason' => ['required', 'string', 'max:1000']])->validate();
        $fields = IdentitySettings::fields();
        foreach ($settings as $key => $value) {
            if (! isset($fields[$key])) {
                throw ValidationException::withMessages(['settings' => '存在不支持的账户配置键。']);
            }
            if ($actor === null || ! $this->retainsSecret($key, $value)) {
                $this->validateValue($key, $value);
            }
        }
        DB::transaction(function () use ($settings, $reason, $actor): void {
            if ($actor !== null) {
                $operator = User::whereKey($actor->id)->lockForUpdate()->first();
                if ($operator === null || $operator->anonymized_at !== null) {
                    throw new AuthorizationException('管理员账户已停用。');
                }
                Gate::forUser($operator)->authorize('settings.view');
                foreach (IdentitySettings::sections() as $section) {
                    if (array_intersect(array_keys($settings), array_keys($section['fields'])) !== []) {
                        Gate::forUser($operator)->authorize($section['permission']);
                    }
                }
                $this->validateEnabledConfiguration($settings);
                $settings = array_filter($settings, fn (mixed $value, string $key): bool => ! $this->retainsSecret($key, $value), ARRAY_FILTER_USE_BOTH);
            }
            foreach ($settings as $key => $value) {
                [$group, $name] = explode('.', $key, 2);
                $secret = IdentitySettings::isSecret($key);
                $text = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
                DB::table('system_settings')->updateOrInsert(['group' => $group, 'key' => $name], [
                    'value' => $secret ? Crypt::encryptString($text) : $text, 'is_encrypted' => $secret,
                    'created_at' => now(), 'updated_at' => now()]);
                DB::afterCommit(fn () => app(SettingService::class)->forget($key));
            }
            DB::table('audit_logs')->insert(['actor_type' => $actor === null ? 'system' : 'user', 'actor_id' => $actor?->id,
                'action' => 'identity.configure', 'reason' => $reason,
                'after' => json_encode(['keys' => array_keys($settings)], JSON_THROW_ON_ERROR),
                ...AdminAuditContext::fields($actor !== null), 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    private function retainsSecret(string $key, mixed $value): bool
    {
        return IdentitySettings::isSecret($key) && ($value === null || (is_string($value) && trim($value) === ''));
    }

    private function validateValue(string $key, mixed $value): void
    {
        validator(['value' => $value], ['value' => IdentitySettings::rules()[$key]])->validate();
        if ($key === 'sms.idcsmart_templates') {
            foreach ($value as $templateKey => $template) {
                validator(['key' => $templateKey, 'template' => $template], [
                    'key' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9_.-]+$/D'],
                    'template' => ['required', 'array:id,content'],
                    'template.id' => ['required', 'string', 'max:255'], 'template.content' => ['required', 'string', 'max:2000'],
                ])->validate();
            }
        }
    }

    /** Validate fresh database values without caching tentative or decrypted settings. */
    private function validateEnabledConfiguration(array $settings): void
    {
        $sections = array_filter(IdentitySettings::sections(), fn (array $section): bool => array_intersect(array_keys($settings), array_keys($section['fields'])) !== []);
        if ($sections === []) {
            return;
        }
        $rows = DB::table('system_settings')->where(function (Builder $query) use ($sections): void {
            foreach ($sections as $section) {
                foreach (array_keys($section['fields']) as $key) {
                    [$group, $name] = explode('.', $key, 2);
                    $query->orWhere(fn (Builder $entry): Builder => $entry->where('group', $group)->where('key', $name));
                }
            }
        })->orderBy('group')->orderBy('key')->lockForUpdate()->get();
        $values = ['mail.transport' => 'log', 'mail.port' => 587, 'sms.driver' => 'log', 'kyc.driver' => 'disabled'];
        foreach ($rows as $row) {
            $key = $row->group.'.'.$row->key;
            $value = $row->is_encrypted ? Crypt::decryptString($row->value) : $row->value;
            $values[$key] = $key === 'sms.idcsmart_templates' ? json_decode($value, true) : $value;
        }
        foreach ($settings as $key => $value) {
            if (! $this->retainsSecret($key, $value)) {
                $values[$key] = $value;
            }
        }
        foreach ($sections as $sectionName => $section) {
            $required = match ($sectionName) {
                'mail' => $values['mail.transport'] === 'smtp' ? ['mail.host', 'mail.port', 'mail.from_address'] : [],
                'sms' => $values['sms.driver'] === 'idcsmart' ? ['sms.idcsmart_endpoint', 'sms.idcsmart_api', 'sms.idcsmart_key', 'sms.idcsmart_sign', 'sms.idcsmart_templates'] : [],
                'kyc' => $this->enabled($values, 'kyc.enabled') ? ['kyc.driver', 'kyc.idcsmartali_endpoint', 'kyc.idcsmartali_api', 'kyc.idcsmartali_key', 'kyc.idcsmartali_biz_code'] : [],
                'telegram' => $this->enabled($values, 'oauth.telegram_enabled') ? ['telegram.bot_username', 'telegram.bot_token'] : [],
                default => $this->enabled($values, 'oauth.'.$sectionName.'_enabled') ? ['oauth.'.$sectionName.'_client_id', 'oauth.'.$sectionName.'_client_secret'] : [],
            };
            foreach ($required as $key) {
                $this->validateValue($key, $values[$key] ?? null);
            }
            if ($sectionName === 'kyc' && $this->enabled($values, 'kyc.enabled') && $values['kyc.driver'] !== 'idcsmartali') {
                throw ValidationException::withMessages(['settings' => '启用实名认证前请选择可用的认证通道。']);
            }
            if ($sectionName === 'sms' && $this->enabled($values, 'sms.binding_enabled')) {
                $template = $values['sms.binding_template'] ?? null;
                $this->validateValue('sms.binding_template', $template);
                if ($values['sms.driver'] !== 'idcsmart' || ! isset($values['sms.idcsmart_templates'][$template])) {
                    throw ValidationException::withMessages(['settings' => '启用手机绑定前须配置真实短信通道及对应模板。']);
                }
            }
        }
    }

    private function enabled(array $values, string $key): bool
    {
        return filter_var($values[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
