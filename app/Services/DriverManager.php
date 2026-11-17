<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\FulfillmentDriver;
use App\Contracts\PaymentGatewayInterface;
use App\Drivers\Payment\EpayGateway;
use App\Drivers\SelfHosted\RedemptionCodeDriver;
use App\Drivers\Upstream\ZjmfClient;
use App\Drivers\Upstream\ZjmfFinanceDriver;
use App\Enums\DriverType;
use App\Models\UpstreamApi;
use DomainException;

class DriverManager
{
    public function fulfillment(DriverType $type, ?int $upstreamApiId = null): FulfillmentDriver
    {
        return match ($type) {
            DriverType::ZJMF_API => $this->classicAccount($upstreamApiId),
            DriverType::REDEMPTION => app(RedemptionCodeDriver::class),
            default => throw new DomainException('This fulfillment driver is not available yet.'),
        };
    }

    public function classicAccount(?int $id): ZjmfFinanceDriver
    {
        $account = UpstreamApi::find($id);
        if ($account === null || ! $account->is_active || $account->driver !== DriverType::ZJMF_API) {
            throw new DomainException('An active classic ZJMF account is required.');
        }

        return new ZjmfFinanceDriver(new ZjmfClient($account, app(UpstreamOperationBuffer::class)), $account);
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
