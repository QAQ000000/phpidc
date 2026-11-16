<?php

declare(strict_types=1);

namespace App\Enums;

enum InvoiceStatus: string
{
    case UNPAID = 'Unpaid';
    case PAID = 'Paid';
    case REFUNDED = 'Refunded';
    case CANCELLED = 'Cancelled';
    case EXPIRED = 'Expired';

    /** Unfreezing is valid only while the invoice is still open (INV-W4). */
    public function canReleaseFrozenCredit(): bool
    {
        return in_array($this, [self::UNPAID], true);
    }

    public function isSettled(): bool
    {
        return in_array($this, [self::PAID, self::REFUNDED], true);
    }
}
