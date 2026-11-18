<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\DispatchPendingNotificationsAction;
use Illuminate\Console\Command;

class DispatchPendingNotificationsCommand extends Command
{
    protected $signature = 'notifications:dispatch-pending';

    protected $description = 'Dispatch durable notification events and reconcile expired sending leases';

    public function handle(DispatchPendingNotificationsAction $action): int
    {
        $action->execute();

        return self::SUCCESS;
    }
}
