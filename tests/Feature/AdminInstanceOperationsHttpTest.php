<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Actions\SyncAdminInstanceAction;
use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InvoiceStatus;
use App\Jobs\ExecuteFulfillmentTaskJob;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Support\AdminRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Http::preventStrayRequests();
    Queue::fake();
});

function c4HttpActor(array $permissions = ['upstream.view', 'upstream.machine-control']): User
{
    $actor = User::factory()->create();
    foreach ($permissions as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $actor;
}

function c4HttpInstance(): Instance
{
    $account = UpstreamApi::factory()->create();
    $product = Product::factory()->create(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id]);

    return Instance::factory()->active()->create(['driver_type' => DriverType::ZJMF_API,
        'product_id' => $product->id, 'upstream_api_id' => $account->id, 'upstream_instance_id' => '4253',
        'active_config' => ['upstream_panel' => ['machine_status' => true]]]);
}

function c4HttpFake(): void
{
    Http::fake(fn ($request) => Http::response(match (parse_url($request->url(), PHP_URL_PATH)) {
        '/zjmf_api_login' => ['status' => 200, 'jwt' => 'C4-HTTP-PRIVATE-JWT'],
        '/host/header' => ['status' => 200, 'data' => ['host_data' => ['id' => 4253, 'domainstatus' => 'Active'],
            'module_button' => ['control' => array_map(fn ($func) => ['type' => 'default', 'func' => $func], ['on', 'off', 'reinstall', 'rescue_system'])],
            'cloud_os_group' => [['id' => 'Ubuntu', 'name' => 'Ubuntu']],
            'cloud_os' => [['id' => 12, 'group' => 'Ubuntu', 'name' => 'Ubuntu 24.04']],
            'reinstall_random_port' => true, 'reinstall_format_data_disk' => true]],
        '/provision/default' => $request['func'] === 'status'
            ? ['status' => 200, 'data' => ['status' => 'on', 'password' => 'C4-HTTP-PRIVATE-STATUS']]
            : ['status' => 200, 'msg' => 'Accepted'],
        default => throw new LogicException('Unexpected C4 HTTP request'),
    }));
}

it('adds separate fixed machine and sync roles without granting them to existing lifecycle or readonly roles', function (): void {
    expect(AdminRoles::permissions()['upstream-machine-operator'])->toBe(['upstream.view', 'upstream.machine-control'])
        ->and(AdminRoles::permissions()['upstream-sync-operator'])->toBe(['upstream.view', 'upstream.sync'])
        ->and(AdminRoles::permissions()['upstream-lifecycle'])->toBe(['upstream.view', 'upstream.lifecycle'])
        ->and(AdminRoles::permissions()['upstream-viewer'])->toBe(['upstream.view']);
    foreach (['upstream', 'all_business'] as $preset) {
        expect(AdminRoles::presets()[$preset]['roles'])->toContain('upstream-machine-operator', 'upstream-sync-operator');
    }
    foreach (['upstream-machine-operator', 'upstream-sync-operator'] as $role) {
        $actor = User::factory()->create();
        app(AssignAdminRoleAction::class)->execute($actor, $role, 'Isolated C4 role acceptance');
        expect($actor->fresh()->getAllPermissions()->pluck('name')->all())->toEqualCanonicalizing(AdminRoles::permissions()[$role]);
    }
});

it('rejects customer and readonly posts before contacting any upstream', function (string $suffix): void {
    $instance = c4HttpInstance();
    foreach ([c4HttpActor([]), c4HttpActor(['upstream.view'])] as $actor) {
        $this->actingAs($actor)->post('/admin/upstream-instances/'.$instance->id.$suffix)->assertForbidden();
    }
    Http::assertNothingSent();
    Queue::assertNothingPushed();
})->with(['/machine', '/machine/command', '/power-status', '/sync']);

it('loads the machine menu by explicit POST with only header capabilities and independent administrator identity', function (): void {
    $instance = c4HttpInstance();
    $actor = c4HttpActor();
    c4HttpFake();
    $response = $this->actingAs($actor)->post('/admin/upstream-instances/'.$instance->id.'/machine');
    $response->assertOk()->assertSee('管理员机器操作')->assertSee('重装系统')->assertSee('救援系统')
        ->assertSee('备份')->assertSee('maxlength="255"', false)->assertDontSee('crack_pass')->assertDontSee('C4-HTTP-PRIVATE-JWT')
        ->assertSee('/css/admin-shell.css', false)->assertSee('data-csrf=', false)->assertSee('livewire', false);
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(DB::table('audit_logs')->where('action', 'instance.machine.controls_read')->sole()->actor_id)->toBe($actor->id);
    Http::assertSentCount(2);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/provision/') || str_contains($request->url(), 'chart'));
    $this->assertDatabaseCount('fulfillment_tasks', 0);
});

it('has no standalone GET that implicitly loads a machine menu', function (): void {
    $instance = c4HttpInstance();
    $this->actingAs(c4HttpActor())->get('/admin/instance-machine-controls')->assertNotFound();
    $this->get('/admin/upstream-instances/'.$instance->id.'/machine')->assertNotFound();
    Http::assertNothingSent();
});

it('requires interruption or backup confirmation before any machine request and never flashes rescue secrets', function (string $command): void {
    $instance = c4HttpInstance();
    $this->actingAs(c4HttpActor())->post('/admin/upstream-instances/'.$instance->id.'/machine/command', [
        'command' => $command, 'request_id' => (string) Str::ulid(), 'temp_pass' => 'C4-SECRET-RESCUE',
        'os_group' => 'Ubuntu', 'os' => 12, 'reason' => 'Private submitted note',
    ])->assertRedirect('/admin/instances/'.$instance->id)->assertSessionHasErrors()
        ->assertSessionMissing('_old_input');
    Http::assertNothingSent();
    $this->assertDatabaseCount('audit_logs', 0);
})->with(['off', 'hard_off', 'reboot', 'hard_reboot', 'reinstall', 'rescueSystem']);

it('submits a power request once as the actual administrator and describes upstream acceptance accurately', function (): void {
    $instance = c4HttpInstance();
    $actor = c4HttpActor();
    $input = ['command' => 'on', 'request_id' => (string) Str::ulid()];
    c4HttpFake();
    $this->actingAs($actor)->post('/admin/upstream-instances/'.$instance->id.'/machine/command', $input)
        ->assertRedirect('/admin/instances/'.$instance->id)->assertSessionHas('instance_status', fn ($message) => str_contains($message, '受理不代表完成'));
    $this->post('/admin/upstream-instances/'.$instance->id.'/machine/command', $input)
        ->assertRedirect('/admin/instances/'.$instance->id)->assertSessionHasErrors('command');
    expect(DB::table('audit_logs')->where('action', 'instance.machine.request.'.$input['request_id'])->sole()->actor_id)->toBe($actor->id)
        ->and(DB::table('audit_logs')->where('action', 'instance.machine.result.'.$input['request_id'])->sole()->actor_id)->toBe($actor->id);
    Http::assertSentCount(3);
    $this->assertDatabaseCount('financial_transactions', 0);
    Queue::assertNothingPushed();
});

it('returns only normalized power status through the explicit administrator endpoint', function (): void {
    $instance = c4HttpInstance();
    c4HttpFake();
    $response = $this->actingAs(c4HttpActor())->postJson('/admin/upstream-instances/'.$instance->id.'/power-status');
    $response->assertOk()->assertJsonPath('available', true)->assertJsonPath('state', 'on')->assertJsonCount(3)
        ->assertDontSee('C4-HTTP-PRIVATE-STATUS')->assertDontSee('C4-HTTP-PRIVATE-JWT');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/host/header'));
});

it('limits machine posts by administrator without consuming another operators allowance', function (): void {
    $instance = c4HttpInstance();
    $uri = '/admin/upstream-instances/'.$instance->id.'/machine/command';
    $this->actingAs(c4HttpActor());
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $this->post($uri, ['command' => 'invalid'])->assertRedirect('/admin/instances/'.$instance->id);
    }
    $this->post($uri, ['command' => 'invalid'])->assertTooManyRequests();
    $this->actingAs(c4HttpActor())->post($uri, ['command' => 'invalid'])->assertRedirect('/admin/instances/'.$instance->id);
    Http::assertNothingSent();
});

it('requires the exact sync form identity and only restores its safe bounded note', function (): void {
    $instance = c4HttpInstance();
    $this->actingAs(c4HttpActor(['upstream.view', 'upstream.sync']))->post('/admin/upstream-instances/'.$instance->id.'/sync', [
        '_admin_form' => 'forged', 'instance_id' => $instance->id + 1, 'reason' => str_repeat('a', 256),
        'temp_pass' => 'PRIVATE', 'confirm' => 1, 'password' => 'PRIVATE',
    ])->assertRedirect('/admin/instances/'.$instance->id)->assertSessionHasErrors(['_admin_form', 'instance_id', 'reason'])
        ->assertSessionHas('_old_input', ['_admin_form' => 'instance-sync', 'instance_id' => $instance->id, 'reason' => str_repeat('a', 255)]);
    Http::assertNothingSent();
});

it('invokes explicit sync once and returns the workspace success message', function (): void {
    $instance = c4HttpInstance();
    $actor = c4HttpActor(['upstream.view', 'upstream.sync']);
    $this->mock(SyncAdminInstanceAction::class, fn ($mock) => $mock->shouldReceive('execute')->once()
        ->withArgs(fn (Instance $target, User $operator, $note) => $target->id === $instance->id && $operator->id === $actor->id && $note === null));
    $this->actingAs($actor)->post('/admin/upstream-instances/'.$instance->id.'/sync', [
        '_admin_form' => 'instance-sync', 'instance_id' => $instance->id,
    ])->assertRedirect('/admin/instances/'.$instance->id)->assertSessionHas('instance_status');
    Http::assertNothingSent();
});

it('returns an unavailable sync to its dialog without publishing remote errors or changing local facts', function (): void {
    $instance = c4HttpInstance();
    $before = $instance->fresh()->getRawOriginal();
    Http::fake(fn () => Http::response(['status' => 400, 'msg' => 'C4-PRIVATE-UPSTREAM-ERROR']));
    $this->actingAs(c4HttpActor(['upstream.view', 'upstream.sync']))->post('/admin/upstream-instances/'.$instance->id.'/sync', [
        '_admin_form' => 'instance-sync', 'instance_id' => $instance->id, 'reason' => 'Retry a read later',
    ])->assertRedirect('/admin/instances/'.$instance->id)->assertSessionHasErrors('sync')
        ->assertSessionHas('_old_input.reason', 'Retry a read later');
    expect($instance->fresh()->getRawOriginal())->toBe($before)
        ->and(json_encode(session('errors')->all()))->not->toContain('C4-PRIVATE-UPSTREAM-ERROR');
    expect(DB::table('audit_logs')->where('action', 'instance.admin_sync')->count())->toBe(0);
});

function c4HttpFailedTask(Instance $instance): FulfillmentTask
{
    $invoice = Invoice::factory()->paid()->create(['user_id' => $instance->user_id, 'instance_id' => $instance->id]);

    return FulfillmentTask::factory()->create(['user_id' => $instance->user_id, 'instance_id' => $instance->id,
        'invoice_id' => $invoice->id, 'driver_type' => $instance->driver_type, 'upstream_api_id' => $instance->upstream_api_id,
        'status' => FulfillmentTaskStatus::FAILED, 'attempts' => 5, 'action' => 'provision']);
}

it('requires all workspace finance task and lifecycle permissions before retrying', function (string $missing): void {
    $instance = c4HttpInstance();
    $task = c4HttpFailedTask($instance);
    $permissions = array_values(array_diff(['upstream.view', 'tasks.view', 'finance.view', 'upstream.lifecycle'], [$missing]));
    $this->actingAs(c4HttpActor($permissions))->post('/admin/instances/'.$instance->id.'/tasks/'.$task->id.'/retry', [
        '_admin_form' => 'instance-task-retry', 'instance_id' => $instance->id, 'record_id' => $task->id, 'confirm' => 1,
    ])->assertForbidden();
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::FAILED);
    Queue::assertNothingPushed();
})->with(['upstream.view', 'tasks.view', 'finance.view', 'upstream.lifecycle']);

it('does not retry a different instance task or a nonpaid invoice from the workspace', function (): void {
    $instance = c4HttpInstance();
    $task = c4HttpFailedTask($instance);
    $actor = c4HttpActor(['upstream.view', 'tasks.view', 'finance.view', 'upstream.lifecycle']);
    $this->actingAs($actor)->post('/admin/instances/'.c4HttpInstance()->id.'/tasks/'.$task->id.'/retry')->assertNotFound();
    $task->invoice->update(['status' => InvoiceStatus::UNPAID]);
    $this->post('/admin/instances/'.$instance->id.'/tasks/'.$task->id.'/retry', [
        '_admin_form' => 'instance-task-retry', 'instance_id' => $instance->id, 'record_id' => $task->id, 'confirm' => 1,
    ])->assertRedirect('/admin/instances/'.$instance->id)->assertSessionHasErrors('operation');
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::FAILED);
    Queue::assertNothingPushed();
});

it('requires renewed confirmation on retry failures and then queues the original paid task once', function (): void {
    $instance = c4HttpInstance();
    $task = c4HttpFailedTask($instance);
    $actor = c4HttpActor(['upstream.view', 'tasks.view', 'finance.view', 'upstream.lifecycle']);
    $input = ['_admin_form' => 'instance-task-retry', 'instance_id' => $instance->id, 'record_id' => $task->id, 'reason' => 'Check original task'];
    $uri = '/admin/instances/'.$instance->id.'/tasks/'.$task->id.'/retry';
    $this->actingAs($actor)->post($uri, $input + ['temp_pass' => 'PRIVATE'])->assertRedirect('/admin/instances/'.$instance->id)
        ->assertSessionHasErrors('confirm')->assertSessionHas('_old_input', $input);
    $this->post($uri, $input + ['confirm' => 1])->assertRedirect('/admin/instances/'.$instance->id)->assertSessionHas('instance_status');
    $this->post($uri, $input + ['confirm' => 1])->assertRedirect('/admin/instances/'.$instance->id)->assertSessionHasErrors('operation');
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::PENDING)->and($task->fresh()->attempts)->toBe(0);
    Queue::assertPushed(ExecuteFulfillmentTaskJob::class, 1);
    $this->assertDatabaseCount('fulfillment_tasks', 1);
    $this->assertDatabaseCount('financial_transactions', 0);
    Http::assertNothingSent();
});
