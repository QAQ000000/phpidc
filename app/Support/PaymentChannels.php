<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SettingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** New-payment availability; historical receipt verification uses its immutable profile. */
class PaymentChannels
{
    private const LABELS = ['epay_wechat' => '微信', 'epay_alipay' => '支付宝'];

    public function enabled(string $gateway): bool
    {
        return isset(self::LABELS[$gateway]) && app(SettingService::class)->getBool('payment.'.$gateway.'_enabled', true);
    }

    /** @return array<string, string> */
    public function available(): array
    {
        return array_filter(self::LABELS, fn (string $gateway): bool => $this->enabled($gateway), ARRAY_FILTER_USE_KEY);
    }

    /** Call under the payment configuration lock when creating a new attempt. */
    public function assertEnabled(string $gateway): void
    {
        $enabled = isset(self::LABELS[$gateway]);
        if ($enabled && app(SettingService::class)->isInstalled()) {
            // Admission must not reuse a scoped or shared value read before a concurrent settings save.
            $value = DB::table('system_settings')->where('group', 'payment')->where('key', $gateway.'_enabled')->value('value');
            $enabled = $value === null || filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
        if (! $enabled) {
            throw ValidationException::withMessages(['gateway' => '此支付渠道已暂停，请选择其他可用渠道。']);
        }
    }
}
