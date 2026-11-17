<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\ProvisionResult;
use App\Data\QuoteResult;
use App\Models\Instance;

/**
 * A resource with a recurring billing life. Lifecycle schedulers target ONLY
 * drivers implementing this interface (INV-S2) — never a driver string.
 */
interface RenewableResource
{
    public function quoteRenewal(Instance $instance, string $billingCycle): QuoteResult;

    public function renew(Instance $instance, string $billingCycle): ProvisionResult;

    public function suspend(Instance $instance, string $reason = ''): ProvisionResult;

    public function unsuspend(Instance $instance): ProvisionResult;

    public function terminate(Instance $instance, string $reason = ''): ProvisionResult;
}
