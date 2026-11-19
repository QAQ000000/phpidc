<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\SettingService;
use App\Support\IdentitySettings;
use App\Support\PaymentChannels;
use App\Support\PolicySettings;
use App\Support\SiteSettings;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use UnitEnum;

class SystemSettings extends Page
{
    protected static ?string $slug = 'settings';

    protected static ?string $title = '系统配置';

    protected static string|UnitEnum|null $navigationGroup = '系统配置';

    protected string $view = 'filament.pages.system-settings';

    #[Url(as: 'tab')]
    public string $tab = 'payment';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('settings.view') ?? false;
    }

    protected function getViewData(): array
    {
        $sections = ['site' => ['label' => '站点信息', 'permission' => 'settings.site.manage', 'fields' => SiteSettings::fields()]]
            + IdentitySettings::sections() + PolicySettings::sections();
        $tabs = ['payment' => '易支付'];
        foreach ($sections as $key => $section) {
            $tabs[$key] = $section['label'];
        }
        if (Auth::user()?->can('upstream.view')) {
            $tabs['upstreams'] = '上游连接';
        }
        $tab = array_key_exists($this->tab, $tabs) ? $this->tab : 'payment';
        $section = $sections[$tab] ?? null;
        $settings = app(SettingService::class);
        $values = [];
        $defaults = ['mail.transport' => 'log', 'mail.port' => '587', 'mail.from_address' => 'noreply@example.test',
            'sms.driver' => 'log', 'kyc.driver' => 'disabled'];
        foreach ($section['fields'] ?? [] as $key => $field) {
            // Only this safe projection enters the Blade/Livewire response.
            $default = isset($field['default']) ? (string) $field['default'] : ($defaults[$key] ?? null);
            $value = $settings->get($key, $default);
            $values[$key] = IdentitySettings::isSecret($key)
                ? ['configured' => $value !== null && $value !== '', 'value' => '']
                : ['configured' => $value !== null, 'value' => $value ?? ''];
        }

        $saveAction = match ($tab) {
            'site' => '/admin/settings/site',
            'billing', 'automation' => '/admin/settings/policy/'.$tab,
            default => '/admin/settings/identity/'.$tab,
        };
        [$sectionTitle, $sectionDescription] = $this->sectionPresentation($tab);

        return ['tabs' => $tabs, 'activeTab' => $tab, 'section' => $section, 'values' => $values, 'saveAction' => $saveAction,
            'navigationGroups' => $this->navigationGroups($tabs),
            'fieldGroups' => $this->fieldGroups($tab, array_keys($section['fields'] ?? [])),
            'sectionTitle' => $sectionTitle, 'sectionDescription' => $sectionDescription, 'fieldHints' => $this->fieldHints(),
            'payment' => $tab === 'payment' ? ['url' => $settings->get('payment.epay_api_url', ''),
                'pid' => $settings->get('payment.epay_pid', ''),
                'has_key' => trim($settings->get('payment.epay_key', '') ?? '') !== '',
                'alipay' => app(PaymentChannels::class)->enabled('epay_alipay'),
                'wechat' => app(PaymentChannels::class)->enabled('epay_wechat')] : null];
    }

    /** @param array<string, string> $tabs
     * @return list<array{label: string, tabs: array<string, string>}>
     */
    private function navigationGroups(array $tabs): array
    {
        $groups = [];
        foreach ([
            '站点管理' => ['site'],
            '支付与财务' => ['payment', 'billing'],
            '通知通道' => ['mail', 'sms'],
            '账户与认证' => ['kyc', 'github', 'google', 'qq', 'wechat', 'telegram'],
            '连接与自动化' => ['upstreams', 'automation'],
        ] as $label => $keys) {
            $visible = [];
            foreach ($keys as $key) {
                if (isset($tabs[$key])) {
                    $visible[$key] = $tabs[$key];
                }
            }
            if ($visible !== []) {
                $groups[] = ['label' => $label, 'tabs' => $visible];
            }
        }

        return $groups;
    }

    /** @param list<string> $keys
     * @return list<array{title: string, keys: list<string>}>
     */
    private function fieldGroups(string $tab, array $keys): array
    {
        $definitions = match ($tab) {
            'site' => [
                '基础信息' => ['site.name', 'site.logo_url', 'site.support_url'],
                '页面展示' => ['site.icp_number', 'site.copyright', 'site.announcement'],
                '搜索与 AI 展示' => ['site.seo_title', 'site.seo_description', 'site.llms_description'],
            ],
            'mail' => [
                '通道设置' => ['mail.transport'],
                'SMTP 服务' => ['mail.host', 'mail.port'],
                '登录凭据' => ['mail.username', 'mail.password'],
                '发件信息' => ['mail.from_address', 'mail.from_name'],
            ],
            'sms' => [
                '通道设置' => ['sms.driver'],
                '手机绑定' => ['sms.binding_enabled', 'sms.binding_template'],
                '短信接口' => ['sms.idcsmart_endpoint', 'sms.idcsmart_api', 'sms.idcsmart_key', 'sms.idcsmart_sign'],
                '模板映射' => ['sms.idcsmart_templates'],
            ],
            'kyc' => [
                '认证设置' => ['kyc.enabled', 'kyc.driver'],
                '认证接口' => ['kyc.idcsmartali_endpoint', 'kyc.idcsmartali_api', 'kyc.idcsmartali_key', 'kyc.idcsmartali_biz_code'],
            ],
            'github', 'google', 'qq', 'wechat' => [
                '登录设置' => ['oauth.'.$tab.'_enabled'],
                '应用信息' => ['oauth.'.$tab.'_client_id', 'oauth.'.$tab.'_client_secret'],
            ],
            'telegram' => [
                '登录设置' => ['oauth.telegram_enabled'],
                '机器人信息' => ['telegram.bot_username', 'telegram.bot_token'],
            ],
            'billing' => [
                '账单与续费' => ['billing.invoice_expiry_minutes', 'billing.renewal_notice_days', 'billing.renewal_grace_days'],
                '佣金结算' => ['affiliate.freeze_days'],
            ],
            'automation' => [
                '生命周期处理' => ['lifecycle.urgent_batch', 'lifecycle.rotation_batch'],
                '商品同步' => ['lifecycle.catalog_batch'],
            ],
            default => [],
        };
        $groups = [];
        $remaining = $keys;
        foreach ($definitions as $title => $groupKeys) {
            $visible = array_values(array_intersect($groupKeys, $keys));
            if ($visible !== []) {
                $groups[] = ['title' => $title, 'keys' => $visible];
                $remaining = array_values(array_diff($remaining, $visible));
            }
        }
        if ($remaining !== []) {
            $groups[] = ['title' => '其他设置', 'keys' => $remaining];
        }

        return $groups;
    }

    /** @return array{string, string} */
    private function sectionPresentation(string $tab): array
    {
        return match ($tab) {
            'site' => ['站点设置', '设置商城品牌、公告、页脚和默认搜索展示。'],
            'payment' => ['支付设置', '配置易支付商户和客户可用的支付渠道。'],
            'mail' => ['邮件设置', '配置系统邮件的发送通道、登录凭据和发件信息。'],
            'sms' => ['短信设置', '管理短信服务、手机绑定和消息模板。'],
            'kyc' => ['实名认证', '配置客户实名认证使用的服务与认证场景。'],
            'github' => ['GitHub 登录', '配置 GitHub 授权应用，为客户提供第三方登录。'],
            'google' => ['Google 登录', '配置 Google 授权应用，为客户提供第三方登录。'],
            'qq' => ['QQ 登录', '配置 QQ 互联应用，为客户提供第三方登录。'],
            'wechat' => ['微信登录', '配置微信开放平台应用，为客户提供扫码登录。'],
            'telegram' => ['Telegram 设置', '配置机器人身份及 Telegram 登录。'],
            'billing' => ['计费与分佣', '调整新账单、续费提醒和新佣金的处理规则。'],
            'automation' => ['自动化策略', '设置生命周期处理和商品同步的每轮批量。'],
            'upstreams' => ['上游连接', '集中管理经典魔方和 Sub2API 服务连接。'],
            default => ['系统配置', '管理站点使用的服务与业务参数。'],
        };
    }

    /** @return array<string, string> */
    private function fieldHints(): array
    {
        $hints = [
            'site.name' => '显示在商城与客户中心的品牌位置。',
            'site.logo_url' => '支持完整 HTTP(S) 地址或 /images/logo.svg 这样的站内路径；留空不显示。',
            'site.support_url' => '支持 HTTP(S) 地址或站内路径；留空隐藏客服入口。',
            'site.icp_number' => '填写完整备案号；留空不显示。',
            'site.copyright' => '留空使用默认页脚文案。',
            'site.announcement' => '按纯文本展示，可换行；留空隐藏公告。',
            'site.seo_title' => '用于首页及缺少专属标题的页面；留空使用默认标题。',
            'site.seo_description' => '简要介绍站点内容；留空使用默认简介。',
            'site.llms_description' => '用于 AI 阅读索引中的站点介绍；留空使用默认简介。',
            'mail.transport' => 'SMTP 实际发送邮件；日志模拟仅记录发送结果。',
            'mail.host' => '例如 smtp.example.com。',
            'mail.port' => '465 使用隐式 TLS；587 按服务器支持协商 STARTTLS。',
            'mail.username' => '填写 SMTP 登录账户；留空保留已保存账户。',
            'mail.password' => '填写新的密码或授权码；留空保留已保存凭据。',
            'mail.from_address' => '填写收件人看到的发件邮箱。',
            'mail.from_name' => '填写收件人看到的发件名称。',
            'sms.driver' => '日志模拟不发送真实短信。',
            'sms.binding_template' => '与下方模板映射中的模板标识保持一致。',
            'sms.idcsmart_endpoint' => '填写服务商提供的完整 HTTPS 接口地址。',
            'sms.idcsmart_api' => '填写新的 API 标识；留空保留已保存凭据。',
            'sms.idcsmart_key' => '填写新的接口密钥；留空保留已保存凭据。',
            'sms.idcsmart_sign' => '填写服务商审核通过的短信签名。',
            'sms.idcsmart_templates' => '填写 JSON 对象，每项包含 id 和 content；测试短信使用 system.settings_test。',
            'kyc.idcsmartali_endpoint' => '填写服务商提供的完整 HTTPS 接口地址。',
            'kyc.idcsmartali_api' => '填写新的 API 标识；留空保留已保存凭据。',
            'kyc.idcsmartali_key' => '填写新的接口密钥；留空保留已保存凭据。',
            'telegram.bot_username' => '填写机器人用户名，不含 @。',
            'telegram.bot_token' => '填写 BotFather 提供的新 Token；留空保留已保存凭据。',
        ];
        foreach (['github', 'google', 'qq', 'wechat'] as $provider) {
            $hints['oauth.'.$provider.'_client_id'] = '填写授权应用的 Client ID。';
            $hints['oauth.'.$provider.'_client_secret'] = '填写新的 Client Secret；留空保留已保存凭据。';
        }

        return $hints;
    }
}
