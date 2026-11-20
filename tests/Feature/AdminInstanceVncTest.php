<?php

declare(strict_types=1);

use App\Actions\ExecuteCustomerPowerCommandAction;
use App\Enums\DriverType;
use App\Enums\InstanceStatus;
use App\Enums\ProvisionCommand;
use App\Http\Middleware\EnsureAccountAccess;
use App\Models\Instance;
use App\Models\Product;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Support\AdminMachineAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Http::preventStrayRequests();
    $this->travelTo(now()->startOfSecond());
});

function c4VncActor(): User
{
    $actor = User::factory()->create();
    foreach (['upstream.view', 'upstream.machine-control'] as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $actor->fresh();
}

function c4VncInstance(): Instance
{
    $account = UpstreamApi::factory()->create();
    $product = Product::factory()->create(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id]);

    return Instance::factory()->active()->create(['product_id' => $product->id, 'driver_type' => DriverType::ZJMF_API,
        'upstream_api_id' => $account->id, 'upstream_instance_id' => '4253'])->fresh();
}

function c4VncResponse(): array
{
    return ['status' => 200, 'data' => ['url' => 'https://supplier.test/dcim/novnc?'.http_build_query([
        'url' => base64_encode('wss://console.test/ws?token=ADMIN-VNC-PRIVATE-TOKEN'),
        'password' => 'ADMIN-VNC-PRIVATE-PASSWORD', 'host_token' => 'ADMIN-VNC-PRIVATE-HOST'])]];
}

function c4VncFake(?array $consoleResponse = null): void
{
    $level = DB::transactionLevel();
    Http::fake(function ($request) use ($consoleResponse, $level) {
        expect(DB::transactionLevel())->toBe($level);

        return Http::response(match (parse_url($request->url(), PHP_URL_PATH)) {
            '/zjmf_api_login' => ['status' => 200, 'jwt' => 'ADMIN-VNC-PRIVATE-JWT'],
            '/host/header' => ['status' => 200, 'data' => ['host_data' => ['id' => 4253, 'domainstatus' => 'Active'],
                'module_button' => ['console' => [['type' => 'default', 'func' => 'vnc']]]]],
            '/provision/default' => $consoleResponse ?? c4VncResponse(),
            default => throw new LogicException('Unexpected administrator VNC request: '.$request->url()),
        });
    });
}

function c4VncSignedUrl(Instance $instance, User $actor, array $claims = []): string
{
    return URL::temporarySignedRoute('admin.instance.vnc', now()->addMinutes(5), $claims + [
        'instance' => $instance->id, 'actor_id' => $actor->id, 'auth_revision' => $actor->auth_revision,
        'binding' => app(AdminMachineAccess::class)->fingerprint($instance), 'fingerprint' => hash('sha256', 'connection-fragment'),
    ], absolute: false);
}

it('returns only a signed local page with a fragment connection and actual administrator binding', function (): void {
    $instance = c4VncInstance();
    $actor = c4VncActor();
    c4VncFake();
    $requestId = (string) Str::ulid();
    $response = $this->actingAs($actor)->post('/admin/upstream-instances/'.$instance->id.'/machine/command', [
        'command' => 'vnc', 'request_id' => $requestId,
    ])->assertRedirect()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertSessionMissing('_old_input');
    [$page, $fragment] = explode('#', $response->headers->get('Location'), 2);
    parse_str(parse_url($page, PHP_URL_QUERY), $claims);
    expect(parse_url($page, PHP_URL_PATH))->toBe('/admin/instances/'.$instance->id.'/vnc')
        ->and($claims['actor_id'])->toBe((string) $actor->id)->and($claims['auth_revision'])->toBe((string) $actor->auth_revision)
        ->and($claims['binding'])->toBe(app(AdminMachineAccess::class)->fingerprint($instance->fresh()))
        ->and($claims['fingerprint'])->toBe(hash('sha256', $fragment))->and((int) $claims['expires'])->toBe(now()->addMinutes(5)->getTimestamp())
        ->and($page)->not->toContain('PRIVATE', 'console.test', 'password', 'host_token')
        ->and(json_decode(base64_decode($fragment, true), true))->toBe([
            'url' => 'wss://console.test/ws?token=ADMIN-VNC-PRIVATE-TOKEN', 'password' => 'ADMIN-VNC-PRIVATE-PASSWORD',
        ]);
    $this->get($page)->assertOk()->assertSee('/js/direct-vnc.js')->assertSee('/admin/instances/'.$instance->id)
        ->assertDontSee('ADMIN-VNC-PRIVATE')->assertDontSee('console.test')->assertDontSee($fragment)
        ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Frame-Options', 'DENY')->assertHeader('Content-Security-Policy', "default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; connect-src wss:; img-src 'self' data:; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");
    Http::assertSentCount(3);
    Http::assertSent(fn ($request) => parse_url($request->url(), PHP_URL_PATH) === '/provision/default'
        && $request['func'] === 'vnc' && $request['id'] === '4253');
    $logs = DB::table('audit_logs')->get()->toJson().DB::table('upstream_operations')->get()->toJson().$instance->fresh()->toJson();
    expect($logs)->not->toContain('ADMIN-VNC-PRIVATE', $fragment);
    $this->assertDatabaseHas('audit_logs', ['action' => 'instance.machine.request.'.$requestId, 'actor_id' => $actor->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'instance.machine.result.'.$requestId, 'actor_id' => $actor->id]);
    foreach (['invoices', 'financial_transactions', 'fulfillment_tasks', 'notification_events'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
});

it('rejects a signed console claim for another actor revision or binding', function (string $claim): void {
    $instance = c4VncInstance();
    $actor = c4VncActor();
    $claims = match ($claim) {
        'actor' => ['actor_id' => User::factory()->create()->id],
        'revision' => ['auth_revision' => $actor->auth_revision + 1],
        'binding' => ['binding' => hash('sha256', 'another-binding')],
        'invalid binding' => ['binding' => 'invalid'],
        'invalid fingerprint' => ['fingerprint' => 'invalid'],
        'missing fingerprint' => ['fingerprint' => ''],
    };
    $this->actingAs($actor)->get(c4VncSignedUrl($instance, $actor, $claims))->assertForbidden();
    Http::assertNothingSent();
})->with(['actor', 'revision', 'binding', 'invalid binding', 'invalid fingerprint', 'missing fingerprint']);

it('rejects an unsigned tampered or expired administrator console URL', function (string $invalid): void {
    $instance = c4VncInstance();
    $actor = c4VncActor();
    $url = c4VncSignedUrl($instance, $actor);
    if ($invalid === 'unsigned') {
        $url = '/admin/instances/'.$instance->id.'/vnc';
    } elseif ($invalid === 'tampered') {
        $url = str_replace('binding=', 'binding=0', $url);
    } else {
        $this->travel(6)->minutes();
    }
    $this->actingAs($actor)->get($url)->assertForbidden();
    Http::assertNothingSent();
})->with(['unsigned', 'tampered', 'expired']);

it('does not transfer console authority to another permitted administrator or a new login revision', function (string $change): void {
    $instance = c4VncInstance();
    $actor = c4VncActor();
    $url = c4VncSignedUrl($instance, $actor);
    if ($change === 'another administrator') {
        $actor = c4VncActor();
    } else {
        $actor->forceFill(['auth_revision' => $actor->auth_revision + 1])->save();
    }
    $this->actingAs($actor->fresh())->withSession([EnsureAccountAccess::REVISION_KEY => $actor->auth_revision])
        ->get($url)->assertForbidden();
    Http::assertNothingSent();
})->with(['another administrator', 'new login revision']);

it('requires a currently authorized active administrator and a retained original owner for the signed page', function (string $invalid): void {
    $instance = c4VncInstance();
    $actor = c4VncActor();
    $url = c4VncSignedUrl($instance, $actor);
    match ($invalid) {
        'disabled actor' => $actor->forceFill(['disabled_at' => now()])->save(),
        'closed actor' => $actor->forceFill(['deleted_at' => now(), 'anonymized_at' => now()])->save(),
        'revoked actor' => $actor->revokePermissionTo('upstream.machine-control'),
        'missing view' => $actor->revokePermissionTo('upstream.view'),
        'closed owner' => $instance->user->forceFill(['deleted_at' => now()])->save(),
        'anonymized owner' => $instance->user->forceFill(['anonymized_at' => now()])->save(),
        'customer' => $actor = $instance->user,
    };
    $response = $this->actingAs($actor)->get($url);
    if ($invalid === 'disabled actor') {
        $response->assertRedirect('/admin/login');
    } else {
        $response->assertForbidden();
    }
    $response->assertDontSee('/js/direct-vnc.js');
    Http::assertNothingSent();
})->with(['disabled actor', 'closed actor', 'revoked actor', 'missing view', 'closed owner', 'anonymized owner', 'customer']);

it('allows a retained disabled owner but requires an authenticated administrator for the signed page', function (): void {
    $instance = c4VncInstance();
    $instance->user->forceFill(['disabled_at' => now()])->save();
    $actor = c4VncActor();
    $url = c4VncSignedUrl($instance, $actor);
    $this->get($url)->assertRedirect('/admin/login');
    $this->actingAs($actor)->get($url)->assertOk()->assertSee('/js/direct-vnc.js');
    Http::assertNothingSent();
});

it('requires an active classic bound service and enabled classic account even with matching signed claims', function (string $invalid): void {
    $instance = c4VncInstance();
    $actor = c4VncActor();
    match ($invalid) {
        'suspended' => $instance->update(['status' => InstanceStatus::SUSPENDED]),
        'deleted' => $instance->update(['status' => InstanceStatus::DELETED]),
        'local card' => $instance->update(['driver_type' => DriverType::REDEMPTION]),
        'sub2api' => $instance->update(['driver_type' => DriverType::SUB2API]),
        'no host' => $instance->update(['upstream_instance_id' => null]),
        'blank host' => $instance->update(['upstream_instance_id' => '']),
        'no account' => $instance->update(['upstream_api_id' => null]),
        'disabled account' => $instance->upstreamApi->update(['is_active' => false]),
        'other account driver' => $instance->upstreamApi->update(['driver' => DriverType::SUB2API]),
    };
    $this->actingAs($actor)->get(c4VncSignedUrl($instance->fresh(), $actor))->assertForbidden()->assertDontSee('/js/direct-vnc.js');
    $this->post('/admin/upstream-instances/'.$instance->id.'/machine/command', ['command' => 'vnc', 'request_id' => (string) Str::ulid()])
        ->assertUnprocessable();
    Http::assertNothingSent();
})->with(['suspended', 'deleted', 'local card', 'sub2api', 'no host', 'blank host', 'no account', 'disabled account', 'other account driver']);

it('invalidates the signed page after its binding or account credentials change', function (string $change): void {
    $instance = c4VncInstance();
    $actor = c4VncActor();
    $url = c4VncSignedUrl($instance, $actor);
    match ($change) {
        'owner' => $instance->update(['user_id' => User::factory()->create()->id]),
        'host' => $instance->update(['upstream_instance_id' => '9000']),
        'token' => $instance->update(['downstream_token' => 'rotated-token']),
        'base url' => $instance->upstreamApi->update(['base_url' => 'https://another.test']),
        'credentials' => $instance->upstreamApi->update(['encrypted_secret' => 'rotated-secret']),
    };
    $this->actingAs($actor)->get($url)->assertForbidden();
    Http::assertNothingSent();
})->with(['owner', 'host', 'token', 'base url', 'credentials']);

it('refuses to sign a returned console for a binding changed after the execution action returned', function (string $change): void {
    $instance = c4VncInstance();
    $actor = c4VncActor();
    c4VncFake();
    $realExecute = new ExecuteCustomerPowerCommandAction;
    $this->mock(ExecuteCustomerPowerCommandAction::class, function (MockInterface $mock) use ($realExecute, $instance, $change): void {
        $mock->shouldReceive('executeAsAdmin')->once()->andReturnUsing(function (User $actor, Instance $snapshot, ProvisionCommand $command, array $input) use ($realExecute, $instance, $change) {
            $result = $realExecute->executeAsAdmin($actor, $snapshot, $command, $input);
            match ($change) {
                'host' => $instance->fresh()->update(['upstream_instance_id' => '9000']),
                'owner' => $instance->fresh()->update(['user_id' => User::factory()->create()->id]),
                'credentials' => $instance->upstreamApi->update(['encrypted_secret' => 'rotated-after-return']),
                'status' => $instance->fresh()->update(['status' => InstanceStatus::SUSPENDED]),
                'account activation' => $instance->upstreamApi->update(['is_active' => false]),
            };

            return $result;
        });
    });
    $requestId = (string) Str::ulid();
    $this->actingAs($actor)->post('/admin/upstream-instances/'.$instance->id.'/machine/command', [
        'command' => 'vnc', 'request_id' => $requestId,
    ])->assertStatus(409)->assertHeaderMissing('Location')->assertDontSee('ADMIN-VNC-PRIVATE');
    Http::assertSentCount(3);
    $this->assertDatabaseHas('audit_logs', ['action' => 'instance.machine.result.'.$requestId, 'actor_id' => $actor->id]);
})->with(['host', 'owner', 'credentials', 'status', 'account activation']);

it('does not flash machine inputs when command validation or safe console decoding fails', function (string $invalid): void {
    $instance = c4VncInstance();
    $actor = c4VncActor();
    $input = ['command' => 'vnc', 'request_id' => (string) Str::ulid(), 'temp_pass' => 'ADMIN-VNC-PRIVATE-INPUT'];
    if ($invalid === 'invalid command') {
        $input['command'] = 'exitRescue';
    } elseif ($invalid === 'long note') {
        $input['reason'] = str_repeat('a', 256);
    } else {
        c4VncFake(['status' => 200, 'data' => ['url' => 'https://supplier.test/dcim/novnc?url='.base64_encode('ws://console.test/insecure')]]);
    }
    $this->actingAs($actor)->post('/admin/upstream-instances/'.$instance->id.'/machine/command', $input)
        ->assertRedirect('/admin/instances/'.$instance->id)->assertSessionHasErrors()->assertSessionMissing('_old_input')
        ->assertDontSee('ADMIN-VNC-PRIVATE');
    if ($invalid !== 'insecure console') {
        Http::assertNothingSent();
    }
})->with(['invalid command', 'long note', 'insecure console']);
