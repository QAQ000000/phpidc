<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use LogicException;

final class WalletCapacity
{
    public const LIMIT = '9999999999.99';

    /** @return array{credit: string, frozen_credit: string, wallet_clearance_frozen: string} */
    public static function snapshot(User $user): array
    {
        return ['credit' => self::amount($user, 'credit'), 'frozen_credit' => self::amount($user, 'frozen_credit'),
            'wallet_clearance_frozen' => self::amount($user, 'wallet_clearance_frozen')];
    }

    /** @param array{credit: string, frozen_credit: string, wallet_clearance_frozen: string} $state */
    public static function columnsFit(array $state): bool
    {
        foreach ($state as $amount) {
            if (preg_match('/^\d{1,10}\.\d{2}$/D', $amount) !== 1 || Money::gt($amount, self::LIMIT)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate the complete atomic result, including consumption of invoice holds.
     * Legacy aggregate excess may be reduced or moved, never increased.
     *
     * @param  array{credit: string, frozen_credit: string, wallet_clearance_frozen: string}  $before
     * @param  array{credit: string, frozen_credit: string, wallet_clearance_frozen: string}  $after
     */
    public static function allows(array $before, array $after): bool
    {
        if (! self::columnsFit($before) || ! self::columnsFit($after)) {
            return false;
        }
        $totalBefore = Money::add(Money::add($before['credit'], $before['frozen_credit']), $before['wallet_clearance_frozen']);
        $totalAfter = Money::add(Money::add($after['credit'], $after['frozen_credit']), $after['wallet_clearance_frozen']);

        return ! Money::gt($totalAfter, self::LIMIT) || ! Money::gt($totalAfter, $totalBefore);
    }

    /** The caller must use the original locked snapshot before either movement. */
    public static function canCredit(User $user, string $amount, string $consumeFrozen = '0.00'): bool
    {
        $before = self::snapshot($user);
        if (Money::gt($consumeFrozen, $before['frozen_credit'])) {
            return false;
        }

        return self::allows($before, [...$before,
            'credit' => Money::add($before['credit'], $amount),
            'frozen_credit' => Money::sub($before['frozen_credit'], $consumeFrozen),
        ]);
    }

    private static function amount(User $user, string $column): string
    {
        $value = $user->getAttribute($column);
        if (! is_string($value) || preg_match('/^\d{1,10}\.\d{2}$/D', $value) !== 1) {
            throw new LogicException('A complete, migrated wallet snapshot is required.');
        }

        return $value;
    }
}
