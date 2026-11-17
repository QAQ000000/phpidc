<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\FulfillmentDriver;
use App\Contracts\PaymentGatewayInterface;
use App\Drivers\Payment\EpayGateway;
use App\Drivers\SelfHosted\RedemptionCodeDriver;
use App\Enums\DriverType;
use DomainException;

class DriverManager
{
    public function fulfillment(DriverType $type, ?int $upstreamApiId = null): FulfillmentDriver
    {
        return match ($type) {
            DriverType::REDEMPTION => app(RedemptionCodeDriver::class),
            default => throw new DomainException('This fulfillment driver is not available yet.'),
        };
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
