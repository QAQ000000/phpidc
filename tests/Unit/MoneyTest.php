<?php

declare(strict_types=1);

use App\Support\Money;

it('normalizes input to a 2dp non-negative string', function (): void {
    expect(Money::zero())->toBe('0.00')
        ->and(Money::of(10))->toBe('10.00')
        ->and(Money::of('10.5'))->toBe('10.50')
        ->and(Money::of('10.555'))->toBe('10.56');
})->group('phase0');

it('rejects floating-point input at the money boundary', function (): void {
    expect(fn () => Money::of(10.555))->toThrow(TypeError::class);
})->group('phase0');

it('rounds half-up exactly once', function (): void {
    expect(Money::round('10.005'))->toBe('10.01')
        ->and(Money::round('10.004'))->toBe('10.00');
})->group('phase0');

it('adds, subtracts and multiplies without float drift', function (): void {
    expect(Money::add('10.00', '5.50'))->toBe('15.50')
        ->and(Money::sub('10.00', '5.50'))->toBe('4.50')
        ->and(Money::sub('10.00', '10.00'))->toBe('0.00')
        ->and(Money::mul('10.00', 3))->toBe('30.00')
        ->and(Money::sum('1.00', '2.50', '3.25'))->toBe('6.75');
})->group('phase0');

it('refuses negative results and malformed money instead of silently coercing', function (): void {
    expect(fn () => Money::sub('1.00', '2.00'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::of('-1'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::add('10.0', '1.00'))->toThrow(InvalidArgumentException::class);
})->group('phase0');

it('computes percent with a single rounding point', function (): void {
    expect(Money::percentOf('10.00', '20'))->toBe('2.00')
        ->and(Money::percentOf('33.33', '15'))->toBe('5.00');
})->group('phase0');

it('satisfies the INV-I1 invoice identity', function (): void {
    $subtotal = '25.00';
    $discount = '3.00';
    $total = Money::sub($subtotal, $discount);
    $credit = Money::min('5.00', $total);
    $gateway = Money::sub($total, $credit);

    expect($total)->toBe('22.00')
        ->and($credit)->toBe('5.00')
        ->and($gateway)->toBe('17.00')
        ->and(Money::add($credit, $gateway))->toBe($total);
})->group('phase0', 'phase1');
