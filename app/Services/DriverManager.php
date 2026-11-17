<?php

declare(strict_types=1);

namespace App\Services;

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
use App\Models\UpstreamApi;
use DomainException;
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

    public function fulfillment(DriverType $type, ?int $upstreamApiId = null): FulfillmentDriver
    {
        return match ($type) {
            DriverType::ZJMF_API => $this->classicAccount($upstreamApiId),
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

    public function classicAccount(?int $id): ZjmfFinanceDriver
    {
        $account = UpstreamApi::find($id);
        if ($account === null || ! $account->is_active || $account->driver !== DriverType::ZJMF_API) {
            throw new DomainException('An active classic ZJMF account is required.');
        }

        return new ZjmfFinanceDriver(new ZjmfClient($account, app(UpstreamOperationBuffer::class)), $account);
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

    public function payment(string $identifier): PaymentGatewayInterface
    {
        $channel = match ($identifier) {
            'epay_wechat' => 'wxpay',
            'epay_alipay' => 'alipay',
            default => throw new DomainException('Unsupported payment gateway.'),
        };
        $settings = app(SettingService::class);

        return new EpayGateway($identifier, $channel, $settings->get('payment.epay_api_url', '') ?? '',
            $settings->get('payment.epay_pid', '') ?? '', $settings->get('payment.epay_key', '') ?? '');
    }
}
