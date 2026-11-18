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
    case AFFILIATE_FREEZE = 'affiliate_freeze';
    case AFFILIATE_UNFREEZE = 'affiliate_unfreeze';
    case AFFILIATE_RECEIVABLE_SETTLE = 'affiliate_receivable_settle';
    case AFFILIATE_RECEIVABLE_REVERSE = 'affiliate_receivable_reverse';
    case WALLET_CLEARANCE_HOLD = 'wallet_clearance_hold';
    case WALLET_CLEARANCE_PAID = 'wallet_clearance_paid';
    case WALLET_CLEARANCE_RELEASE = 'wallet_clearance_release';
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
            self::AFFILIATE_FREEZE,
            self::AFFILIATE_UNFREEZE,
        ], true);
    }
}
