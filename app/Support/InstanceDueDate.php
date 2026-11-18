<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;

final class InstanceDueDate
{
    public static function parse(string $value): Carbon
    {
        $timezone = (string) config('app.timezone');

        // DATETIME columns have no offset. Normalize before Eloquent formats them,
        // so reloading in the application's timezone preserves the same instant.
        return Carbon::parse($value, $timezone)->setTimezone($timezone);
    }
}
