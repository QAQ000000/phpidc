<?php

declare(strict_types=1);

use App\Http\Controllers\InstallController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Web installer (charter §3.6). Exempt from the install redirect; returns 404
// once installed.lock exists.
Route::get('/install', [InstallController::class, 'show'])->name('install.show');
Route::post('/install', [InstallController::class, 'store'])->name('install.store');

/*
 * Fallback: group middleware never runs when no route matches, so an
 * unmatched path would 404 before EnsureInstalledMiddleware could redirect.
 * Registering an explicit fallback keeps a pre-install system pointing at the
 * installer no matter what URL a visitor arrives on.
 */
Route::fallback(function () {
    if (! file_exists(storage_path('installed.lock'))) {
        return redirect('/install');
    }

    abort(404);
})->name('fallback');
