<?php

declare(strict_types=1);

use App\Enums\DriverType;
use App\Enums\InstanceStatus;
use App\Models\Instance;
use App\Models\Product;
use App\Models\UpstreamApi;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(fn () => $this->installApplicationForTest());

function powerStatusInstance(): Instance
{
    $account = UpstreamApi::factory()->create();
    $product = Product::factory()->create(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id, 'upstream_pid' => 14]);

    return Instance::factory()->create(['product_id' => $product->id, 'driver_type' => DriverType::ZJMF_API,
        'upstream_api_id' => $account->id, 'upstream_instance_id' => '4253', 'status' => InstanceStatus::ACTIVE,
        'active_config' => ['upstream_panel' => ['machine_status' => true, 'charts' => ['cpu', 'disk', 'memory', 'flow'], 'information' => false]]])->fresh();
}

function fakePowerStatus(?Closure $status = null): void
{
    $level = DB::transactionLevel();
    Http::preventStrayRequests();
    Http::fake(function ($request) use ($status, $level) {
        expect(DB::transactionLevel())->toBe($level);
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/zjmf_api_login') {
            return Http::response(['status' => 200, 'jwt' => 'PRIVATE-STATUS-JWT']);
        }
        // Any header, chart, panel or mutation here is the original latency regression.
        expect($path)->toBe('/provision/default')->and($request->method())->toBe('POST')
            ->and($request['id'])->toBe('4253')->and($request['is_api'])->toBe(1)->and($request['func'])->toBe('status');

        return $status !== null ? $status($request) : Http::response(['status' => 200, 'data' => ['status' => 'on']]);
    });
}

it('reads power state through one status request without fetching the header panel controls or charts', function (): void {
    $instance = powerStatusInstance();
    $before = $instance->getRawOriginal();
    $wallet = $instance->user->getRawOriginal();
    fakePowerStatus();
    $this->actingAs($instance->user)->postJson('/console/instances/'.$instance->id.'/power-status', ['func' => 'off', 'command' => 'reinstall'])
        ->assertOk()->assertJsonPath('available', true)->assertJsonPath('state', 'on')->assertJsonStructure(['checked_at'])
        ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')->assertDontSee('PRIVATE-STATUS-JWT');
    Http::assertSentCount(2); // Initial auth plus exactly one status request.
    expect(DB::table('upstream_operations')->where('path', '/provision/default')->count())->toBe(1)
        ->and(DB::table('upstream_operations')->where('path', '/host/header')->count())->toBe(0)
        ->and($instance->fresh()->getRawOriginal())->toBe($before)->and($instance->user->fresh()->getRawOriginal())->toBe($wallet);
    foreach (['invoices', 'financial_transactions', 'fulfillment_tasks', 'audit_logs'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    expect(DB::table('upstream_operations')->get()->toJson())->not->toContain('PRIVATE-STATUS-JWT');
});

it('shows the independent status button without causing upstream I/O on the service page', function (): void {
    $instance = powerStatusInstance();
    Http::fake();
    $this->actingAs($instance->user)->get('/console/instances/'.$instance->id)->assertOk()
        ->assertSee('查看机器电源状态')->assertSee('/power-status')->assertSee('/js/power-status.js')
        ->assertSee('aria-live="polite"', false)->assertSee('查看机器状态与监控图表');
    Http::assertNothingSent();
});

it('retains a usable status page when javascript is disabled', function (): void {
    $instance = powerStatusInstance();
    fakePowerStatus();
    $response = $this->actingAs($instance->user)->post('/console/instances/'.$instance->id.'/power-status')
        ->assertOk()->assertSee('机器电源状态：开机')->assertSee('查看机器电源状态');
    expect(substr_count($response->getContent(), '<svg '))->toBe(0);
    Http::assertSentCount(2);
});

it('keeps Active billing status distinct from the independently queried power state', function (string $state): void {
    $instance = powerStatusInstance();
    fakePowerStatus(fn () => Http::response(['status' => 200, 'data' => ['status' => $state]]));
    $this->actingAs($instance->user)->postJson('/console/instances/'.$instance->id.'/power-status')
        ->assertOk()->assertJsonPath('state', $state)->assertJsonPath('available', true);
    expect($instance->fresh()->status)->toBe(InstanceStatus::ACTIVE);
})->with(['on', 'off', 'unknown']);

it('does not cache a previous on state as the next real time result', function (): void {
    $instance = powerStatusInstance();
    $count = 0;
    fakePowerStatus(function () use (&$count) {
        return Http::response(['status' => 200, 'data' => ['status' => $count++ === 0 ? 'on' : 'off']]);
    });
    $this->actingAs($instance->user)->postJson('/console/instances/'.$instance->id.'/power-status')->assertJsonPath('state', 'on');
    $this->postJson('/console/instances/'.$instance->id.'/power-status')->assertJsonPath('state', 'off');
    expect($count)->toBe(2);
});

it('reports invalid or rejected status as unavailable without exposing supplier data', function (array $body): void {
    $instance = powerStatusInstance();
    fakePowerStatus(fn () => Http::response($body + ['password' => 'PRIVATE-STATUS-PASSWORD']));
    $this->actingAs($instance->user)->postJson('/console/instances/'.$instance->id.'/power-status')
        ->assertStatus(502)->assertJsonPath('available', false)->assertJsonPath('state', 'unavailable')->assertDontSee('PRIVATE-STATUS-PASSWORD');
})->with([
    [['status' => 400]], [['status' => 200, 'data' => ['status' => 'Active']]], [['status' => 200, 'data' => ['status' => ['on']]]],
]);

it('reports a status timeout once without queuing or replaying a machine command', function (): void {
    $instance = powerStatusInstance();
    fakePowerStatus(function () {
        throw new ConnectionException('Read timed out');
    });
    $this->actingAs($instance->user)->postJson('/console/instances/'.$instance->id.'/power-status')->assertStatus(502)->assertJsonPath('available', false);
    expect(DB::table('upstream_operations')->where('path', '/provision/default')->count())->toBe(1)
        ->and(DB::table('upstream_operations')->where('path', '/provision/default')->value('outcome'))->toBe('failed')
        ->and($instance->fresh()->active_config)->not->toHaveKey('cloud_operation');
    $this->assertDatabaseCount('fulfillment_tasks', 0);
});

it('blocks guests and other owners before status HTTP', function (): void {
    $instance = powerStatusInstance();
    Http::fake();
    $this->post('/console/instances/'.$instance->id.'/power-status')->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->postJson('/console/instances/'.$instance->id.'/power-status')->assertForbidden();
    Http::assertNothingSent();
});

it('rejects unsupported status reads and disabled or suspended bindings before HTTP', function (string $case): void {
    $instance = powerStatusInstance();
    if ($case === 'disabled') {
        $instance->upstreamApi->update(['is_active' => false]);
    } elseif ($case === 'unsupported') {
        $instance->update(['active_config' => []]);
    } else {
        $instance->update(['status' => InstanceStatus::SUSPENDED]);
    }
    Http::fake();
    $this->actingAs($instance->user)->postJson('/console/instances/'.$instance->id.'/power-status')->assertUnprocessable();
    Http::assertNothingSent();
})->with(['disabled', 'unsupported', 'suspended']);

it('discards status if ownership binding or account activation changes during HTTP', function (string $case): void {
    $instance = powerStatusInstance();
    fakePowerStatus(function () use ($instance, $case) {
        if ($case === 'binding') {
            $instance->fresh()->update(['upstream_instance_id' => '9999']);
        } elseif ($case === 'owner') {
            $instance->fresh()->update(['user_id' => User::factory()->create()->id]);
        } else {
            $instance->upstreamApi->update(['is_active' => false]);
        }

        return Http::response(['status' => 200, 'data' => ['status' => 'on']]);
    });
    $this->actingAs($instance->user)->postJson('/console/instances/'.$instance->id.'/power-status')
        ->assertUnprocessable()->assertJsonMissing(['state' => 'on']);
})->with(['binding', 'owner', 'disabled']);
