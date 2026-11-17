<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\AffiliateWithdrawal;
use App\Models\FinancialTransaction;
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
        // C18: no automatic settlement exists; never infer payment from a fresh positive balance.
        foreach (FinancialTransaction::where('user_id', $userId)->where('type', TransactionType::AFFILIATE_CLAWBACK)->get(['meta']) as $row) {
            if (Money::gt($row->meta['receivable_amount'] ?? '0.00', '0.00')) {
                return true;
            }
        }

        return false;
    }
}
