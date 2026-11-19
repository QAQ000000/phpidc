<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ConfigureEpayAction;
use App\Actions\ConfigureIdentityAction;
use App\Actions\TestSystemConnectionAction;
use App\Models\User;
use App\Support\IdentitySettings;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use JsonException;

class SystemSettingsController extends Controller
{
    public function identity(Request $request, string $section): RedirectResponse
    {
        $definition = IdentitySettings::sections()[$section] ?? null;
        abort_if($definition === null, 404);
        $actor = $this->actor($request, $definition['permission']);
        $data = $request->validate(['settings' => ['required', 'array'], 'reason' => ['nullable', 'string', 'max:1000']]);
        $settings = $data['settings'];
        if (array_diff(array_keys($settings), IdentitySettings::keysForSection($section)) !== []) {
            throw ValidationException::withMessages(['settings' => '存在不属于此配置组的字段。']);
        }
        foreach ($settings as $key => $value) {
            if ($key === 'sms.idcsmart_templates' && is_string($value) && trim($value) !== '') {
                try {
                    $settings[$key] = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw ValidationException::withMessages(['settings' => '短信模板须为有效的 JSON 对象。']);
                }
            } elseif (($value === null || $value === '') && ! in_array($key, ['mail.from_name', 'mail.username', 'mail.password'], true)) {
                // Disabled providers need no repeated credentials; optional sender text may be cleared.
                unset($settings[$key]);
            }
        }
        app(ConfigureIdentityAction::class)->execute($settings, $this->reason($data, '保存'.$definition['label'].'配置'), $actor);

        return redirect('/admin/settings?tab='.$section)->with('inventory_status', '配置已保存；密钥留空时保留原值。');
    }

    public function payment(Request $request): RedirectResponse
    {
        $actor = $this->actor($request, 'settings.payment.manage');
        $data = $request->validate(['url' => ['required', 'string', 'max:2048'], 'pid' => ['required', 'string', 'max:255'],
            'epay_key' => ['nullable', 'string', 'max:4096'], 'alipay' => ['required', 'boolean'], 'wechat' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:1000']]);
        try {
            app(ConfigureEpayAction::class)->execute($data['url'], $data['pid'], $data['epay_key'] ?? '',
                $this->reason($data, '保存易支付接口配置'), $actor,
                ['epay_alipay' => $request->boolean('alipay'), 'epay_wechat' => $request->boolean('wechat')]);
        } catch (DomainException) {
            throw ValidationException::withMessages(['payment' => '支付配置未保存，请检查接口地址、商户凭据及历史配置状态。']);
        }

        return redirect('/admin/settings?tab=payment')->with('inventory_status', '支付配置已保存；历史支付继续使用原商户快照。');
    }

    public function test(Request $request, string $channel): RedirectResponse
    {
        abort_unless(in_array($channel, ['mail', 'sms', 'telegram'], true), 404);
        $actor = $this->actor($request, $channel === 'telegram' ? 'settings.identity.manage' : 'settings.notification.manage');
        $result = app(TestSystemConnectionAction::class)->execute($actor, $channel);
        $message = match ($result['status']) {
            'connected' => 'Telegram 连接成功：@'.$result['bot_username'],
            'sent' => '测试已提交发送，请检查本人接收账户。',
            'simulated' => '当前为日志通道，已模拟测试，没有真实发送。',
            'retryable' => '本次连接失败，可稍后手动重试。',
            'uncertain' => '发送结果不明，请先检查接收账户或平台记录，系统不会自动重发。',
            default => '测试未通过，请检查已保存配置。',
        };

        return redirect('/admin/settings?tab='.$channel)->with('inventory_status', $message);
    }

    private function actor(Request $request, string $permission): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && ! $actor->trashed() && $actor->anonymized_at === null, 403);
        Gate::forUser($actor)->authorize('settings.view');
        Gate::forUser($actor)->authorize($permission);

        return $actor;
    }

    private function reason(array $data, string $fallback): string
    {
        return trim($data['reason'] ?? '') ?: $fallback;
    }
}
