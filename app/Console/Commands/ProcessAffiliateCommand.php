<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\RunMaintenanceBatchAction;
use Illuminate\Console\Command;

class ProcessAffiliateCommand extends Command
{
    protected $signature = 'affiliate:process';

    protected $description = 'Run separately bounded commission accrual and maturity batches';

    public function handle(RunMaintenanceBatchAction $action): int
    {
        $action->execute('affiliate-accrue');
        $action->execute('affiliate-mature');

        return self::SUCCESS;
    }
}
