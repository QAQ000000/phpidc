<?php

declare(strict_types=1);

use App\Services\SettingService;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
});

it('refreshes settings between real worker jobs while retaining one task snapshot and encrypted shared cache', function (): void {
    DB::table('system_settings')->insert([
        ['group' => 'mail', 'key' => 'password', 'value' => Crypt::encryptString('original-fixture-secret'), 'is_encrypted' => true,
            'created_at' => now(), 'updated_at' => now()],
        ['group' => 'billing', 'key' => 'renewal_notice_days', 'value' => '7', 'is_encrypted' => false,
            'created_at' => now(), 'updated_at' => now()],
    ]);
    config(['queue.connections.database.after_commit' => false]);
    $queue = Queue::connection('database');
    $queue->push(static function (): void {
        $settings = app(SettingService::class);
        $original = $settings->get('mail.password') === 'original-fixture-secret';
        $initialDays = $settings->getInt('billing.renewal_notice_days') === 7;

        DB::table('system_settings')->where('group', 'mail')->where('key', 'password')
            ->update(['value' => Crypt::encryptString('updated-fixture-secret')]);
        DB::table('system_settings')->where('group', 'billing')->where('key', 'renewal_notice_days')->update(['value' => '3']);
        // A separate settings instance represents invalidation by the configuration writer.
        $writer = new SettingService(Cache::store());
        $writer->forget('mail.password');
        $writer->forget('billing.renewal_notice_days');

        Cache::put('settings-worker:first', [
            'original_secret' => $original, 'initial_days' => $initialDays,
            'same_scope' => app(SettingService::class) === $settings,
            'stable_secret' => $settings->get('mail.password') === 'original-fixture-secret',
            'stable_days' => $settings->getInt('billing.renewal_notice_days') === 7,
        ]);
    }, queue: 'settings-scope');
    $queue->push(static function (): void {
        $settings = app(SettingService::class);
        Cache::put('settings-worker:second', [
            'updated_secret' => $settings->get('mail.password') === 'updated-fixture-secret',
            'updated_days' => $settings->getInt('billing.renewal_notice_days') === 3,
        ]);
    }, queue: 'settings-scope');

    $handlers = [];
    $asyncSignals = extension_loaded('pcntl') ? pcntl_async_signals() : null;
    if ($asyncSignals !== null) {
        foreach ([SIGQUIT, SIGTERM, SIGINT, SIGUSR2, SIGCONT, SIGALRM] as $signal) {
            $handlers[$signal] = pcntl_signal_get_handler($signal);
        }
    }
    try {
        $status = app('queue.worker')->daemon('database', 'settings-scope', new WorkerOptions(
            memory: 512, timeout: 0, sleep: 0, stopWhenEmpty: true, maxJobs: 2,
        ));
    } finally {
        foreach ($handlers as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }
        if ($asyncSignals !== null) {
            pcntl_async_signals($asyncSignals);
        }
    }

    expect($status)->toBe(Worker::EXIT_SUCCESS)
        ->and(Cache::get('settings-worker:first'))->toBe([
            'original_secret' => true, 'initial_days' => true, 'same_scope' => true, 'stable_secret' => true, 'stable_days' => true,
        ])
        ->and(Cache::get('settings-worker:second'))->toBe(['updated_secret' => true, 'updated_days' => true])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
    $cached = Cache::get('setting:mail:password');
    expect($cached['is_encrypted'])->toBeTrue()
        ->and($cached['value'])->not->toContain('fixture-secret')
        ->and(Crypt::decryptString($cached['value']))->toBe('updated-fixture-secret');
});

it('keeps the settings preinstallation bypass without database or shared cache access', function (): void {
    unlink(storage_path('installed.lock'));
    $cache = Mockery::mock(Repository::class);
    $cache->shouldNotReceive('get');
    $cache->shouldNotReceive('put');
    DB::listen(fn () => throw new RuntimeException('Preinstallation settings touched the database.'));

    $settings = app(SettingService::class);
    expect($settings->get('mail.password', 'preinstallation-default'))->toBe('preinstallation-default')
        ->and($settings->getInt('billing.renewal_notice_days', 7))->toBe(7)
        ->and($settings->getBool('sms.binding_enabled'))->toBeFalse();
    expect((new SettingService($cache))->get('mail.password', 'preinstallation-default'))->toBe('preinstallation-default');
});
