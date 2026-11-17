<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

class CustomerDestination
{
    public static function pull(Request $request): string
    {
        $intended = $request->session()->pull('url.intended');
        if (! is_string($intended)) {
            return '/console';
        }
        $path = parse_url($intended, PHP_URL_PATH);
        if (! is_string($path) || ($path !== '/console' && ! str_starts_with($path, '/console/') && ! str_starts_with($path, '/email/verify/'))) {
            return '/console';
        }
        // Drop scheme and authority even if the stored URL is absolute.
        $query = parse_url($intended, PHP_URL_QUERY);

        return $path.($query === null || $query === false ? '' : '?'.$query);
    }
}
