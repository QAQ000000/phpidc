<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ReapRedemptionReservationsAction;
use Illuminate\Console\Command;

class ReapRedemptionReservationsCommand extends Command
{
    protected $signature = 'cards:reap-reservations';

    protected $description = 'Release expired undelivered card reservations';

    public function handle(ReapRedemptionReservationsAction $action): int
    {
        $action->execute();

        return self::SUCCESS;
    }
}
