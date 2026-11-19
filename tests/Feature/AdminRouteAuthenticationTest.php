<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Jobs\ExecuteFulfillmentTaskJob;
use App\Models\CmsCategory;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route as Routes;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Http::preventStrayRequests();
    Queue::fake();
});

it('authenticates every explicit admin endpoint before resolving resources or running controller queries', function (): void {
    $routes = collect(Routes::getRoutes()->getRoutes())->filter(fn (Route $route): bool => str_starts_with($route->uri(), 'admin/')
        && str_starts_with($route->getActionName(), 'App\\Http\\Controllers\\'));
    expect($routes)->not->toBeEmpty();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    foreach ($routes as $route) {
        $uri = '/'.preg_replace('/\{[^}]+\}/', '999999999', $route->uri());
        $method = $route->methods()[0];
        $this->call($method, $uri)->assertRedirect('/admin/login');
        $this->json($method, $uri)->assertUnauthorized();
    }
    expect($queries)->toBe([]);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('keeps the admin login public and sends customer guests to their own login', function (): void {
    $this->get('/admin/login')->assertOk();
    $this->get('/console')->assertRedirect('/login');
    $this->getJson('/console')->assertUnauthorized();
});

it('does not replace business gates with the route authentication check', function (string $role): void {
    $actor = User::factory()->create();
    if ($role !== '') {
        app(AssignAdminRoleAction::class)->execute($actor, $role, 'Isolated route boundary acceptance');
    }
    $category = CmsCategory::create(['type' => 'help', 'slug' => 'route-guides', 'name' => 'Route guides']);
    $this->actingAs($actor)->postJson('/admin/content/articles', ['category_id' => $category->id, 'title' => 'Unauthorized draft',
        'slug' => 'unauthorized-draft', 'body' => 'Draft body', 'content_format' => 'markdown', 'is_published' => false])->assertForbidden();
    $this->postJson('/admin/finance/wallet-topup-settings', [])->assertForbidden();
    $this->assertDatabaseCount('cms_articles', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
})->with(['ordinary customer' => [''], 'content viewer' => ['cms-viewer']]);

it('retains all three retry paths and their operational permissions without scheduling a task twice', function (string $path, array $roles, DriverType $driver, string $redirect): void {
    $actor = User::factory()->create();
    foreach ($roles as $role) {
        app(AssignAdminRoleAction::class)->execute($actor, $role, 'Isolated retry compatibility acceptance');
    }
    $instance = Instance::factory()->create(['driver_type' => $driver,
        'status' => $driver === DriverType::ZJMF_API ? InstanceStatus::ACTIVE : InstanceStatus::PROVISION_FAILED]);
    $task = FulfillmentTask::factory()->create(['instance_id' => $instance->id, 'status' => FulfillmentTaskStatus::FAILED, 'attempts' => 5]);
    $uri = str_replace('{task}', (string) $task->id, $path);
    $body = ['reason' => 'Retry original failed task', 'confirm' => 1];
    $this->actingAs($actor)->postJson($uri, $body)->assertRedirect(str_replace('{task}', (string) $task->id, $redirect));
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::PENDING)->and($task->fresh()->attempts)->toBe(0);
    $this->postJson($uri, $body)->assertUnprocessable()->assertJsonValidationErrors('operation');
    $this->assertDatabaseCount('fulfillment_tasks', 1);
    $this->assertDatabaseCount('financial_transactions', 0);
    expect(DB::table('audit_logs')->where('action', 'fulfillment.retry')->count())->toBe(1);
    Queue::assertPushed(ExecuteFulfillmentTaskJob::class, 1);
    Http::assertNothingSent();
})->with([
    'inventory path' => ['/admin/fulfillment/{task}/retry', ['inventory-operator'], DriverType::REDEMPTION, '/admin/fulfillment'],
    'central task path' => ['/admin/tasks/{task}/retry', ['task-viewer', 'inventory-operator'], DriverType::REDEMPTION, '/admin/tasks?task_id={task}'],
    'classic path' => ['/admin/upstream-tasks/{task}/retry', ['upstream-lifecycle'], DriverType::ZJMF_API, '/admin/upstream-instances'],
]);

it('retains read-only rejection on every legacy retry path', function (string $path, string $role, DriverType $driver): void {
    $actor = User::factory()->create();
    app(AssignAdminRoleAction::class)->execute($actor, $role, 'Isolated read-only retry acceptance');
    $instance = Instance::factory()->create(['driver_type' => $driver]);
    $task = FulfillmentTask::factory()->create(['instance_id' => $instance->id, 'status' => FulfillmentTaskStatus::FAILED, 'attempts' => 5]);
    $this->actingAs($actor)->postJson(str_replace('{task}', (string) $task->id, $path), ['reason' => 'Read-only request', 'confirm' => 1])->assertForbidden();
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::FAILED)->and($task->fresh()->attempts)->toBe(5);
    expect(DB::table('audit_logs')->where('action', 'fulfillment.retry')->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with([
    'inventory path' => ['/admin/fulfillment/{task}/retry', 'inventory-viewer', DriverType::REDEMPTION],
    'central task path' => ['/admin/tasks/{task}/retry', 'task-viewer', DriverType::REDEMPTION],
    'classic path' => ['/admin/upstream-tasks/{task}/retry', 'upstream-viewer', DriverType::ZJMF_API],
]);
