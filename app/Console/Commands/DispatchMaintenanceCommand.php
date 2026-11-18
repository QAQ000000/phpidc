<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\MaintenanceBatchWork;
use App\Jobs\RunMaintenanceBatchJob;
use App\Support\MaintenanceState;
use Illuminate\Console\Command;
use LogicException;

class DispatchMaintenanceCommand extends Command
{
    protected $signature = 'maintenance:dispatch {kind : invoices|fulfillment|cards|lifecycle|catalog|affiliate|notifications}';

    protected $description = 'Dispatch one bounded local maintenance family to its separate queue';

    public function handle(MaintenanceState $state): int
    {
        if (config('queue.connections.'.config('queue.default').'.driver') === 'sync') {
            throw new LogicException('Maintenance dispatch requires an asynchronous queue.');
        }
        if (! app()->bound(MaintenanceBatchWork::class)) {
            throw new LogicException('Maintenance handlers have not been activated for this deployment.');
        }
        $kind = (string) $this->argument('kind');
        $kinds = $kind === 'affiliate' ? ['affiliate-accrue', 'affiliate-mature'] : [$kind];
        foreach ($kinds as $selected) {
            $state->assertKind($selected);
            if (! in_array($selected, config('maintenance.kinds'), true)) {
                throw new LogicException('Only a complete maintenance family may be queued.');
            }
            RunMaintenanceBatchJob::dispatch($selected);
            $state->heartbeat($selected, 'dispatched');
        }

        return self::SUCCESS;
    }
}
