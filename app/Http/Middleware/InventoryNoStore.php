<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InventoryNoStore
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->is('install', 'install/*', 'admin', 'admin/*', 'console', 'console/*', 'auth/*', 'login', 'register', 'forgot-password', 'reset-password', 'reset-password/*', 'email/*')) {
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }

        return $response;
    }
}
