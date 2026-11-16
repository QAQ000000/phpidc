<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\SettingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingService::class, function (): SettingService {
            return new SettingService(Cache::store());
        });
    }

    public function boot(): void
    {
        // Nothing here may resolve settings before install: the database and
        // Redis may be unreachable, and AppServiceProvider::boot() runs on
        // every request including the installer's (charter §3.6).
        if (! file_exists(storage_path('installed.lock'))) {
            return;
        }

        // Phase 1+: model observers, DriverManager bindings and scheduled
        // registrations go here.
    }
}
