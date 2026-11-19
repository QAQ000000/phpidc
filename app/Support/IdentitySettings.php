<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IdentitySettings
{
    /** @return array<string, array{label: string, permission: string, fields: array<string, array{label: string, type: string, secret?: bool, options?: array<string, string>}>}> */
    public static function sections(): array
    {
        $sections = [
            'mail' => ['label' => '邮件', 'permission' => 'settings.notification.manage', 'fields' => [
                'mail.transport' => ['label' => '邮件通道', 'type' => 'select', 'options' => ['log' => '日志模拟', 'smtp' => 'SMTP']],
                'mail.host' => ['label' => 'SMTP 主机', 'type' => 'text'],
                'mail.port' => ['label' => 'SMTP 端口', 'type' => 'number'],
                'mail.username' => ['label' => 'SMTP 用户名', 'type' => 'password', 'secret' => true],
                'mail.password' => ['label' => 'SMTP 密码', 'type' => 'password', 'secret' => true],
                'mail.from_address' => ['label' => '发件邮箱', 'type' => 'text'],
                'mail.from_name' => ['label' => '发件名称', 'type' => 'text'],
            ]],
            'sms' => ['label' => '短信', 'permission' => 'settings.notification.manage', 'fields' => [
                'sms.driver' => ['label' => '短信通道', 'type' => 'select', 'options' => ['log' => '日志模拟', 'idcsmart' => '智简魔方']],
                'sms.binding_enabled' => ['label' => '启用手机绑定', 'type' => 'checkbox'],
                'sms.binding_template' => ['label' => '手机绑定模板标识', 'type' => 'text'],
                'sms.idcsmart_endpoint' => ['label' => '短信接口地址', 'type' => 'text'],
                'sms.idcsmart_api' => ['label' => '短信 API 标识', 'type' => 'password', 'secret' => true],
                'sms.idcsmart_key' => ['label' => '短信密钥', 'type' => 'password', 'secret' => true],
                'sms.idcsmart_sign' => ['label' => '短信签名', 'type' => 'text'],
                'sms.idcsmart_templates' => ['label' => '短信模板映射（JSON）', 'type' => 'textarea'],
            ]],
            'kyc' => ['label' => '实名认证', 'permission' => 'settings.identity.manage', 'fields' => [
                'kyc.enabled' => ['label' => '启用实名认证', 'type' => 'checkbox'],
                'kyc.driver' => ['label' => '实名通道', 'type' => 'select', 'options' => ['disabled' => '停用', 'idcsmartali' => '智简阿里认证']],
                'kyc.idcsmartali_endpoint' => ['label' => '实名接口地址', 'type' => 'text'],
                'kyc.idcsmartali_api' => ['label' => '实名 API 标识', 'type' => 'password', 'secret' => true],
                'kyc.idcsmartali_key' => ['label' => '实名密钥', 'type' => 'password', 'secret' => true],
                'kyc.idcsmartali_biz_code' => ['label' => '认证场景', 'type' => 'select', 'options' => [
                    'FACE' => '人脸认证', 'SMART_FACE' => '智能人脸认证', 'CERT_PHOTO' => '证件照认证', 'CERT_PHOTO_FACE' => '证件照与人脸认证',
                ]],
            ]],
        ];
        foreach (['github' => 'GitHub', 'google' => 'Google', 'qq' => 'QQ', 'wechat' => '微信开放平台'] as $provider => $label) {
            $sections[$provider] = ['label' => $label, 'permission' => 'settings.identity.manage', 'fields' => [
                'oauth.'.$provider.'_enabled' => ['label' => '启用'.$label.'登录', 'type' => 'checkbox'],
                'oauth.'.$provider.'_client_id' => ['label' => 'Client ID', 'type' => 'text'],
                'oauth.'.$provider.'_client_secret' => ['label' => 'Client Secret', 'type' => 'password', 'secret' => true],
            ]];
        }
        $sections['telegram'] = ['label' => 'Telegram', 'permission' => 'settings.identity.manage', 'fields' => [
            'telegram.bot_username' => ['label' => '机器人用户名', 'type' => 'text'],
            'telegram.bot_token' => ['label' => '机器人 Token', 'type' => 'password', 'secret' => true],
            'oauth.telegram_enabled' => ['label' => '启用 Telegram 登录', 'type' => 'checkbox'],
        ]];

        return $sections;
    }

    /** @return list<string> */
    public static function keysForSection(string $section): array
    {
        $definition = self::sections()[$section] ?? null;
        if ($definition === null) {
            throw ValidationException::withMessages(['section' => '不支持的配置分组。']);
        }

        return array_keys($definition['fields']);
    }

    /** @return array<string, array{label: string, type: string, secret?: bool, options?: array<string, string>}> */
    public static function fields(): array
    {
        $fields = [];
        foreach (self::sections() as $section) {
            $fields += $section['fields'];
        }

        return $fields;
    }

    public static function isSecret(string $key): bool
    {
        return self::fields()[$key]['secret'] ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public static function rules(): array
    {
        $rules = [
            'mail.host' => ['required', 'string', 'max:255'], 'mail.port' => ['required', 'integer', 'min:1', 'max:65535'],
            'mail.username' => ['nullable', 'string', 'max:255'], 'mail.password' => ['nullable', 'string', 'max:4096'],
            'mail.from_address' => ['required', 'email', 'max:255'], 'mail.from_name' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'sms.binding_template' => ['required', 'string', 'max:255'],
            'sms.idcsmart_endpoint' => ['required', 'url:https', 'max:2048', 'not_regex:/[?#@]/'],
            'sms.idcsmart_api' => ['required', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'sms.idcsmart_key' => ['required', 'string', 'max:4096', 'not_regex:/[\r\n]/'],
            'sms.idcsmart_sign' => ['required', 'string', 'max:100', 'not_regex:/[\r\n]/'],
            'sms.idcsmart_templates' => ['required', 'array', 'min:1'],
            'telegram.bot_token' => ['required', 'string', 'regex:/^[0-9]+:[A-Za-z0-9_-]{30,}$/D'],
            'telegram.bot_username' => ['required', 'string', 'regex:/^[A-Za-z0-9_]{2,29}[Bb][Oo][Tt]$/D'],
            'kyc.idcsmartali_endpoint' => ['required', 'url:https', 'max:2048', 'not_regex:/[?#@]/'],
            'kyc.idcsmartali_api' => ['required', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'kyc.idcsmartali_key' => ['required', 'string', 'max:4096', 'not_regex:/[\r\n]/'],
        ];
        foreach (self::fields() as $key => $field) {
            if ($field['type'] === 'select') {
                $rules[$key] = ['required', Rule::in(array_keys($field['options'] ?? []))];
            } elseif ($field['type'] === 'checkbox') {
                $rules[$key] = ['required', 'boolean'];
            } elseif (! isset($rules[$key])) {
                $rules[$key] = ['required', 'string', self::isSecret($key) ? 'max:4096' : 'max:255'];
            }
        }

        return $rules;
    }
}
