<?php

declare(strict_types=1);
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| Runbook conformance — Phase 0
|--------------------------------------------------------------------------
|
| This file holds only cases that pass on the current tree. Behaviour that is
| not implemented yet lives in tests/Specs and is run explicitly.
|
| A red case here is a real regression. Never make it green by weakening the
| assertion; see docs/agent-guardrails.md.
|
*/

uses(RefreshDatabase::class);

it('boots application, serves /up even before install, and locks /install after installed.lock', function (): void {
    @unlink(storage_path('installed.lock'));
    $this->get('/up')->assertOk();
    $this->get('/')->assertRedirect('/install');

    file_put_contents(storage_path('installed.lock'), 'locked');
    $this->get('/up')->assertOk();
    $this->get('/install')->assertNotFound();
    @unlink(storage_path('installed.lock'));
})->group('phase0');

it('returns 404 for the installer once installed.lock exists, but still serves /up', function (): void {
    file_put_contents(storage_path('installed.lock'), 'locked');
    $this->get('/install')->assertNotFound();
    $this->get('/up')->assertOk();
    @unlink(storage_path('installed.lock'));
})->group('phase0');

it('redirects unknown web paths to the installer before install', function (): void {
    @unlink(storage_path('installed.lock'));
    $this->get('/console/anything')->assertRedirect('/install');
})->group('phase0');

it('does not open a database connection while reading settings before install', function (): void {
    @unlink(storage_path('installed.lock'));

    $service = app(SettingService::class);

    expect($service->isInstalled())->toBeFalse()
        ->and($service->get('payment.epay_pid'))->toBeNull()
        ->and($service->get('payment.epay_pid', 'fallback'))->toBe('fallback');
})->group('phase0');

it('rejects a setting key that is not in group.key form', function (): void {
    // The pre-install bypass returns before key parsing, so the lock must exist
    // for this path to be reachable at all.
    file_put_contents(storage_path('installed.lock'), 'locked');

    try {
        expect(fn () => app(SettingService::class)->get('notagroupkey'))
            ->toThrow(InvalidArgumentException::class);
    } finally {
        @unlink(storage_path('installed.lock'));
    }
})->group('phase0');

it('refuses to claim the installer succeeded while it is unimplemented', function (): void {
    @unlink(storage_path('installed.lock'));

    $response = $this->post('/install', [
        'app_url' => 'https://cloud.example.com',
        'db_host' => '127.0.0.1',
        'db_port' => 3306,
        'db_database' => 'cloud_cms',
        'db_username' => 'root',
    ]);

    $response->assertSessionHasErrors('install');
    expect(file_exists(storage_path('installed.lock')))->toBeFalse();
})->group('phase0');
