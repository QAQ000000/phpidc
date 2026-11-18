<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Billing cycles mirror ZJMF classic's 18 `billing_cycle` keys exactly.
 *
 * Human-approved C19: cycle fields hold period prices; the paired
 * `*setupfee` fields hold one-time setup fees.
 */
enum BillingCycle: string
{
    case FREE = 'free';
    case ONETIME = 'onetime';
    case HOUR = 'hour';
    case DAY = 'day';
    case ONTRIAL = 'ontrial';
    case MONTHLY = 'monthly';
    case QUARTERLY = 'quarterly';
    case SEMIANNUALLY = 'semiannually';
    case ANNUALLY = 'annually';
    case BIENNIALLY = 'biennially';
    case TRIENNIALLY = 'triennially';
    case FOURLY = 'fourly'; // ZJMF typo: NOT 'fourthly'
    case FIVELY = 'fively';
    case SIXLY = 'sixly';
    case SEVENLY = 'sevenly';
    case EIGHTLY = 'eightly';
    case NINELY = 'ninely';
    case TENLY = 'tenly';

    /** The upstream column holding this cycle's period price (C19). */
    public function priceKey(): ?string
    {
        return $this === self::FREE ? null : $this->value;
    }

    /** The independent, one-time setup fee column for this cycle (C19). */
    public function setupFeeKey(): ?string
    {
        return match ($this) {
            self::FREE => null,
            self::ONETIME => 'osetupfee',
            self::HOUR => 'hsetupfee',
            self::DAY => 'dsetupfee',
            self::ONTRIAL => 'ontrialfee',
            self::MONTHLY => 'msetupfee',
            self::QUARTERLY => 'qsetupfee',
            self::SEMIANNUALLY => 'ssetupfee',
            self::ANNUALLY => 'asetupfee',
            self::BIENNIALLY => 'bsetupfee',
            self::TRIENNIALLY => 'tsetupfee',
            self::FOURLY => 'foursetupfee',
            self::FIVELY => 'fivesetupfee',
            self::SIXLY => 'sixsetupfee',
            self::SEVENLY => 'sevensetupfee',
            self::EIGHTLY => 'eightsetupfee',
            self::NINELY => 'ninesetupfee',
            self::TENLY => 'tensetupfee',
        };
    }

    public function isRecurring(): bool
    {
        return $this !== self::FREE;
    }

    /** A cycle may be renewed only when it repeats; trials and one-offs never do. */
    public function isRenewable(): bool
    {
        return ! in_array($this, [self::FREE, self::ONETIME, self::ONTRIAL], true);
    }

    /** Number of months per period; null for non-month-aligned cycles. */
    public function months(): ?int
    {
        return match ($this) {
            self::MONTHLY => 1,
            self::QUARTERLY => 3,
            self::SEMIANNUALLY => 6,
            self::ANNUALLY => 12,
            self::BIENNIALLY => 24,
            self::TRIENNIALLY => 36,
            self::FOURLY => 48,
            self::FIVELY => 60,
            self::SIXLY => 72,
            self::SEVENLY => 84,
            self::EIGHTLY => 96,
            self::NINELY => 108,
            self::TENLY => 120,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::FREE => 'Free',
            self::ONETIME => 'One Time',
            self::HOUR => 'Hourly',
            self::DAY => 'Daily',
            self::ONTRIAL => 'Trial',
            self::MONTHLY => 'Monthly',
            self::QUARTERLY => 'Quarterly',
            self::SEMIANNUALLY => 'Semi-Annually',
            self::ANNUALLY => 'Annually',
            self::BIENNIALLY => 'Biennially',
            self::TRIENNIALLY => 'Triennially',
            self::FOURLY => '4 Years',
            self::FIVELY => '5 Years',
            self::SIXLY => '6 Years',
            self::SEVENLY => '7 Years',
            self::EIGHTLY => '8 Years',
            self::NINELY => '9 Years',
            self::TENLY => '10 Years',
        };
    }
}
