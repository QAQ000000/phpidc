<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\DispatchStalePendingTasksAction;
use Illuminate\Console\Command;

class DispatchStalePendingTasksCommand extends Command
{
    protected $signature = 'fulfillment:dispatch-stale';

    protected $description = 'Recover pending tasks and expired leases';

    public function handle(DispatchStalePendingTasksAction $action): int
    {
        $action->execute();

        return self::SUCCESS;
    }
}
