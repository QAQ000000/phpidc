<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The single source of truth for driver identifiers (INV-L4 / charter §2).
 * Code MUST NOT branch on these strings outside DriverManager; use capability
 * interfaces instead.
 */
enum DriverType: string
{
    case ZJMF_API = 'zjmf_api';
    case PROXMOX = 'proxmox';
    case SUB2API = 'sub2api';
    case REDEMPTION = 'redemption';
    case KYC_ZJMF_LEGACY = 'kyc_zjmf_legacy';

    /** Drivers that provision a customer-visible instance. */
    public function isFulfillment(): bool
    {
        return in_array($this, [self::ZJMF_API, self::PROXMOX, self::SUB2API, self::REDEMPTION], true);
    }

    /** Drivers whose resources live on our own infrastructure. */
    public function isSelfHosted(): bool
    {
        return in_array($this, [self::PROXMOX, self::SUB2API, self::REDEMPTION], true);
    }

    /** Drivers proxied to a remote ZJMF/upstream panel. */
    public function isUpstreamProxied(): bool
    {
        return $this === self::ZJMF_API;
    }
}
