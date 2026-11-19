<?php

declare(strict_types=1);

namespace App\Actions;

use App\Services\SettingService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ConfigureIdentityAction
{
    /** Trusted CLI only. Credentials are never echoed in output or audit. */
    public function execute(array $settings, string $reason): void
    {
        validator(['reason' => trim($reason)], ['reason' => ['required', 'string', 'max:1000']])->validate();
        $rules = ['mail.transport' => ['required', Rule::in(['smtp', 'log'])], 'mail.host' => ['required', 'string', 'max:255'],
            'mail.port' => ['required', 'integer', 'min:1', 'max:65535'], 'mail.username' => ['nullable', 'string', 'max:255'],
            'mail.password' => ['nullable', 'string', 'max:4096'], 'mail.from_address' => ['required', 'email', 'max:255'],
            'mail.from_name' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'sms.binding_enabled' => ['required', 'boolean'], 'sms.binding_template' => ['required', 'string', 'max:255'],
            'sms.driver' => ['required', Rule::in(['log', 'idcsmart'])],
            'sms.idcsmart_endpoint' => ['required', 'url:https', 'max:2048', 'not_regex:/[?#@]/'],
            'sms.idcsmart_api' => ['required', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'sms.idcsmart_key' => ['required', 'string', 'max:4096', 'not_regex:/[\r\n]/'],
            'sms.idcsmart_sign' => ['required', 'string', 'max:100', 'not_regex:/[\r\n]/'],
            'sms.idcsmart_templates' => ['required', 'array', 'min:1'],
            'telegram.bot_token' => ['required', 'string', 'regex:/^[0-9]+:[A-Za-z0-9_-]{30,}$/D'],
            'telegram.bot_username' => ['required', 'string', 'regex:/^[A-Za-z0-9_]{2,29}[Bb][Oo][Tt]$/D'],
            'oauth.telegram_enabled' => ['required', 'boolean'],
            'kyc.enabled' => ['required', 'boolean'], 'kyc.driver' => ['required', Rule::in(['idcsmartali', 'disabled'])],
            'kyc.idcsmartali_endpoint' => ['required', 'url:https', 'max:2048', 'not_regex:/[?#@]/'],
            'kyc.idcsmartali_api' => ['required', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'kyc.idcsmartali_key' => ['required', 'string', 'max:4096', 'not_regex:/[\r\n]/'],
            'kyc.idcsmartali_biz_code' => ['required', Rule::in(['FACE', 'SMART_FACE', 'CERT_PHOTO', 'CERT_PHOTO_FACE'])]];
        foreach (['github', 'google', 'qq', 'wechat'] as $provider) {
            $rules['oauth.'.$provider.'_enabled'] = ['required', 'boolean'];
            $rules['oauth.'.$provider.'_client_id'] = ['required', 'string', 'max:255'];
            $rules['oauth.'.$provider.'_client_secret'] = ['required', 'string', 'max:4096'];
        }
        foreach ($settings as $key => $value) {
            if (! isset($rules[$key])) {
                throw ValidationException::withMessages(['settings' => '存在不支持的账户配置键。']);
            }
            validator(['value' => $value], ['value' => $rules[$key]])->validate();
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
        DB::transaction(function () use ($settings, $reason): void {
            foreach ($settings as $key => $value) {
                [$group, $name] = explode('.', $key, 2);
                $secret = in_array($key, ['mail.password', 'mail.username', 'sms.idcsmart_api', 'sms.idcsmart_key', 'kyc.idcsmartali_api', 'kyc.idcsmartali_key', 'telegram.bot_token'], true) || str_ends_with($key, '_client_secret');
                $text = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
                DB::table('system_settings')->updateOrInsert(['group' => $group, 'key' => $name], [
                    'value' => $secret ? Crypt::encryptString($text) : $text, 'is_encrypted' => $secret,
                    'created_at' => now(), 'updated_at' => now()]);
                DB::afterCommit(fn () => app(SettingService::class)->forget($key));
            }
            DB::table('audit_logs')->insert(['actor_type' => 'system', 'action' => 'identity.configure', 'reason' => $reason,
                'after' => json_encode(['keys' => array_keys($settings)], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        });
    }
}
