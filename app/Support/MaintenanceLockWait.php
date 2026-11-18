<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;

class MaintenanceLockWait
{
    public function execute(Closure $callback): mixed
    {
        $connection = DB::connection();
        if ($connection->getDriverName() === 'mysql') {
            $original = (int) $connection->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS seconds')->seconds;
            $connection->statement('SET SESSION innodb_lock_wait_timeout = 2');
            try {
                return $callback();
            } finally {
                $connection->statement('SET SESSION innodb_lock_wait_timeout = '.max(1, $original));
            }
        }
        if ($connection->getDriverName() === 'sqlite') {
            $original = (int) $connection->selectOne('PRAGMA busy_timeout')->timeout;
            $connection->statement('PRAGMA busy_timeout = 2000');
            try {
                return $callback();
            } finally {
                $connection->statement('PRAGMA busy_timeout = '.max(0, $original));
            }
        }

        return $callback();
    }
}
