<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AffiliateWithdrawal;
use App\Support\Money;

class AffiliateFunds
{
    public function pending(int $userId): string
    {
        $sum = Money::zero();
        foreach (AffiliateWithdrawal::where('user_id', $userId)->where('status', 'pending')->get(['amount']) as $withdrawal) {
            $sum = Money::add($sum, $withdrawal->amount);
        }

        return $sum;
    }

    public function hasReceivable(int $userId): bool
    {
        // D04: only verified append-only settlement/reversal evidence clears C11 debt.
        return app(AffiliateReceivableBalance::class)->hasOutstanding($userId);
    }
}
