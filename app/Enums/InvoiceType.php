<?php

declare(strict_types=1);

namespace App\Enums;

enum InvoiceType: string
{
    case NEW_ORDER = 'new_order';
    case RENEW = 'renew';
    case UPGRADE = 'upgrade';
    case DOWNGRADE = 'downgrade';
    case WALLET_TOPUP = 'wallet_topup';

    /** Types whose `billing_period_key` must be unique per instance (INV-K1). */
    public function requiresPeriodKey(): bool
    {
        return in_array($this, [self::RENEW, self::UPGRADE, self::DOWNGRADE], true);
    }

    /** Types that create or alter an instance and therefore need a fulfillment task. */
    public function createsFulfillmentTask(): bool
    {
        return in_array($this, [self::NEW_ORDER, self::RENEW, self::UPGRADE, self::DOWNGRADE], true);
    }
}
