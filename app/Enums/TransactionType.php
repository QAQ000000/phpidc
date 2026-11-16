<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ledger vocabulary. Direction lives here, never in the sign of `amount` (INV-M2).
 * See INV-W5 for the authoritative table.
 */
enum TransactionType: string
{
    case CREDIT_IN = 'credit_in';
    case CREDIT_FREEZE = 'credit_freeze';
    case CREDIT_SETTLE = 'credit_settle';
    case CREDIT_UNFREEZE = 'credit_unfreeze';
    case PAYMENT = 'payment';
    case REFUND_WALLET = 'refund_wallet';
    case EXPENSE = 'expense';
    case AFFILIATE_COMMISSION = 'affiliate_commission';
    case AFFILIATE_CLAWBACK = 'affiliate_clawback';
    case AFFILIATE_PAYOUT = 'affiliate_payout';
    case ADJUSTMENT = 'adjustment';

    /** Does this type increase the user's available wallet balance? */
    public function isCredit(): bool
    {
        return in_array($this, [self::CREDIT_IN, self::REFUND_WALLET], true);
    }

    /** Does this type move value into the affiliate balance rather than the wallet? */
    public function isAffiliateBook(): bool
    {
        return in_array($this, [
            self::AFFILIATE_COMMISSION,
            self::AFFILIATE_CLAWBACK,
            self::AFFILIATE_PAYOUT,
        ], true);
    }
}
