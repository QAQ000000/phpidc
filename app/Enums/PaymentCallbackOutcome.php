<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Persisted in `payment_callbacks.outcome`. One row per (gateway, gateway_trade_no).
 * Branch table: docs/domain-invariants.md §4.
 */
enum PaymentCallbackOutcome: string
{
    case PROCESSED_PAID = 'processed_paid';
    case PROCESSED_LATE_CREDIT = 'processed_late_credit';
    case PROCESSED_OVERPAYMENT = 'processed_overpayment';
    case REJECTED_SHORT_AMOUNT = 'rejected_short_amount';
    case REJECTED_GATEWAY_MISMATCH = 'rejected_gateway_mismatch';
    case REJECTED_INVALID_STATE = 'rejected_invalid_state';
    case UNKNOWN_INVOICE = 'unknown_invoice';
    case IGNORED_DUPLICATE = 'ignored_duplicate';

    /** Did money move and did we accept the callback? */
    public function isAccepted(): bool
    {
        return in_array($this, [
            self::PROCESSED_PAID,
            self::PROCESSED_LATE_CREDIT,
            self::PROCESSED_OVERPAYMENT,
        ], true);
    }

    /** Must an operator be alerted? */
    public function requiresAlert(): bool
    {
        return ! in_array($this, [self::PROCESSED_PAID, self::IGNORED_DUPLICATE], true);
    }
}
