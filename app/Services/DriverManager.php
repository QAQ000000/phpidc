<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\ConfigureEpayAction;
use App\Contracts\BotDriverInterface;
use App\Contracts\FulfillmentDriver;
use App\Contracts\MailDriverInterface;
use App\Contracts\PaymentGatewayInterface;
use App\Contracts\SmsDriverInterface;
use App\Drivers\Identity\SocialiteDriver;
use App\Drivers\Identity\TelegramLoginDriver;
use App\Drivers\Kyc\IdcsmartAliKycDriver;
use App\Drivers\Notification\IdcsmartSmsDriver;
use App\Drivers\Notification\LogMailDriver;
use App\Drivers\Notification\LogSmsDriver;
use App\Drivers\Notification\TelegramBotDriver;
use App\Drivers\Payment\EpayGateway;
use App\Drivers\SelfHosted\RedemptionCodeDriver;
use App\Drivers\SelfHosted\Sub2ApiClient;
use App\Drivers\SelfHosted\Sub2ApiDriver;
use App\Drivers\SelfHosted\Sub2ApiRemoteDriver;
use App\Drivers\Upstream\ZjmfClient;
use App\Drivers\Upstream\ZjmfFinanceDriver;
use App\Enums\DriverType;
use App\Models\PaymentAttempt;
use App\Models\UpstreamApi;
use App\Support\EpayProfiles;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GithubProvider;
use Laravel\Socialite\Two\GoogleProvider;
use SocialiteProviders\QQ\Provider;

class DriverManager
{
    public function socialProviders(): array
    {
        $settings = app(SettingService::class);
        $result = [];
        foreach (['github' => 'GitHub', 'google' => 'Google', 'qq' => 'QQ', 'wechat' => '微信开放平台'] as $key => $label) {
            $result[$key] = ['label' => $label, 'enabled' => $settings->getBool('oauth.'.$key.'_enabled')
                && trim($settings->get('oauth.'.$key.'_client_id', '') ?? '') !== ''
                && trim($settings->get('oauth.'.$key.'_client_secret', '') ?? '') !== ''];
        }

        $result['telegram'] = ['label' => 'Telegram', 'enabled' => $this->telegramLogin()->ready()];

        return $result;
    }

    public function telegramLogin(): TelegramLoginDriver
    {
        return app(TelegramLoginDriver::class);
    }

    public function kyc(): IdcsmartAliKycDriver
    {
        return app(IdcsmartAliKycDriver::class);
    }

    public function social(string $provider): SocialiteDriver
    {
        if (! ($this->socialProviders()[$provider]['enabled'] ?? false)) {
            throw new DomainException('此第三方登录尚未配置或未启用。');
        }
        $class = match ($provider) {
            'github' => GithubProvider::class,
            'google' => GoogleProvider::class,
            'qq' => Provider::class,
            'wechat' => \SocialiteProviders\WeChatWeb\Provider::class,
            default => throw new DomainException('Unsupported social provider.'),
        };
        $settings = app(SettingService::class);
        $adapter = Socialite::buildProvider($class, ['client_id' => $settings->get('oauth.'.$provider.'_client_id'),
            'client_secret' => $settings->get('oauth.'.$provider.'_client_secret'),
            'redirect' => rtrim((string) config('app.url'), '/').'/auth/'.$provider.'/callback',
            'guzzle' => ['timeout' => 15, 'connect_timeout' => 5]]);

        return new SocialiteDriver($adapter);
    }

    public function fulfillment(DriverType $type, ?int $upstreamApiId = null, array $panelWidgetTypes = []): FulfillmentDriver
    {
        return match ($type) {
            DriverType::ZJMF_API => $this->classicAccount($upstreamApiId, $panelWidgetTypes),
            DriverType::REDEMPTION => app(RedemptionCodeDriver::class),
            DriverType::SUB2API => $upstreamApiId === null ? app(Sub2ApiDriver::class) : $this->sub2ApiAccount($upstreamApiId),
            default => throw new DomainException('This fulfillment driver is not available yet.'),
        };
    }

    public function sub2ApiAccount(int $id): Sub2ApiRemoteDriver
    {
        $account = UpstreamApi::findOrFail($id);
        if (! $account->is_active || $account->driver !== DriverType::SUB2API || ($account->config['mode'] ?? null) !== 'remote_api'
            || ($account->config['protocol'] ?? null) !== 'balance-codes-v1' || trim($account->encrypted_secret ?? '') === '') {
            throw new DomainException('Sub2API remote mode requires an active verified balance-code connection.');
        }

        return new Sub2ApiRemoteDriver(new Sub2ApiClient($account, app(UpstreamOperationBuffer::class)));
    }

    public function classicAccount(?int $id, array $panelWidgetTypes = []): ZjmfFinanceDriver
    {
        $account = UpstreamApi::find($id);
        if ($account === null || ! $account->is_active || $account->driver !== DriverType::ZJMF_API) {
            throw new DomainException('An active classic ZJMF account is required.');
        }

        return new ZjmfFinanceDriver(new ZjmfClient($account, app(UpstreamOperationBuffer::class)), $account, $panelWidgetTypes);
    }

    public function mail(): MailDriverInterface
    {
        return app(LogMailDriver::class);
    }

    public function sms(): SmsDriverInterface
    {
        return match (app(SettingService::class)->get('sms.driver', 'log')) {
            'idcsmart' => app(IdcsmartSmsDriver::class),
            'log' => app(LogSmsDriver::class),
            default => throw new DomainException('Unsupported SMS transport.'),
        };
    }

    public function bot(): BotDriverInterface
    {
        return app(TelegramBotDriver::class);
    }

    /** @param array{api_url: string, pid: string, key: string}|null $profile */
    public function payment(string $identifier, ?array $profile = null): PaymentGatewayInterface
    {
        $channel = match ($identifier) {
            'epay_wechat' => 'wxpay',
            'epay_alipay' => 'alipay',
            default => throw new DomainException('Unsupported payment gateway.'),
        };
        $profile ??= app(SettingService::class)->isInstalled() ? app(EpayProfiles::class)->currentCredentials()
            : ['api_url' => '', 'pid' => '', 'key' => ''];

        return new EpayGateway($identifier, $channel, $profile['api_url'], $profile['pid'], $profile['key']);
    }

    public function paymentForCallback(string $identifier, Request $request): PaymentGatewayInterface
    {
        return DB::transaction(function () use ($identifier, $request): PaymentGatewayInterface {
            app(ConfigureEpayAction::class)->lockConfiguration();
            $outTradeNo = $request->input('out_trade_no');
            $attempt = is_string($outTradeNo) && strlen($outTradeNo) <= 64
                ? PaymentAttempt::where('gateway', $identifier)->where('out_trade_no', $outTradeNo)->first() : null;
            $profileId = $attempt?->request_payload[EpayProfiles::ATTEMPT_KEY] ?? null;
            $profiles = app(EpayProfiles::class);
            // Only an exact attempt can select history. Unmapped invoice numbers use the current merchant.
            $profile = $profileId === null ? $profiles->currentCredentials()
                : (is_string($profileId) ? $profiles->find($profileId) : throw new DomainException('Invalid payment credential profile.'));

            return $this->payment($identifier, $profile);
        });
    }
}
