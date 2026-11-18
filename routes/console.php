<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('invoices:expire --maintenance')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('fulfillment:dispatch-stale --maintenance')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('cards:reap-reservations --maintenance')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('maintenance:dispatch lifecycle')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('maintenance:dispatch catalog')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

Schedule::command('maintenance:dispatch affiliate')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('maintenance:dispatch notifications')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('app:receivables-audit')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
