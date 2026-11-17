<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\AccrueAffiliateCommissionAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AccrueAffiliateCommissionJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $invoiceId) {}

    public function handle(AccrueAffiliateCommissionAction $action): void
    {
        $action->execute($this->invoiceId);
    }
}
