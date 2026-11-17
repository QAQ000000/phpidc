<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('invoices:expire')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('fulfillment:dispatch-stale')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('cards:reap-reservations')->everyMinute()->withoutOverlapping()->onOneServer();
