<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\DispatchInstanceLifecycleAction;
use Illuminate\Console\Command;

class ProcessInstanceLifecycleCommand extends Command
{
    protected $signature = 'instances:process-lifecycle';

    protected $description = 'Reconcile due services, prepare renewal invoices and queue overdue controls';

    public function handle(DispatchInstanceLifecycleAction $action): int
    {
        $this->info('Dispatched '.$action->execute().' services.');

        return self::SUCCESS;
    }
}
