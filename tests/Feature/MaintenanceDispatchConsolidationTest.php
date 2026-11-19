<?php

declare(strict_types=1);

use App\Actions\DispatchClassicCatalogAction;
use App\Actions\DispatchInstanceLifecycleAction;
use App\Actions\RunMaintenanceBatchAction;
use App\Enums\BillingCycle;
use App\Enums\DriverType;
use App\Enums\InstanceStatus;
use App\Jobs\ProbeClassicProductJob;
use App\Jobs\ProcessInstanceLifecycleJob;
use App\Models\Category;
use App\Models\Instance;
use App\Models\Product;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Services\SettingService;
use App\Support\MaintenanceBudget;
use App\Support\MaintenanceState;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    config(['queue.default' => 'database', 'maintenance.cache_store' => 'array']);
    Queue::fake();
    Http::preventStrayRequests();
});

function maintenanceDispatchSetting(string $key, int $value): void
{
    [$group, $name] = explode('.', $key, 2);
    DB::table('system_settings')->updateOrInsert(['group' => $group, 'key' => $name], ['value' => (string) $value, 'is_encrypted' => false]);
    app(SettingService::class)->forget($key);
}

function maintenanceDispatchInstances(int $count, bool $urgent = false): Collection
{
    return Instance::factory()->count($count)->active()->create(['user_id' => User::factory()->create()->id,
        'product_id' => Product::factory()->create()->id, 'next_due_at' => $urgent ? now()->addHour() : now()->addMonth()]);
}

function maintenanceDispatchProducts(int $count): Collection
{
    return Product::factory()->count($count)->create(['category_id' => Category::factory()->create()->id,
        'driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => UpstreamApi::factory()->create()->id]);
}

it('retains distinct lifecycle quotas and cursors after consolidating the dispatch owner', function (string $entry): void {
    maintenanceDispatchSetting('lifecycle.urgent_batch', 999);
    maintenanceDispatchSetting('lifecycle.rotation_batch', 999);
    $ordinary = maintenanceDispatchInstances(110);
    maintenanceDispatchInstances(100, urgent: true);
    Cache::forever('lifecycle-cursor', 0);
    $state = app(MaintenanceState::class);
    $state->advance('lifecycle', 0);

    if ($entry === 'manual') {
        expect(app(DispatchInstanceLifecycleAction::class)->execute())->toBe(200)
            ->and(Cache::get('lifecycle-cursor'))->toBe($ordinary[99]->id)
            ->and($state->cursor('lifecycle'))->toBe(0);
        $urgent = $rotation = 100;
    } else {
        $summary = app(RunMaintenanceBatchAction::class)->execute('lifecycle');
        expect($summary['selected'])->toBe(100)->and($summary['attempted'])->toBe(100)
            ->and($summary['units'])->toBe(100)->and($summary['failed'])->toBe(0)
            ->and(Cache::get('lifecycle-cursor'))->toBe(0)->and($state->cursor('lifecycle'))->toBe($ordinary[49]->id);
        $urgent = $rotation = 50;
    }

    Queue::assertPushed(ProcessInstanceLifecycleJob::class, $urgent + $rotation);
    expect(Queue::pushed(ProcessInstanceLifecycleJob::class)->where('queue', 'lifecycle-urgent'))->toHaveCount($urgent)
        ->and(Queue::pushed(ProcessInstanceLifecycleJob::class)->where('queue', 'lifecycle'))->toHaveCount($rotation);
    Http::assertNothingSent();
})->with(['manual', 'maintenance']);

it('preserves the different urgent queue classification for manual rotation and scheduled work', function (string $entry): void {
    maintenanceDispatchSetting('lifecycle.urgent_batch', 1);
    maintenanceDispatchSetting('lifecycle.rotation_batch', 2);
    $instances = maintenanceDispatchInstances(2, urgent: true);

    if ($entry === 'manual') {
        $this->artisan('instances:process-lifecycle')->expectsOutput('Dispatched 2 services.')->assertExitCode(0);
    } else {
        expect(app(RunMaintenanceBatchAction::class)->execute('lifecycle')['succeeded'])->toBe(2);
    }

    Queue::assertPushed(ProcessInstanceLifecycleJob::class, 2);
    Queue::assertPushed(ProcessInstanceLifecycleJob::class,
        fn (ProcessInstanceLifecycleJob $job): bool => $job->instanceId === $instances[0]->id && $job->queue === 'lifecycle-urgent');
    Queue::assertPushed(ProcessInstanceLifecycleJob::class,
        fn (ProcessInstanceLifecycleJob $job): bool => $job->instanceId === $instances[1]->id
            && $job->queue === ($entry === 'manual' ? 'lifecycle' : 'lifecycle-urgent'));
    Http::assertNothingSent();
})->with(['manual', 'maintenance']);

it('shares lifecycle eligibility while retaining completed and nonrecurring exclusions', function (string $entry): void {
    $included = maintenanceDispatchInstances(2);
    $included[1]->update(['status' => InstanceStatus::SUSPENDED, 'next_due_at' => null]);
    foreach ([BillingCycle::FREE, BillingCycle::ONETIME, BillingCycle::ONTRIAL] as $cycle) {
        Instance::factory()->active()->create(['billing_cycle' => $cycle]);
    }
    foreach ([InstanceStatus::PENDING, InstanceStatus::DELETED, InstanceStatus::CANCELLED] as $status) {
        Instance::factory()->create(['status' => $status]);
    }

    $entry === 'manual' ? app(DispatchInstanceLifecycleAction::class)->execute() : app(RunMaintenanceBatchAction::class)->execute('lifecycle');
    Queue::assertPushed(ProcessInstanceLifecycleJob::class, 2);
    expect(Queue::pushed(ProcessInstanceLifecycleJob::class)->pluck('instanceId')->sort()->values()->all())->toBe($included->pluck('id')->all());
    Http::assertNothingSent();
})->with(['manual', 'maintenance']);

it('retains catalog quotas and independent cursor ownership for both dispatch paths', function (string $entry, int $setting, int $global, int $expected): void {
    maintenanceDispatchSetting('lifecycle.catalog_batch', $setting);
    config(['maintenance.limit' => $global]);
    $products = maintenanceDispatchProducts(max(12, $expected + 5));
    Cache::forever('catalog-cursor', 0);
    $state = app(MaintenanceState::class);
    $state->advance('catalog', 0);

    if ($entry === 'manual') {
        expect(app(DispatchClassicCatalogAction::class)->execute())->toBe($expected)
            ->and(Cache::get('catalog-cursor'))->toBe($products[$expected - 1]->id)->and($state->cursor('catalog'))->toBe(0);
    } else {
        $summary = app(RunMaintenanceBatchAction::class)->execute('catalog');
        expect($summary['selected'])->toBe($expected)->and($summary['units'])->toBe($expected)
            ->and($summary['failed'])->toBe(0)->and(Cache::get('catalog-cursor'))->toBe(0)
            ->and($state->cursor('catalog'))->toBe($products[$expected - 1]->id);
    }

    Queue::assertPushed(ProbeClassicProductJob::class, $expected);
    Http::assertNothingSent();
})->with([
    ['manual', 999, 100, 200], ['maintenance', 999, 100, 100], ['maintenance', 2, 100, 2],
    ['maintenance', 999, 10, 10], ['maintenance', 1, 100, 1],
]);

it('keeps catalog account and driver eligibility identical across both dispatch paths', function (string $entry): void {
    $included = maintenanceDispatchProducts(2);
    Product::factory()->create();
    Product::factory()->create(['driver_type' => DriverType::ZJMF_API]);
    Product::factory()->create(['driver_type' => DriverType::ZJMF_API,
        'upstream_api_id' => UpstreamApi::factory()->create(['is_active' => false])->id]);

    $entry === 'manual' ? app(DispatchClassicCatalogAction::class)->execute() : app(RunMaintenanceBatchAction::class)->execute('catalog');
    Queue::assertPushed(ProbeClassicProductJob::class, 2);
    expect(Queue::pushed(ProbeClassicProductJob::class)->pluck('productId')->sort()->values()->all())->toBe($included->pluck('id')->all());
    Http::assertNothingSent();
})->with(['manual', 'maintenance']);

it('shares each domain dispatch lock between legacy and maintenance entry points', function (string $kind, string $action): void {
    $kind === 'lifecycle' ? maintenanceDispatchInstances(2) : maintenanceDispatchProducts(2);
    $lock = Cache::lock($kind.'-dispatch', 50);
    expect($lock->get())->toBeTrue();

    expect(app($action)->execute())->toBe(0);
    $summary = app(RunMaintenanceBatchAction::class)->execute($kind);
    expect($summary)->toBe(['selected' => 0, 'attempted' => 0, 'succeeded' => 0, 'failed' => 0,
        'units' => 0, 'deferred' => 0, 'budget_exhausted' => false]);
    Queue::assertNothingPushed();
    $lock->release();
    expect(app(RunMaintenanceBatchAction::class)->execute($kind)['succeeded'])->toBe(2);
    Http::assertNothingSent();
})->with([['lifecycle', DispatchInstanceLifecycleAction::class], ['catalog', DispatchClassicCatalogAction::class]]);

it('uses the caller maintenance deadline without resetting it in the domain dispatch action', function (string $kind, string $action): void {
    $kind === 'lifecycle' ? maintenanceDispatchInstances(10, urgent: true) : maintenanceDispatchProducts(10);
    $time = 0.0;
    $budget = new MaintenanceBudget(function () use (&$time): float {
        return $time;
    });
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->times(3)->andReturnUsing(function () use (&$time): void {
        $time += 2.0;
    });
    app()->instance(Dispatcher::class, $dispatcher);

    $summary = app($action)->executeMaintenance($budget);
    expect($summary['attempted'])->toBe(3)->and($summary['units'])->toBe(3)
        ->and($summary['budget_exhausted'])->toBeTrue()->and($budget->used())->toBe(3)->and($time)->toBe(6.0);
    Http::assertNothingSent();
})->with([['lifecycle', DispatchInstanceLifecycleAction::class], ['catalog', DispatchClassicCatalogAction::class]]);

it('retains manual error propagation and maintenance item backoff for domain dispatch failure', function (string $kind, string $action, string $entry): void {
    $kind === 'lifecycle' ? maintenanceDispatchInstances(3, urgent: true) : maintenanceDispatchProducts(3);
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->times($entry === 'manual' ? 1 : 3)->andThrow(new DomainException('PRIVATE-DISPATCH-FAILURE'));
    app()->instance(Dispatcher::class, $dispatcher);
    $state = app(MaintenanceState::class);

    if ($entry === 'manual') {
        expect(fn () => app($action)->execute())->toThrow(DomainException::class, 'PRIVATE-DISPATCH-FAILURE')
            ->and(Cache::get($kind.'-cursor', 0))->toBe(0)->and($state->failures($kind))->toBe([]);
    } else {
        $summary = app(RunMaintenanceBatchAction::class)->execute($kind);
        expect($summary['attempted'])->toBe(3)->and($summary['failed'])->toBe(3)->and($state->failures($kind))->toHaveCount(3)
            ->and(json_encode($state->failures($kind), JSON_THROW_ON_ERROR))->not->toContain('PRIVATE-DISPATCH-FAILURE');
        $deferred = app(RunMaintenanceBatchAction::class)->execute($kind);
        expect($deferred['attempted'])->toBe(0)->and($deferred['deferred'])->toBe(3);
    }

    Http::assertNothingSent();
})->with([
    ['lifecycle', DispatchInstanceLifecycleAction::class, 'manual'], ['lifecycle', DispatchInstanceLifecycleAction::class, 'maintenance'],
    ['catalog', DispatchClassicCatalogAction::class, 'manual'], ['catalog', DispatchClassicCatalogAction::class, 'maintenance'],
]);

it('rejects synchronous queues from the consolidated maintenance delegates', function (string $kind): void {
    config(['queue.default' => 'sync']);
    expect(fn () => app(RunMaintenanceBatchAction::class)->execute($kind))->toThrow(LogicException::class);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(['lifecycle', 'catalog']);
