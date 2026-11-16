<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Instance lifecycle. Transitions are constrained by INV-S1:
 *   Pending -> Active -> Suspended -> Cancelled|Deleted
 *   Pending -> ProvisionFailed
 */
enum InstanceStatus: string
{
    case PENDING = 'Pending';
    case ACTIVE = 'Active';
    case SUSPENDED = 'Suspended';
    case CANCELLED = 'Cancelled';
    case FRAUD = 'Fraud';
    case DELETED = 'Deleted';
    case PROVISION_FAILED = 'ProvisionFailed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::CANCELLED, self::DELETED], true);
    }

    /** Statuses that still consume upstream resources and must be billed. */
    public function isBillable(): bool
    {
        return in_array($this, [self::ACTIVE, self::SUSPENDED], true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PENDING => [self::ACTIVE, self::PROVISION_FAILED, self::CANCELLED],
            self::PROVISION_FAILED => [self::PENDING, self::ACTIVE, self::CANCELLED],
            self::ACTIVE => [self::SUSPENDED, self::CANCELLED, self::DELETED, self::FRAUD],
            self::SUSPENDED => [self::ACTIVE, self::CANCELLED, self::DELETED, self::FRAUD],
            self::FRAUD => [self::CANCELLED, self::DELETED],
            self::CANCELLED, self::DELETED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
