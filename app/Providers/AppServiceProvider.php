<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\RunMaintenanceBatchAction;
use App\Contracts\MaintenanceBatchWork;
use App\Http\Middleware\EnsureAccountAccess;
use App\Models\Invoice;
use App\Models\User;
use App\Services\SettingService;
use App\Services\UpstreamOperationBuffer;
use App\Support\InstallationState;
use App\Support\WebRateLimits;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MaintenanceBatchWork::class, RunMaintenanceBatchAction::class);
        $this->app->scoped(UpstreamOperationBuffer::class);
        // Keep a consistent request/job snapshot; workers clear scoped instances between jobs.
        $this->app->scoped(SettingService::class, function (): SettingService {
            return new SettingService(Cache::store());
        });
    }

    public function boot(): void
    {
        WebRateLimits::register();
        Livewire::addPersistentMiddleware([EnsureAccountAccess::class]);
        Event::listen(Login::class, function (Login $event): void {
            if ($event->guard === 'web' && $event->user instanceof User && request()->hasSession()) {
                request()->session()->put(EnsureAccountAccess::REVISION_KEY, (int) $event->user->auth_revision);
            }
        });
        // Register a lazy, invoice-scoped authority without touching storage before installation.
        Gate::define('refund-invoice', fn (User $actor, ?Invoice $invoice = null): bool => $actor->exists && ! $actor->trashed()
            && $invoice?->exists && $actor->can('finance.view') && $actor->can('finance.refund'));
        // Nothing here may resolve settings before install: the database and
        // Redis may be unreachable, and AppServiceProvider::boot() runs on
        // every request including the installer's (charter §3.6).
        if (! file_exists(storage_path('installed.lock'))) {
            // The installer must remain reachable while Redis/database configuration is being prepared.
            config(['installation.session_driver' => config('session.driver'), 'session.driver' => 'file']);

            return;
        }

        if (is_file(storage_path('key-rotation.pending'))
            && ! ($this->app->runningInConsole() && in_array('app:reencrypt-secrets', $_SERVER['argv'] ?? [], true))) {
            throw new \RuntimeException('Key rotation is incomplete. Resume app:reencrypt-secrets in maintenance mode.');
        }
        app(InstallationState::class)->assertKey(requireSeal: $this->app->environment('production'));
    }
}
