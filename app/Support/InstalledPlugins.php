<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SettingService;

/** The adapters shipped by this application, not a dynamic package installer. */
class InstalledPlugins
{
    public static function categories(): array
    {
        return [
            'payment' => ['label' => '支付插件', 'permission' => 'settings.payment.manage', 'exclusive' => false, 'description' => '管理收款接口；停用仅影响新的支付，历史回调继续处理。'],
            'mail' => ['label' => '邮件插件', 'permission' => 'settings.notification.manage', 'exclusive' => true, 'description' => '同时只使用一个邮件通道；停用 SMTP 后切换为日志模拟。'],
            'sms' => ['label' => '短信插件', 'permission' => 'settings.notification.manage', 'exclusive' => true, 'description' => '同时只使用一个短信通道；手机绑定启用期间须保留真实短信通道。'],
            'kyc' => ['label' => '实名插件', 'permission' => 'settings.identity.manage', 'exclusive' => true, 'description' => '管理实名认证接口，启用前须完成配置。'],
            'login' => ['label' => '登录插件', 'permission' => 'settings.identity.manage', 'exclusive' => false, 'description' => '可同时启用多个第三方登录；Telegram 登录开关不影响机器人通知。'],
        ];
    }

    public static function definitions(string $category): array
    {
        $definitions = match ($category) {
            'payment' => ['epay' => ['name' => '易支付', 'tab' => 'payment', 'description' => '易支付标准接口，支持支付宝和微信支付。']],
            'mail' => [
                'smtp' => ['name' => 'SMTP 邮件', 'tab' => 'mail', 'description' => '通过自有 SMTP 服务发送邮件。'],
                'log' => ['name' => '日志模拟', 'tab' => null, 'description' => '仅记录模拟结果，不发送真实邮件。'],
            ],
            'sms' => [
                'idcsmart' => ['name' => '智简魔方短信', 'tab' => 'sms', 'description' => '通过智简魔方短信接口发送消息。'],
                'log' => ['name' => '日志模拟', 'tab' => null, 'description' => '仅记录模拟结果，不发送真实短信。'],
            ],
            'kyc' => ['idcsmartali' => ['name' => '智简阿里认证', 'tab' => 'kyc', 'description' => '通过智简阿里接口进行实名认证。']],
            'login' => [
                'github' => ['name' => 'GitHub 登录', 'tab' => 'github', 'description' => '使用 GitHub 账户登录。'],
                'google' => ['name' => 'Google 登录', 'tab' => 'google', 'description' => '使用 Google 账户登录。'],
                'qq' => ['name' => 'QQ 登录', 'tab' => 'qq', 'description' => '使用 QQ 账户登录。'],
                'wechat' => ['name' => '微信登录', 'tab' => 'wechat', 'description' => '使用微信开放平台账户登录。'],
                'telegram' => ['name' => 'Telegram 登录', 'tab' => 'telegram', 'description' => '使用 Telegram 账户登录。'],
            ],
            default => [],
        };
        foreach ($definitions as $key => &$definition) {
            $definition += ['key' => $key, 'identifier' => $category.'.'.$key, 'author' => '项目内置适配器', 'version' => '随系统发布'];
        }

        return $definitions;
    }

    public static function configurationContext(string $tab, string $key): ?array
    {
        foreach (self::categories() as $category => $details) {
            $definition = self::definitions($category)[$key] ?? null;
            if ($definition !== null && $definition['tab'] === $tab) {
                return ['category' => $category, 'definition' => $definition];
            }
        }

        return null;
    }

    public static function rows(string $category): array
    {
        $rows = [];
        foreach (self::definitions($category) as $key => $definition) {
            $rows[] = [...$definition, 'id' => $category.'.'.$key,
                'enabled' => self::enabled($category, $key), 'configured' => self::configured($category, $key),
                'configuration_url' => $definition['tab'] === null ? null : '/admin/settings?'.http_build_query(['tab' => $definition['tab'], 'plugin' => $key])];
        }

        return $rows;
    }

    private static function enabled(string $category, string $key): bool
    {
        $settings = app(SettingService::class);

        return match ($category) {
            'payment' => $settings->getBool('payment.epay_alipay_enabled', true) || $settings->getBool('payment.epay_wechat_enabled', true),
            'mail' => $settings->get('mail.transport', 'log') === $key,
            'sms' => $settings->get('sms.driver', 'log') === $key,
            'kyc' => $settings->getBool('kyc.enabled') && $settings->get('kyc.driver', 'disabled') === $key,
            'login' => $settings->getBool('oauth.'.$key.'_enabled'),
            default => false,
        };
    }

    private static function configured(string $category, string $key): bool
    {
        if ($key === 'log') {
            return true;
        }
        $settings = app(SettingService::class);
        if ($category === 'payment') {
            $credentials = app(EpayProfiles::class)->credentials();

            return trim($credentials['pid']) !== '' && trim($credentials['key']) !== ''
                && filter_var($credentials['api_url'], FILTER_VALIDATE_URL) !== false
                && in_array(parse_url($credentials['api_url'], PHP_URL_SCHEME), ['http', 'https'], true);
        }
        $required = match ($category) {
            'mail' => ['mail.host', 'mail.port', 'mail.from_address'],
            'sms' => ['sms.idcsmart_endpoint', 'sms.idcsmart_api', 'sms.idcsmart_key', 'sms.idcsmart_sign', 'sms.idcsmart_templates'],
            'kyc' => ['kyc.idcsmartali_endpoint', 'kyc.idcsmartali_api', 'kyc.idcsmartali_key', 'kyc.idcsmartali_biz_code'],
            'login' => $key === 'telegram' ? ['telegram.bot_username', 'telegram.bot_token'] : ['oauth.'.$key.'_client_id', 'oauth.'.$key.'_client_secret'],
            default => [],
        };
        foreach ($required as $name) {
            $value = $settings->get($name, $name === 'mail.port' ? '587' : null);
            if ($name === 'sms.idcsmart_templates') {
                $value = json_decode($value ?? '', true);
            }
            if (validator(['value' => $value], ['value' => IdentitySettings::rules()[$name]])->fails()) {
                return false;
            }
        }

        return $required !== [];
    }
}
