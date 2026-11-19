<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\SettingService;
use App\Support\IdentitySettings;
use App\Support\PaymentChannels;
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
        $sections = IdentitySettings::sections();
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
            $value = $settings->get($key, $defaults[$key] ?? null);
            $values[$key] = IdentitySettings::isSecret($key)
                ? ['configured' => $value !== null && $value !== '', 'value' => '']
                : ['configured' => $value !== null, 'value' => $value ?? ''];
        }

        return ['tabs' => $tabs, 'activeTab' => $tab, 'section' => $section, 'values' => $values,
            'payment' => $tab === 'payment' ? ['url' => $settings->get('payment.epay_api_url', ''),
                'pid' => $settings->get('payment.epay_pid', ''),
                'has_key' => trim($settings->get('payment.epay_key', '') ?? '') !== '',
                'alipay' => app(PaymentChannels::class)->enabled('epay_alipay'),
                'wechat' => app(PaymentChannels::class)->enabled('epay_wechat')] : null];
    }
}
