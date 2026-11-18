<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ReapRedemptionReservationsAction;
use Illuminate\Console\Command;

class ReapRedemptionReservationsCommand extends Command
{
    protected $signature = 'cards:reap-reservations {--maintenance : Dispatch the bounded asynchronous maintenance batch}';

    protected $description = 'Release expired undelivered card reservations';

    public function handle(ReapRedemptionReservationsAction $action): int
    {
        if ($this->option('maintenance')) {
            return $this->call('maintenance:dispatch', ['kind' => 'cards']);
        }
        $action->execute();

        return self::SUCCESS;
    }
}
