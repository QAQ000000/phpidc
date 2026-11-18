<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\DispatchStalePendingTasksAction;
use Illuminate\Console\Command;

class DispatchStalePendingTasksCommand extends Command
{
    protected $signature = 'fulfillment:dispatch-stale {--maintenance : Dispatch the bounded asynchronous maintenance batch}';

    protected $description = 'Recover pending tasks and expired leases';

    public function handle(DispatchStalePendingTasksAction $action): int
    {
        if ($this->option('maintenance')) {
            return $this->call('maintenance:dispatch', ['kind' => 'fulfillment']);
        }
        $action->execute();

        return self::SUCCESS;
    }
}
