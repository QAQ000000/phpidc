<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirects every request to /install until `storage/installed.lock` exists,
 * and returns 404 for /install* once it does (charter §3.6).
 *
 * Exemptions are explicit and narrow: the health probe must answer even on an
 * un-installed system, and the installer itself obviously cannot be redirected
 * to the installer.
 */
class EnsureInstalledMiddleware
{
    private const EXEMPT_PATHS = ['up', 'install', 'install/*'];

    public function handle(Request $request, Closure $next): Response
    {
        $installed = file_exists(storage_path('installed.lock'));

        if ($installed && $request->is('install', 'install/*')) {
            abort(404);
        }

        if (! $installed && ! $request->is(...self::EXEMPT_PATHS)) {
            return redirect('/install');
        }

        return $next($request);
    }
}
