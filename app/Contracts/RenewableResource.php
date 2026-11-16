<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\ProvisionResult;
use App\Models\Instance;

/**
 * A resource with a recurring billing life. Lifecycle schedulers target ONLY
 * drivers implementing this interface (INV-S2) — never a driver string.
 */
interface RenewableResource
{
    public function renew(Instance $instance, string $billingCycle): ProvisionResult;

    /** Called after a confirmed renewal; extends `next_due_at` from the current due date. */
    public function suspend(Instance $instance, string $reason = ''): ProvisionResult;

    public function unsuspend(Instance $instance): ProvisionResult;

    public function terminate(Instance $instance, string $reason = ''): ProvisionResult;
}
