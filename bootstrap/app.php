<?php

use App\Http\Middleware\EnsureInstalledMiddleware;
use App\Http\Middleware\InventoryNoStore;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(InventoryNoStore::class);
        $middleware->redirectGuestsTo(fn (Request $request): string => $request->is('admin', 'admin/*') ? '/admin/login' : '/login');
        $middleware->redirectUsersTo('/console');
        $middleware->trimStrings(except: ['codes', 'admin_password', 'admin_password_confirmation']);
        // Install redirects must not trap /up or /install (charter §3.6).
        $middleware->web(append: [
            EnsureInstalledMiddleware::class,
            AuthenticateSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['codes', 'api_secret', 'options', 'current_password', 'code', 'state', 'token', 'id_number', 'real_name',
            'install_token', 'admin_password', 'admin_password_confirmation', 'db_password', 'quote_token', 'proof',
            'payee_name', 'payee_account', 'bank_name', 'evidence', 'external_reference', 'correction_reference',
            'review_token', 'recipient', 'acceptance_reference', 'historical_endpoint_evidence']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
