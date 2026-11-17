<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Fixed-point money arithmetic (INV-M1, INV-M2, INV-M3).
 *
 * Every value is a non-negative decimal string with exactly two fractional
 * digits. Direction is expressed by TransactionType, never by a minus sign.
 * bcmath functions are used with scale 2 throughout.
 */
final class Money
{
    public const SCALE = 2;

    private function __construct() {}

    public static function zero(): string
    {
        return '0.00';
    }

    /** Normalize decimal strings or whole units; float inputs are forbidden (INV-M1). */
    public static function of(int|string $value): string
    {
        $value = trim((string) $value);
        if ($value === '' || ! preg_match('/^\+?\d+(\.\d+)?$/', $value)) {
            throw new InvalidArgumentException("Invalid money value: {$value}");
        }

        $value = ltrim($value, '+');

        return self::round($value);
    }

    /** Half-up rounding to 2dp for non-negative inputs; single rounding point (INV-M3). */
    public static function round(string $value): string
    {
        self::assertNumeric($value);

        return bcadd($value, '0.005', self::SCALE);
    }

    public static function add(string $a, string $b): string
    {
        self::assertMoney($a);
        self::assertMoney($b);

        return bcadd($a, $b, self::SCALE);
    }

    /** Subtraction that refuses to go negative — money has no sign here. */
    public static function sub(string $a, string $b): string
    {
        self::assertMoney($a);
        self::assertMoney($b);

        $result = bcsub($a, $b, self::SCALE);
        if (bccomp($result, '0', self::SCALE) < 0) {
            throw new InvalidArgumentException("Money subtraction would be negative: {$a} - {$b}");
        }

        return $result;
    }

    /** Clamped subtraction, for "how much is left" computations where 0 is a valid floor. */
    public static function subFloorZero(string $a, string $b): string
    {
        $result = bcsub($a, $b, self::SCALE);

        return bccomp($result, '0', self::SCALE) < 0 ? self::zero() : $result;
    }

    public static function mul(string $amount, int|string $factor): string
    {
        self::assertMoney($amount);

        return self::round(bcmul($amount, (string) $factor, self::SCALE + 2));
    }

    /** Multiply by a percentage (e.g. markup), returning money at 2dp. */
    public static function percentOf(string $amount, string $percent): string
    {
        self::assertMoney($amount);
        self::assertNumeric($percent);

        $raw = bcmul($amount, bcdiv($percent, '100', self::SCALE + 4), self::SCALE + 4);

        return self::round($raw);
    }

    public static function min(string $a, string $b): string
    {
        return bccomp($a, $b, self::SCALE) <= 0 ? $a : $b;
    }

    public static function isZero(string $a): bool
    {
        return bccomp($a, '0', self::SCALE) === 0;
    }

    public static function gt(string $a, string $b): bool
    {
        return bccomp($a, $b, self::SCALE) > 0;
    }

    public static function gte(string $a, string $b): bool
    {
        return bccomp($a, $b, self::SCALE) >= 0;
    }

    public static function eq(string $a, string $b): bool
    {
        return bccomp($a, $b, self::SCALE) === 0;
    }

    /** Sum a list of money strings. */
    public static function sum(string ...$amounts): string
    {
        $total = self::zero();
        foreach ($amounts as $amount) {
            $total = self::add($total, $amount);
        }

        return $total;
    }

    private static function assertMoney(string $value): void
    {
        if (! preg_match('/^\d+\.\d{2}$/', $value)) {
            throw new InvalidArgumentException("Not a 2dp money string: {$value}");
        }
    }

    private static function assertNumeric(string $value): void
    {
        if (! preg_match('/^\d+(\.\d+)?$/', $value)) {
            throw new InvalidArgumentException("Not a non-negative decimal: {$value}");
        }
    }
}
