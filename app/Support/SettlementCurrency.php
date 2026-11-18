<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class SettlementCurrency
{
    public const CODE = 'CNY';

    public static function supports(string $currency): bool
    {
        return $currency === self::CODE;
    }

    public static function assertSupported(string $currency): void
    {
        if (! self::supports($currency)) {
            throw ValidationException::withMessages(['currency' => '目前仅支持 CNY 结算；其他币种账单需人工核对。']);
        }
    }
}
