<?php

declare(strict_types=1);

use App\Actions\ExecuteCustomerPowerCommandAction;
use App\Actions\ReadAdminInstanceControlsAction;
use App\Actions\ReadAdminInstancePowerStatusAction;
use App\Actions\ReconcileMachineOperationAction;
use App\Enums\DriverType;
use App\Enums\InstanceStatus;
use App\Enums\ProvisionCommand;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Product;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Support\AdminMachineAccess;
use App\Support\MachineOperation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Http::preventStrayRequests();
    $this->travelTo(now()->startOfSecond());
});

function c4MachineActor(array $permissions = ['upstream.view', 'upstream.machine-control']): User
{
    $actor = User::factory()->create();
    foreach ($permissions as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $actor;
}

function c4MachineInstance(?User $owner = null): Instance
{
    $account = UpstreamApi::factory()->create();
    $product = Product::factory()->create(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id,
        'provisioning_rules' => ['password_min_len' => 12]]);

    return Instance::factory()->active()->create(['user_id' => ($owner ?? User::factory()->create())->id,
        'product_id' => $product->id, 'driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id,
        'upstream_instance_id' => '4253', 'active_config' => ['upstream_panel' => ['machine_status' => true]]])->fresh();
}

function c4MachineHeader(): array
{
    return ['status' => 200, 'data' => ['host_data' => ['id' => 4253, 'domainstatus' => 'Active',
        'password_rule' => ['rule' => ['len_num' => '12', 'upper' => 1, 'lower' => 1, 'num' => 1, 'special' => 0]]],
        'module_button' => ['control' => array_map(fn ($func) => ['type' => 'default', 'func' => $func],
            ['on', 'off', 'reboot', 'hard_off', 'hard_reboot', 'reinstall', 'rescue_system']),
            'console' => [['type' => 'default', 'func' => 'vnc'], ['type' => 'custom', 'func' => 'exitRescue'], ['type' => 'default', 'func' => 'crack_pass']]],
        'cloud_os_group' => [['id' => 'Ubuntu', 'name' => 'Ubuntu'], ['id' => 'Debian', 'name' => 'Debian']],
        'cloud_os' => [['id' => 1173028, 'group' => 'Ubuntu', 'name' => 'Ubuntu-24.04-x64'], ['id' => 1173023, 'group' => 'Debian', 'name' => 'Debian-12.0-x64']],
        'reinstall_random_port' => true, 'reinstall_format_data_disk' => true,
        'module_power_status' => true, 'module_chart' => [['type' => 'cpu']], 'module_client_area' => [['key' => 'information']]]];
}

function c4MachineInput(array $input = []): array
{
    return $input + ['request_id' => (string) Str::ulid(), 'confirm' => true, 'backup_confirm' => true];
}

function c4FakeMachine(?Closure $hook = null): void
{
    $level = DB::transactionLevel();
    Http::fake(function ($request) use ($hook, $level) {
        expect(DB::transactionLevel())->toBe($level);
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($hook !== null && ($response = $hook($path, $request)) !== null) {
            return $response;
        }

        return Http::response(match ($path) {
            '/zjmf_api_login' => ['status' => 200, 'jwt' => 'C4-PRIVATE-JWT'],
            '/host/header' => c4MachineHeader(),
            '/provision/default' => match ($request['func']) {
                'status' => ['status' => 200, 'data' => ['status' => 'on']],
                'vnc' => ['status' => 200, 'data' => ['url' => 'https://supplier.test/dcim/novnc?'.http_build_query([
                    'url' => base64_encode('wss://console.test/ws?token=C4-PRIVATE-TOKEN'), 'password' => 'C4-PRIVATE-VNC', 'host_token' => 'C4-PRIVATE-HOST'])]],
                default => ['status' => 200, 'msg' => 'Accepted'],
            },
            default => throw new LogicException('Unexpected C4 machine request: '.$path),
        });
    });
}

it('reads only the current advertised controls with actual administrator audit and no monitoring or login requests', function (): void {
    $instance = c4MachineInstance();
    $actor = c4MachineActor();
    $before = $instance->getRawOriginal();
    c4FakeMachine();
    $controls = app(ReadAdminInstanceControlsAction::class)->execute($actor, $instance);
    expect($controls['commands'])->toBe(['on', 'off', 'reboot', 'hard_off', 'hard_reboot', 'reinstall', 'rescueSystem', 'vnc'])
        ->and($controls['systems'][0])->toBe(['id' => 1173028, 'name' => 'Ubuntu-24.04-x64', 'group' => 'Ubuntu'])
        ->and($instance->fresh()->getRawOriginal())->toBe($before);
    $audit = DB::table('audit_logs')->where('action', 'instance.machine.controls_read')->sole();
    expect($audit->actor_id)->toBe($actor->id)->and($audit->ip)->not->toBeNull()->and($audit->request_id)->not->toBeNull()
        ->and(json_decode($audit->after, true)['owner_id'])->toBe($instance->user_id);
    Http::assertSentCount(2);
    Http::assertNotSent(fn ($request) => ! in_array(parse_url($request->url(), PHP_URL_PATH), ['/zjmf_api_login', '/host/header'], true));
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('fulfillment_tasks', 0);
});

it('queries a fresh standalone power status as the actual administrator without loading header or charts', function (): void {
    $instance = c4MachineInstance();
    $actor = c4MachineActor();
    $state = 'on';
    c4FakeMachine(function ($path, $request) use (&$state) {
        return $path === '/provision/default' && $request['func'] === 'status'
            ? Http::response(['status' => 200, 'data' => ['status' => $state]]) : null;
    });
    expect(app(ReadAdminInstancePowerStatusAction::class)->execute($actor, $instance))->toBe(['available' => true, 'state' => 'on']);
    $state = 'off';
    expect(app(ReadAdminInstancePowerStatusAction::class)->execute($actor, $instance))->toBe(['available' => true, 'state' => 'off']);
    expect(DB::table('audit_logs')->where('action', 'instance.machine.power_status_read')->where('actor_id', $actor->id)->count())->toBe(2);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/host/header') || str_contains($request->url(), '/chart'));
    $this->assertDatabaseCount('fulfillment_tasks', 0);
});

it('requires both separate machine permissions and a fresh enabled operator before any upstream request', function (string $invalid): void {
    $instance = c4MachineInstance();
    $actor = c4MachineActor();
    match ($invalid) {
        'viewer' => $actor->revokePermissionTo('upstream.machine-control'),
        'lifecycle' => $actor->syncPermissions([Permission::findOrCreate('upstream.view', 'web'), Permission::findOrCreate('upstream.lifecycle', 'web')]),
        'control only' => $actor->revokePermissionTo('upstream.view'),
        'disabled' => $actor->forceFill(['disabled_at' => now()])->save(),
        'closed' => $actor->forceFill(['anonymized_at' => now(), 'deleted_at' => now()])->save(),
        'customer' => $actor = $instance->user,
    };
    $exception = in_array($invalid, ['disabled', 'closed'], true) ? HttpException::class : AuthorizationException::class;
    foreach ([ReadAdminInstanceControlsAction::class, ReadAdminInstancePowerStatusAction::class] as $action) {
        expect(fn () => app($action)->execute($actor, $instance))->toThrow($exception);
    }
    expect(fn () => app(ExecuteCustomerPowerCommandAction::class)->executeAsAdmin($actor, $instance, ProvisionCommand::POWER_ON, c4MachineInput()))->toThrow($exception);
    Http::assertNothingSent();
    $this->assertDatabaseCount('audit_logs', 0);
})->with(['viewer', 'lifecycle', 'control only', 'disabled', 'closed', 'customer']);

it('allows administrator maintenance of a disabled owners existing active service without restoring account access', function (): void {
    $owner = User::factory()->create(['disabled_at' => now(), 'auth_revision' => 3, 'credit' => '18.00']);
    $instance = c4MachineInstance($owner);
    $actor = c4MachineActor();
    c4FakeMachine();
    expect(app(ReadAdminInstanceControlsAction::class)->execute($actor, $instance)['commands'])->toContain('on');
    expect(app(ReadAdminInstancePowerStatusAction::class)->execute($actor, $instance)['state'])->toBe('on');
    expect(app(ExecuteCustomerPowerCommandAction::class)->executeAsAdmin($actor, $instance, ProvisionCommand::POWER_ON, c4MachineInput())->success)->toBeTrue()
        ->and($owner->fresh()->disabled_at)->not->toBeNull()->and($owner->fresh()->auth_revision)->toBe(3)->and($owner->fresh()->credit)->toBe('18.00');
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
});

it('rejects new upstream requests for a closed owner or a nonclassic service', function (string $invalid): void {
    $instance = c4MachineInstance();
    $actor = c4MachineActor();
    match ($invalid) {
        'closed' => $instance->user->forceFill(['deleted_at' => now()])->save(),
        'anonymized' => $instance->user->forceFill(['anonymized_at' => now()])->save(),
        'local card' => $instance->update(['driver_type' => DriverType::REDEMPTION]),
        'sub2api' => $instance->update(['driver_type' => DriverType::SUB2API]),
    };
    foreach ([ReadAdminInstanceControlsAction::class, ReadAdminInstancePowerStatusAction::class] as $action) {
        expect(fn () => app($action)->execute($actor, $instance))->toThrow(HttpException::class);
    }
    expect(fn () => app(ExecuteCustomerPowerCommandAction::class)->executeAsAdmin($actor, $instance, ProvisionCommand::POWER_ON, c4MachineInput()))->toThrow(HttpException::class);
    Http::assertNothingSent();
})->with(['closed', 'anonymized', 'local card', 'sub2api']);

it('records actual administrator intent and result once for the supported commands without changing billing or delivery facts', function (ProvisionCommand $command): void {
    $instance = c4MachineInstance();
    $actor = c4MachineActor();
    $input = c4MachineInput(match ($command) {
        ProvisionCommand::REINSTALL => ['os_group' => 'Ubuntu', 'os' => 1173028, 'format_data_disk' => 1],
        ProvisionCommand::RESCUE_SYSTEM => ['system' => 2, 'temp_pass' => 'C4PrivateRescue1!'],
        default => [],
    });
    $before = $instance->getRawOriginal();
    c4FakeMachine();
    $result = app(ExecuteCustomerPowerCommandAction::class)->executeAsAdmin($actor, $instance, $command, $input);
    expect($result->success)->toBeTrue()->and($instance->fresh()->status)->toBe(InstanceStatus::ACTIVE)
        ->and(array_intersect_key($instance->fresh()->getRawOriginal(), array_flip(['next_due_at', 'recurring_amount', 'encrypted_credentials'])))
        ->toBe(array_intersect_key($before, array_flip(['next_due_at', 'recurring_amount', 'encrypted_credentials'])))
        ->and($instance->fresh()->active_config['cloud_operation']['status'])->toBe('accepted');
    foreach (['request', 'result'] as $stage) {
        $audit = DB::table('audit_logs')->where('action', 'instance.machine.'.$stage.'.'.$input['request_id'])->sole();
        expect($audit->actor_id)->toBe($actor->id)->and($audit->actor_id)->not->toBe($instance->user_id)
            ->and($audit->reason)->toContain('管理员')->and($audit->ip)->not->toBeNull()->and($audit->request_id)->not->toBeNull()
            ->and(json_decode($audit->after, true)['owner_id'])->toBe($instance->user_id);
    }
    $logs = DB::table('audit_logs')->get()->toJson().DB::table('upstream_operations')->get()->toJson().$instance->fresh()->toJson();
    expect($logs)->not->toContain('C4PrivateRescue1!', 'C4-PRIVATE-TOKEN', 'C4-PRIVATE-VNC', 'C4-PRIVATE-HOST');
    expect(fn () => app(ExecuteCustomerPowerCommandAction::class)->executeAsAdmin($actor, $instance->fresh(), $command, $input))->toThrow(ValidationException::class);
    Http::assertSentCount(3);
    foreach (['invoices', 'financial_transactions', 'fulfillment_tasks', 'notification_events'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    expect($instance->fresh()->getRawOriginal('encrypted_credentials'))->toBe($before['encrypted_credentials']);
})->with([ProvisionCommand::POWER_ON, ProvisionCommand::POWER_OFF, ProvisionCommand::REBOOT, ProvisionCommand::HARD_OFF,
    ProvisionCommand::HARD_REBOOT, ProvisionCommand::REINSTALL, ProvisionCommand::RESCUE_SYSTEM, ProvisionCommand::VNC]);

it('retains command-specific confirmation and the administrators shorter optional note limit', function (string $invalid): void {
    $instance = c4MachineInstance();
    $actor = c4MachineActor();
    $command = in_array($invalid, ['backup', 'OS pairing'], true) ? ProvisionCommand::REINSTALL : ProvisionCommand::REBOOT;
    $input = c4MachineInput(match ($invalid) {
        'interruption' => ['confirm' => false], 'backup' => ['backup_confirm' => false],
        'long reason' => ['reason' => str_repeat('a', 256)], 'OS pairing' => ['os_group' => 'Debian', 'os' => 1173028],
        default => [],
    });
    c4FakeMachine();
    expect(fn () => app(ExecuteCustomerPowerCommandAction::class)->executeAsAdmin($actor, $instance, $command, $input))->toThrow(ValidationException::class);
    Http::assertNotSent(fn ($request) => parse_url($request->url(), PHP_URL_PATH) === '/provision/default');
    if ($invalid !== 'OS pairing') {
        Http::assertNothingSent();
        $this->assertDatabaseCount('audit_logs', 0);
    }
})->with(['interruption', 'backup', 'long reason', 'OS pairing']);

it('does not add confirmation to direct opening or replace a deliberately entered zero note', function (): void {
    $instance = c4MachineInstance();
    $actor = c4MachineActor();
    $requestId = (string) Str::ulid();
    c4FakeMachine();
    expect(app(ExecuteCustomerPowerCommandAction::class)->executeAsAdmin($actor, $instance, ProvisionCommand::POWER_ON,
        ['request_id' => $requestId, 'reason' => ' 0 '])->success)->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['actor_id' => $actor->id, 'action' => 'instance.machine.request.'.$requestId, 'reason' => '0']);
});

it('shares the customer mutex and pending intent instead of running an independent administrator machine lane', function (string $block): void {
    $instance = c4MachineInstance();
    $actor = c4MachineActor();
    $lock = null;
    if ($block === 'mutex') {
        $lock = Cache::lock('customer-machine-control:'.$instance->id, 120);
        $lock->get();
    } elseif (in_array($block, ['requested', 'unknown'], true)) {
        $instance->update(['active_config' => ['cloud_operation' => ['status' => $block]]]);
    } elseif ($block === 'cancellation') {
        $instance->update(['active_config' => ['cancellation' => ['status' => 'requested']]]);
    } else {
        FulfillmentTask::factory()->create(['instance_id' => $instance->id, 'status' => $block]);
    }
    try {
        expect(fn () => app(ExecuteCustomerPowerCommandAction::class)->executeAsAdmin($actor, $instance, ProvisionCommand::REBOOT, c4MachineInput()))->toThrow(ValidationException::class);
        Http::assertNothingSent();
        $this->assertDatabaseCount('audit_logs', 0);
    } finally {
        $lock?->release();
    }
})->with(['mutex', 'requested', 'unknown', 'cancellation', 'pending', 'running', 'failed']);

it('retains an unknown administrator result and prevents automatic or customer replay', function (): void {
    $instance = c4MachineInstance();
    $actor = c4MachineActor();
    $input = c4MachineInput();
    c4FakeMachine(function ($path) {
        if ($path === '/provision/default') {
            throw new ConnectionException('C4 lost command response');
        }

        return null;
    });
    $action = app(ExecuteCustomerPowerCommandAction::class);
    $result = $action->executeAsAdmin($actor, $instance, ProvisionCommand::REBOOT, $input);
    expect($result->success)->toBeFalse()->and($result->retryable)->toBeTrue()
        ->and($instance->fresh()->active_config['cloud_operation']['status'])->toBe('unknown')
        ->and(app(MachineOperation::class)->blocked($instance->fresh()))->toBeTrue();
    expect(fn () => $action->executeAsAdmin($actor, $instance->fresh(), ProvisionCommand::REBOOT, $input))->toThrow(ValidationException::class);
    expect(fn () => $action->execute($instance->user, $instance->fresh(), ProvisionCommand::REBOOT, c4MachineInput()))->toThrow(ValidationException::class);
    // HttpFake records completed exchanges; the failed command is retained by CallUpstreamAction.
    Http::assertSentCount(2);
    expect(DB::table('upstream_operations')->where('path', '/provision/default')->count())->toBe(1)
        ->and(DB::table('upstream_operations')->where('path', '/provision/default')->value('outcome'))->toBe('ambiguous');
});

it('keeps administrator unknown intent compatible with the original evidence-based machine reconciliation', function (): void {
    $instance = c4MachineInstance();
    $actor = c4MachineActor(['upstream.view', 'upstream.machine-control', 'upstream.lifecycle']);
    $lost = true;
    c4FakeMachine(function ($path, $request) use (&$lost) {
        if ($path === '/provision/default' && $request['func'] !== 'status' && $lost) {
            throw new ConnectionException('C4 first command response lost');
        }

        return null;
    });
    $input = c4MachineInput();
    $machine = app(ExecuteCustomerPowerCommandAction::class);
    $machine->executeAsAdmin($actor, $instance, ProvisionCommand::REBOOT, $input);
    $lost = false;
    $this->travel(6)->minutes();
    $instance = $instance->fresh();
    $reconcile = app(ReconcileMachineOperationAction::class);
    $review = $reconcile->inspect($instance, $actor, $input['request_id'], 'Inspect original administrator machine task');
    $result = $reconcile->execute($instance, $actor, ['resolution_id' => (string) Str::ulid(),
        'operation_request_id' => $input['request_id'], 'proof' => $review['proof'], 'decision' => 'release',
        'reason' => 'Supplier confirmed original task ended', 'confirm' => true, 'source' => 'supplier_ticket',
        'conclusion' => 'failed', 'evidence_ref' => 'C4-SUPPLIER-ORIGINAL-TASK',
        'evidence' => 'Supplier confirmed the original task failed and no related command is queued or running.',
        'verified_host_id' => '4253', 'ended_at' => now()->toIso8601String(), 'idle_confirm' => true]);
    expect($result['released'])->toBeTrue()->and($instance->fresh()->active_config['cloud_operation']['status'])->toBe('unknown')
        ->and(app(MachineOperation::class)->blocked($instance->fresh()))->toBeFalse();
    expect(fn () => $machine->executeAsAdmin($actor, $instance->fresh(), ProvisionCommand::REBOOT, $input))->toThrow(ValidationException::class);
    expect($machine->executeAsAdmin($actor, $instance->fresh(), ProvisionCommand::REBOOT, c4MachineInput())->success)->toBeTrue();
});

it('closes already submitted administrator results under the original identities after access is revoked', function (string $change): void {
    $instance = c4MachineInstance();
    $actor = c4MachineActor();
    $input = c4MachineInput();
    c4FakeMachine(function ($path) use ($instance, $actor, $change) {
        if ($path === '/provision/default') {
            match ($change) {
                'disabled actor' => $actor->forceFill(['disabled_at' => now()])->save(),
                'revoked actor' => $actor->revokePermissionTo('upstream.machine-control'),
                'closed actor' => $actor->forceFill(['deleted_at' => now(), 'anonymized_at' => now()])->save(),
                'closed owner' => $instance->user->forceFill(['deleted_at' => now(), 'anonymized_at' => now()])->save(),
            };
        }

        return null;
    });
    expect(app(ExecuteCustomerPowerCommandAction::class)->executeAsAdmin($actor, $instance, ProvisionCommand::REBOOT, $input)->success)->toBeTrue();
    $audit = DB::table('audit_logs')->where('action', 'instance.machine.result.'.$input['request_id'])->sole();
    expect($audit->actor_id)->toBe($actor->id)->and(json_decode($audit->after, true)['owner_id'])->toBe($instance->user_id)
        ->and($instance->fresh()->active_config['cloud_operation']['status'])->toBe('accepted');
})->with(['disabled actor', 'revoked actor', 'closed actor', 'closed owner']);

it('does not return VNC credentials when administrator permission changes during the command while preserving result audit', function (string $change): void {
    $instance = c4MachineInstance();
    $actor = c4MachineActor();
    $input = c4MachineInput();
    c4FakeMachine(function ($path) use ($instance, $actor, $change) {
        if ($path === '/provision/default') {
            match ($change) {
                'disabled actor' => $actor->forceFill(['disabled_at' => now()])->save(),
                'revoked actor' => $actor->revokePermissionTo('upstream.machine-control'),
                'closed owner' => $instance->user->forceFill(['anonymized_at' => now()])->save(),
                'credential rotation' => $instance->upstreamApi->update(['encrypted_secret' => 'C4-replacement-secret']),
            };
        }

        return null;
    });
    expect(fn () => app(ExecuteCustomerPowerCommandAction::class)->executeAsAdmin($actor, $instance, ProvisionCommand::VNC, $input))
        ->toThrow($change === 'revoked actor' ? AuthorizationException::class : HttpException::class);
    $this->assertDatabaseHas('audit_logs', ['actor_id' => $actor->id, 'action' => 'instance.machine.result.'.$input['request_id']]);
    expect($instance->fresh()->active_config['cloud_operation']['status'])->toBe('accepted');
})->with(['disabled actor', 'revoked actor', 'closed owner', 'credential rotation']);

it('discards machine reads after a permission owner binding or account change while upstream is responding', function (string $read, string $change): void {
    $instance = c4MachineInstance();
    $actor = c4MachineActor();
    c4FakeMachine(function ($path) use ($instance, $actor, $read, $change) {
        if ($path === ($read === 'controls' ? '/host/header' : '/provision/default')) {
            match ($change) {
                'revoke' => $actor->revokePermissionTo('upstream.machine-control'),
                'disable' => $actor->forceFill(['disabled_at' => now()])->save(),
                'owner close' => $instance->user->forceFill(['anonymized_at' => now()])->save(),
                'rebind' => $instance->fresh()->update(['upstream_instance_id' => '9999']),
                'credentials' => $instance->upstreamApi->update(['encrypted_secret' => 'C4-new-secret']),
            };
        }

        return null;
    });
    $action = $read === 'controls' ? ReadAdminInstanceControlsAction::class : ReadAdminInstancePowerStatusAction::class;
    $exception = match ($change) {
        'revoke' => AuthorizationException::class, 'disable', 'owner close' => HttpException::class,
        default => ValidationException::class,
    };
    expect(fn () => app($action)->execute($actor, $instance))->toThrow($exception);
    $this->assertDatabaseCount('audit_logs', 0);
})->with(['controls', 'power'])->with(['revoke', 'disable', 'owner close', 'rebind', 'credentials']);

it('rolls the requested intent back without calling upstream if the administrator audit cannot persist', function (): void {
    $instance = c4MachineInstance();
    $actor = c4MachineActor();
    $before = $instance->getRawOriginal();
    DB::unprepared("CREATE TRIGGER c4_machine_audit_failure BEFORE INSERT ON audit_logs WHEN NEW.action LIKE 'instance.machine.request.%' BEGIN SELECT RAISE(ABORT, 'C4 audit failed'); END");
    expect(fn () => app(ExecuteCustomerPowerCommandAction::class)->executeAsAdmin($actor, $instance, ProvisionCommand::REBOOT, c4MachineInput()))->toThrow(QueryException::class);
    expect($instance->fresh()->getRawOriginal())->toBe($before);
    Http::assertNothingSent();
});

it('binds the reusable administrator console fingerprint to service owner endpoint credentials and current account state', function (string $change): void {
    $instance = c4MachineInstance();
    $actor = c4MachineActor();
    $access = app(AdminMachineAccess::class);
    $snapshot = $access->authorize($actor, $instance);
    $digest = $access->fingerprint($snapshot['instance']);
    match ($change) {
        'owner' => $instance->update(['user_id' => User::factory()->create()->id]),
        'host' => $instance->update(['upstream_instance_id' => '9999']),
        'token' => $instance->update(['downstream_token' => 'C4-another-binding']),
        'endpoint' => $instance->upstreamApi->update(['base_url' => 'https://changed.example.invalid']),
        'credential' => $instance->upstreamApi->update(['encrypted_secret' => 'C4-next-secret']),
        'disabled account' => $instance->upstreamApi->update(['is_active' => false]),
        'service state' => $instance->update(['status' => InstanceStatus::SUSPENDED]),
    };
    expect($access->fingerprint($instance->fresh()->load('upstreamApi')))->not->toBe($digest);
    Http::assertNothingSent();
})->with(['owner', 'host', 'token', 'endpoint', 'credential', 'disabled account', 'service state']);
