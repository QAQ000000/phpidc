<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Access revocation only: retained business identities remain visible to workers and callbacks. */
class EnsureAccountAccess
{
    public const REVISION_KEY = 'auth.account_revision';

    public function handle(Request $request, Closure $next): Response
    {
        if (! file_exists(storage_path('installed.lock')) || ! $request->hasSession()) {
            return $next($request);
        }
        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request);
        }
        // The session guard loads the current identity for this HTTP request. Legacy sessions
        // have revision zero; new logins record their actual revision through the Login event.
        $revision = (int) $request->session()->get(self::REVISION_KEY, 0);
        if (! $user->isAccessEnabled() || $revision !== (int) $user->auth_revision) {
            $closedAdmin = ($user->trashed() || $user->anonymized_at !== null)
                && $request->is('admin', 'admin/*');
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            // Preserve the panel's existing closed-account denial while revoking its session.
            abort_if($closedAdmin, 403);
            if ($request->expectsJson()) {
                return response()->json(['message' => '账户不可用或登录已失效，请重新登录。'], 401);
            }

            return redirect($request->is('admin', 'admin/*') ? '/admin/login' : '/login')
                ->withErrors(['email' => '账户不可用或登录已失效，请重新登录。']);
        }

        return $next($request);
    }
}
