<?php

use App\Http\Middleware\EnsureAccountAccess;
use App\Http\Middleware\EnsureInstalledMiddleware;
use App\Http\Middleware\InventoryNoStore;
use App\Support\IdentitySettings;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
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
        // Revoke sessions before Filament's authentication middleware can return a bare 403.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, EnsureAccountAccess::class);
        $middleware->redirectGuestsTo(fn (Request $request): string => $request->is('admin', 'admin/*') ? '/admin/login' : '/login');
        $middleware->redirectUsersTo('/console');
        $secretInputs = array_map(fn (string $key): string => 'settings.'.$key,
            array_keys(array_filter(IdentitySettings::fields(), fn (array $field): bool => $field['secret'] ?? false)));
        $middleware->trimStrings(except: ['codes', 'admin_password', 'admin_password_confirmation', 'epay_key', 'api_secret', ...$secretInputs]);
        // Install redirects must not trap /up or /install (charter §3.6).
        $middleware->web(append: [
            EnsureInstalledMiddleware::class,
            AuthenticateSession::class,
            EnsureAccountAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['codes', 'api_secret', 'options', 'current_password', 'code', 'state', 'token', 'id_number', 'real_name',
            'install_token', 'admin_password', 'admin_password_confirmation', 'db_password', 'quote_token', 'proof',
            'payee_name', 'payee_account', 'bank_name', 'evidence', 'external_reference', 'correction_reference',
            'review_token', 'recipient', 'acceptance_reference', 'historical_endpoint_evidence', 'settings', 'epay_key']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
