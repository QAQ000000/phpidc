<?php

declare(strict_types=1);

use App\Actions\SyncAdminInstanceAction;
use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\UpstreamApi;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Http::preventStrayRequests();
});

function c4SyncActor(array $permissions = ['upstream.view', 'upstream.sync']): User
{
    $actor = User::factory()->create();
    foreach ($permissions as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $actor;
}

function c4SyncInstance(array $attributes = []): Instance
{
    return Instance::factory()->create($attributes + ['driver_type' => DriverType::ZJMF_API,
        'upstream_api_id' => UpstreamApi::factory()->create(['base_url' => 'https://c4-sync.example.test'])->id,
        'upstream_instance_id' => '432', 'status' => InstanceStatus::ACTIVE,
        'next_due_at' => '2027-01-01 00:00:00', 'primary_ip' => '203.0.113.1']);
}

function fakeC4Sync(?Closure $duringHeader = null, string $status = 'Suspended', string $hostId = '432'): void
{
    $level = DB::transactionLevel();
    Http::fake(function ($request) use ($duringHeader, $status, $hostId, $level) {
        expect(DB::transactionLevel())->toBe($level);
        if (parse_url($request->url(), PHP_URL_PATH) === '/zjmf_api_login') {
            return Http::response(['status' => 200, 'jwt' => 'PRIVATE-C4-SYNC-JWT']);
        }
        expect(parse_url($request->url(), PHP_URL_PATH))->toBe('/host/header')
            ->and($request->method())->toBe('GET')->and((string) $request['host_id'])->toBe('432');
        $duringHeader?->__invoke();

        return Http::response(['status' => 200, 'data' => ['module_power_status' => true,
            'host_data' => ['id' => $hostId, 'domainstatus' => $status, 'dedicatedip' => '203.0.113.44',
                'assignedips' => '203.0.113.44, 203.0.113.45', 'username' => 'root',
                'password' => 'PRIVATE-C4-SYNC-PASSWORD', 'port' => 2222, 'os' => 'Debian 12',
                'suspendreason' => 'Upstream service fact', 'nextduedate' => 1801440000]]]);
    });
}

it('explicitly syncs classic facts outside locks and atomically audits the real administrator', function (): void {
    $actor = c4SyncActor();
    $owner = User::factory()->create(['credit' => '18.25']);
    $owner->forceFill(['disabled_at' => now()])->save();
    $operation = ['status' => 'unknown', 'request_id' => '01C4-UNKNOWN', 'command' => 'reboot',
        'reconciliation' => ['audit_id' => 987, 'released' => false]];
    $instance = c4SyncInstance(['user_id' => $owner->id,
        'active_config' => ['cloud_operation' => $operation, 'approved_config' => ['cpu' => 2]]]);
    fakeC4Sync();

    app(SyncAdminInstanceAction::class)->execute($instance, $actor);

    $current = $instance->fresh();
    expect($current->status)->toBe(InstanceStatus::SUSPENDED)
        ->and($current->primary_ip)->toBe('203.0.113.44')
        ->and($current->assigned_ips)->toBe(['203.0.113.44', '203.0.113.45'])
        ->and($current->encrypted_credentials)->toBe(['username' => 'root', 'password' => 'PRIVATE-C4-SYNC-PASSWORD', 'port' => 2222, 'os' => 'Debian 12'])
        ->and($current->getRawOriginal('encrypted_credentials'))->not->toContain('PRIVATE-C4-SYNC-PASSWORD')
        ->and($current->next_due_at->getTimestamp())->toBe(1801440000)
        ->and($current->last_synced_at)->not->toBeNull()
        ->and($current->active_config['cloud_operation'])->toBe($operation)
        ->and($current->active_config['approved_config'])->toBe(['cpu' => 2])
        ->and($current->active_config['upstream_panel']['machine_status'])->toBeTrue()
        ->and($current->user_id)->toBe($owner->id)
        ->and($current->recurring_amount)->toBe('10.00')
        ->and($owner->fresh()->credit)->toBe('18.25');
    $audit = DB::table('audit_logs')->where('action', 'instance.admin_sync')->sole();
    expect($audit->actor_id)->toBe($actor->id)->and($audit->actor_type)->toBe('user')
        ->and($audit->subject_id)->toBe($instance->id)->and($audit->request_id)->not->toBeNull()
        ->and($audit->reason)->toBe('管理员同步上游服务事实');
    expect(DB::table('audit_logs')->pluck('after')->implode(' ')
        .DB::table('upstream_operations')->pluck('response_body')->implode(' '))
        ->not->toContain('PRIVATE-C4-SYNC-PASSWORD', 'PRIVATE-C4-SYNC-JWT');
    Http::assertSentCount(2);
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('fulfillment_tasks', 0);
});

it('requires separate current sync and view authority even with lifecycle authority', function (array $permissions): void {
    $instance = c4SyncInstance();
    $actor = c4SyncActor($permissions);
    expect(fn () => app(SyncAdminInstanceAction::class)->execute($instance, $actor))->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
    expect($instance->fresh()->last_synced_at)->toBeNull();
})->with([[[]], [['upstream.view']], [['upstream.sync']], [['upstream.view', 'upstream.lifecycle']]]);

it('rechecks a stale actor before requesting upstream', function (string $change): void {
    $actor = c4SyncActor();
    expect($actor->can('upstream.sync'))->toBeTrue();
    $instance = c4SyncInstance();
    match ($change) {
        'revoked' => $actor->fresh()->revokePermissionTo('upstream.sync'),
        'disabled' => User::whereKey($actor->id)->update(['disabled_at' => now()]),
        'deleted' => User::whereKey($actor->id)->delete(),
        'anonymized' => User::whereKey($actor->id)->update(['anonymized_at' => now()]),
    };
    expect(fn () => app(SyncAdminInstanceAction::class)->execute($instance, $actor))
        ->toThrow($change === 'revoked' ? AuthorizationException::class : HttpException::class);
    Http::assertNothingSent();
})->with(['revoked', 'disabled', 'deleted', 'anonymized']);

it('does not make a supplier request for unsupported or closed bindings', function (string $change): void {
    $actor = c4SyncActor();
    $instance = c4SyncInstance();
    match ($change) {
        'local' => $instance->update(['driver_type' => DriverType::REDEMPTION, 'upstream_api_id' => null]),
        'remote-card' => $instance->update(['driver_type' => DriverType::SUB2API]),
        'pve' => $instance->update(['driver_type' => DriverType::PROXMOX]),
        'no-account' => $instance->update(['upstream_api_id' => null]),
        'no-host' => $instance->update(['upstream_instance_id' => null]),
        'empty-host' => $instance->update(['upstream_instance_id' => '  ']),
        'inactive' => $instance->upstreamApi->update(['is_active' => false]),
        'wrong-account-driver' => $instance->upstreamApi->update(['driver' => DriverType::SUB2API]),
        'cancelled' => $instance->update(['status' => InstanceStatus::CANCELLED]),
        'deleted' => $instance->update(['status' => InstanceStatus::DELETED]),
        'closed-owner' => $instance->user->delete(),
        'anonymized-owner' => User::whereKey($instance->user_id)->update(['anonymized_at' => now()]),
    };
    expect(fn () => app(SyncAdminInstanceAction::class)->execute($instance, $actor))->toThrow(ValidationException::class);
    Http::assertNothingSent();
    expect($instance->fresh()->last_synced_at)->toBeNull();
    $this->assertDatabaseMissing('audit_logs', ['action' => 'instance.admin_sync']);
})->with(['local', 'remote-card', 'pve', 'no-account', 'no-host', 'empty-host', 'inactive', 'wrong-account-driver',
    'cancelled', 'deleted', 'closed-owner', 'anonymized-owner']);

it('discards a completed read after binding account owner or state changes during HTTP', function (string $change): void {
    $actor = c4SyncActor();
    $instance = c4SyncInstance();
    $otherOwner = User::factory()->create();
    $otherAccount = UpstreamApi::factory()->create();
    fakeC4Sync(function () use ($change, $instance, $otherOwner, $otherAccount): void {
        match ($change) {
            'owner' => Instance::whereKey($instance->id)->update(['user_id' => $otherOwner->id]),
            'host' => Instance::whereKey($instance->id)->update(['upstream_instance_id' => '433']),
            'token' => Instance::whereKey($instance->id)->update(['downstream_token' => 'rebound-token']),
            'account' => Instance::whereKey($instance->id)->update(['upstream_api_id' => $otherAccount->id]),
            'state' => Instance::whereKey($instance->id)->update(['status' => InstanceStatus::SUSPENDED]),
            'terminal' => Instance::whereKey($instance->id)->update(['status' => InstanceStatus::DELETED]),
            'endpoint' => UpstreamApi::whereKey($instance->upstream_api_id)->update(['base_url' => 'https://replacement.example.test']),
            'secret' => $instance->upstreamApi->update(['encrypted_secret' => 'rotated-secret']),
            'username' => UpstreamApi::whereKey($instance->upstream_api_id)->update(['username' => 'replacement@example.test']),
            'auth-mode' => UpstreamApi::whereKey($instance->upstream_api_id)->update(['auth_header_mode' => 'Bearer']),
            'config' => UpstreamApi::whereKey($instance->upstream_api_id)->update(['config' => ['revision' => 2]]),
            'inactive' => UpstreamApi::whereKey($instance->upstream_api_id)->update(['is_active' => false]),
            'closed-owner' => User::whereKey($instance->user_id)->delete(),
            'anonymized-owner' => User::whereKey($instance->user_id)->update(['anonymized_at' => now()]),
        };
    });
    expect(fn () => app(SyncAdminInstanceAction::class)->execute($instance, $actor))->toThrow(ValidationException::class);
    expect($instance->fresh()->last_synced_at)->toBeNull()
        ->and($instance->fresh()->primary_ip)->toBe('203.0.113.1')
        ->and($instance->fresh()->encrypted_credentials)->toBeNull();
    $this->assertDatabaseMissing('audit_logs', ['action' => 'instance.admin_sync']);
})->with(['owner', 'host', 'token', 'account', 'state', 'terminal', 'endpoint', 'secret', 'username', 'auth-mode',
    'config', 'inactive', 'closed-owner', 'anonymized-owner']);

it('rechecks actual administrator access after the supplier read', function (string $change): void {
    $actor = c4SyncActor();
    $instance = c4SyncInstance();
    fakeC4Sync(function () use ($change, $actor): void {
        if ($change === 'revoked') {
            $actor->fresh()->revokePermissionTo('upstream.sync');
        } else {
            User::whereKey($actor->id)->update(['disabled_at' => now()]);
        }
    });
    expect(fn () => app(SyncAdminInstanceAction::class)->execute($instance, $actor))
        ->toThrow($change === 'revoked' ? AuthorizationException::class : HttpException::class);
    expect($instance->fresh()->last_synced_at)->toBeNull();
    $this->assertDatabaseMissing('audit_logs', ['action' => 'instance.admin_sync']);
})->with(['revoked', 'disabled']);

it('applies terminal upstream facts and stales committed tasks without redispatch', function (): void {
    $actor = c4SyncActor();
    $instance = c4SyncInstance();
    $task = FulfillmentTask::factory()->create(['instance_id' => $instance->id, 'status' => FulfillmentTaskStatus::RUNNING,
        'payload' => ['classic_order' => ['status' => 'unknown', 'request_id' => 'preserve-original-intent']]]);
    $payload = $task->payload;
    fakeC4Sync(status: 'Deleted');
    app(SyncAdminInstanceAction::class)->execute($instance, $actor, '0');
    expect($instance->fresh()->status)->toBe(InstanceStatus::DELETED)
        ->and($task->fresh()->status)->toBe(FulfillmentTaskStatus::STALE)
        ->and($task->fresh()->payload)->toBe($payload);
    $this->assertDatabaseHas('audit_logs', ['action' => 'instance.admin_sync', 'reason' => '0']);
    Http::assertSentCount(2);
});

it('rolls back synced fields task retirement and both audits when the administrator audit fails', function (): void {
    $actor = c4SyncActor();
    $instance = c4SyncInstance();
    $task = FulfillmentTask::factory()->create(['instance_id' => $instance->id]);
    DB::unprepared("CREATE TRIGGER c4_sync_audit_failure BEFORE INSERT ON audit_logs WHEN NEW.action = 'instance.admin_sync' BEGIN SELECT RAISE(ABORT, 'synthetic audit failure'); END");
    fakeC4Sync(status: 'Deleted');
    expect(fn () => app(SyncAdminInstanceAction::class)->execute($instance, $actor))->toThrow(QueryException::class);
    expect($instance->fresh()->status)->toBe(InstanceStatus::ACTIVE)
        ->and($instance->fresh()->last_synced_at)->toBeNull()
        ->and($instance->fresh()->encrypted_credentials)->toBeNull()
        ->and($task->fresh()->status)->toBe(FulfillmentTaskStatus::PENDING);
    $this->assertDatabaseMissing('audit_logs', ['subject_type' => Instance::class, 'subject_id' => $instance->id]);
});

it('rejects invalid reasons before I/O and rejects a response for another host', function (): void {
    $actor = c4SyncActor();
    $instance = c4SyncInstance();
    expect(fn () => app(SyncAdminInstanceAction::class)->execute($instance, $actor, str_repeat('x', 256)))->toThrow(ValidationException::class);
    Http::assertNothingSent();
    fakeC4Sync(hostId: '999');
    expect(fn () => app(SyncAdminInstanceAction::class)->execute($instance, $actor))->toThrow(DomainException::class);
    expect($instance->fresh()->last_synced_at)->toBeNull()->and($instance->fresh()->encrypted_credentials)->toBeNull();
    $this->assertDatabaseMissing('audit_logs', ['action' => 'instance.admin_sync']);
});
