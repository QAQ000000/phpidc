<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\WalletCapacity;

/** @return array{credit: string, frozen_credit: string, wallet_clearance_frozen: string} */
function walletCapacityState(string $credit = '0.00', string $frozen = '0.00', string $clearance = '0.00'): array
{
    return ['credit' => $credit, 'frozen_credit' => $frozen, 'wallet_clearance_frozen' => $clearance];
}

function walletCapacityUser(array $state): User
{
    return (new User)->setRawAttributes($state, true);
}

it('requires an explicit complete wallet snapshot without including separate affiliate funds', function (): void {
    $state = walletCapacityState('12.34', '5.67', '8.90');
    $user = walletCapacityUser([...$state, 'affiliate_balance' => WalletCapacity::LIMIT, 'affiliate_frozen_balance' => WalletCapacity::LIMIT]);

    expect(WalletCapacity::snapshot($user))->toBe($state)
        ->and($user->getRawOriginal())->toBe([...$state, 'affiliate_balance' => WalletCapacity::LIMIT, 'affiliate_frozen_balance' => WalletCapacity::LIMIT]);
});

it('rejects a user snapshot missing any required wallet column instead of treating it as zero', function (string $column): void {
    $state = walletCapacityState();
    unset($state[$column]);

    expect(fn () => WalletCapacity::snapshot(walletCapacityUser($state)))
        ->toThrow(LogicException::class, 'A complete, migrated wallet snapshot is required.');
})->with(['credit', 'frozen_credit', 'wallet_clearance_frozen']);

it('accepts the exact wallet capacity in any component or split across all components', function (array $state): void {
    expect(WalletCapacity::columnsFit($state))->toBeTrue()
        ->and(WalletCapacity::allows(walletCapacityState(), $state))->toBeTrue();
})->with([
    'available' => [walletCapacityState(WalletCapacity::LIMIT)],
    'invoice hold' => [walletCapacityState(frozen: WalletCapacity::LIMIT)],
    'clearance hold' => [walletCapacityState(clearance: WalletCapacity::LIMIT)],
    'all three' => [walletCapacityState('9999999999.97', '0.01', '0.01')],
]);

it('rejects one cent above aggregate capacity even when every column fits', function (string $column): void {
    $before = walletCapacityState('9999999999.97', '0.01', '0.01');
    $after = $before;
    $after[$column] = match ($column) {
        'credit' => '9999999999.98',
        default => '0.02',
    };

    expect(WalletCapacity::columnsFit($after))->toBeTrue()
        ->and(WalletCapacity::allows($before, $after))->toBeFalse();
})->with(['credit', 'frozen_credit', 'wallet_clearance_frozen']);

it('rejects malformed or overflowing amounts in every wallet column', function (string $column, string $amount): void {
    $state = walletCapacityState();
    $state[$column] = $amount;

    expect(WalletCapacity::columnsFit($state))->toBeFalse()
        ->and(WalletCapacity::allows(walletCapacityState(), $state))->toBeFalse()
        ->and(WalletCapacity::allows($state, walletCapacityState()))->toBeFalse();
})->with(['credit', 'frozen_credit', 'wallet_clearance_frozen'])->with([
    'negative' => '-0.01',
    'column overflow' => '10000000000.00',
    'missing decimal place' => '1.0',
    'excess decimal place' => '1.001',
    'exponent' => '1e2',
]);

it('allows legacy aggregate excess to stay unchanged or decrease through complete transitions', function (array $after): void {
    $before = walletCapacityState('9999999998.00', '10.00', '5.00');

    expect(WalletCapacity::allows($before, $after))->toBeTrue();
})->with([
    'unchanged' => [walletCapacityState('9999999998.00', '10.00', '5.00')],
    'spend while still above limit' => [walletCapacityState('9999999997.00', '10.00', '5.00')],
    'invoice freeze' => [walletCapacityState('9999999997.00', '11.00', '5.00')],
    'invoice release' => [walletCapacityState('9999999999.00', '9.00', '5.00')],
    'clearance freeze' => [walletCapacityState('9999999997.00', '10.00', '6.00')],
    'clearance release' => [walletCapacityState('9999999999.00', '10.00', '4.00')],
    'clearance paid' => [walletCapacityState('9999999998.00', '10.00', '4.00')],
    'back within limit' => [walletCapacityState('9999999984.99', '10.00', '5.00')],
]);

it('blocks additional wallet value for legacy aggregate excess despite each column fitting', function (): void {
    $before = walletCapacityState('9999999998.00', '10.00', '5.00');
    $after = walletCapacityState('9999999998.01', '10.00', '5.00');

    expect(WalletCapacity::columnsFit($after))->toBeTrue()
        ->and(WalletCapacity::allows($before, $after))->toBeFalse();
});

it('never permits an overflowing component even when a legacy aggregate would decrease', function (string $column): void {
    $before = walletCapacityState(WalletCapacity::LIMIT, WalletCapacity::LIMIT, WalletCapacity::LIMIT);
    $after = walletCapacityState();
    $after[$column] = '10000000000.00';

    expect(WalletCapacity::allows($before, $after))->toBeFalse();
})->with(['credit', 'frozen_credit', 'wallet_clearance_frozen']);

it('includes clearance holds when checking a proposed wallet credit', function (): void {
    $user = walletCapacityUser(walletCapacityState('9999999998.00', '0.00', '1.99'));

    expect(WalletCapacity::canCredit($user, '0.00'))->toBeTrue()
        ->and(WalletCapacity::canCredit($user, '0.01'))->toBeFalse();
});

it('checks the complete credit and frozen consumption against the original snapshot without mutation', function (): void {
    $state = walletCapacityState('9999999998.00', '10.00', '5.00');
    $user = walletCapacityUser($state);

    expect(WalletCapacity::canCredit($user, '1.00', '10.00'))->toBeTrue()
        ->and(WalletCapacity::canCredit($user, '1.00'))->toBeFalse()
        ->and(WalletCapacity::snapshot($user))->toBe($state)
        ->and($user->getDirty())->toBe([]);
});

it('allows exact-capacity settlement but rejects insufficient frozen funds or a final column overflow', function (): void {
    $user = walletCapacityUser(walletCapacityState('9999999999.00', '0.99'));
    $fullCredit = walletCapacityUser(walletCapacityState(WalletCapacity::LIMIT, '1.00'));

    expect(WalletCapacity::canCredit($user, '0.99', '0.99'))->toBeTrue()
        ->and(WalletCapacity::canCredit($user, '1.00', '0.99'))->toBeFalse()
        ->and(WalletCapacity::canCredit($user, '0.01', '1.00'))->toBeFalse()
        ->and(WalletCapacity::canCredit($fullCredit, '0.01', '1.00'))->toBeFalse();
});
