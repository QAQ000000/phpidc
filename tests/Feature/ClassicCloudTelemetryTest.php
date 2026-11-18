<?php

declare(strict_types=1);

use App\Actions\ApplyInstanceSnapshotAction;
use App\Actions\CallUpstreamAction;
use App\Enums\DriverType;
use App\Enums\InstanceStatus;
use App\Models\Instance;
use App\Models\Product;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Services\DriverManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(fn () => $this->installApplicationForTest());

function cloudTelemetryInstance(): Instance
{
    $account = UpstreamApi::factory()->create();
    $product = Product::factory()->create(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id, 'upstream_pid' => 14]);

    return Instance::factory()->create(['product_id' => $product->id, 'driver_type' => DriverType::ZJMF_API,
        'upstream_api_id' => $account->id, 'upstream_instance_id' => '4253', 'status' => InstanceStatus::ACTIVE,
        'active_config' => ['upstream_panel' => ['machine_status' => true, 'charts' => ['cpu', 'disk', 'memory', 'flow'], 'information' => false]]])->fresh();
}

function fakeCloudTelemetry(?Closure $override = null): void
{
    $level = DB::transactionLevel();
    Http::preventStrayRequests();
    Http::fake(function ($request) use ($level, $override) {
        expect(DB::transactionLevel())->toBe($level);
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($override !== null && ($result = $override($path, $request)) !== null) {
            return $result;
        }
        if ($path === '/provision/chart/4253') {
            $types = ['cpu' => 'area', 'disk' => 'line', 'memory' => 'bar', 'flow' => 'line'];

            return Http::response(['status' => 200, 'data' => ['chart_type' => $types[$request['type']], 'unit' => $request['type'] === 'cpu' ? '%' : 'KB/s',
                'label' => ['Sample'], 'list' => [[['time' => '2026-11-18 00:00:00', 'value' => 0], ['time' => '2026-11-18 00:02:00', 'value' => 5.59]]]]]);
        }

        return Http::response(match ($path) {
            '/zjmf_api_login' => ['status' => 200, 'jwt' => 'PRIVATE-CLOUD-JWT'],
            '/host/header' => ['status' => 200, 'data' => ['host_data' => ['id' => 4253, 'productid' => 14, 'domainstatus' => 'Active',
                'dedicatedip' => '192.0.2.14', 'assignedips' => ['192.0.2.15'], 'port' => 22,
                'username' => 'root', 'password' => 'PRIVATE-CLOUD-PASSWORD', 'os' => 'Ubuntu-20.04.1-x64'],
                'module_power_status' => true, 'module_client_area' => [['key' => 'setting', 'name' => '设置']],
                'module_chart' => [['type' => 'cpu', 'title' => 'CPU使用量'], ['type' => 'disk', 'title' => '硬盘IO'],
                    ['type' => 'memory', 'title' => '内存用量'], ['type' => 'flow', 'title' => '网卡']]]],
            '/provision/default' => ['status' => 200, 'data' => ['status' => 'on', 'des' => '开机']],
            default => throw new RuntimeException('Unexpected cloud telemetry request: '.$path),
        });
    });
}

it('syncs cloud IP port and login fields while leaving live telemetry for an explicit owner request', function (): void {
    $instance = cloudTelemetryInstance();
    $before = $instance->getRawOriginal();
    fakeCloudTelemetry();
    $snapshot = app(CallUpstreamAction::class)->execute(fn () => app(DriverManager::class)->classicAccount($instance->upstream_api_id)->syncInfo($instance));
    expect($instance->fresh()->getRawOriginal())->toBe($before);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/provision/'));
    app(ApplyInstanceSnapshotAction::class)->execute($instance, $snapshot, 'Read existing cloud service.');
    $current = $instance->fresh();
    expect($current->primary_ip)->toBe('192.0.2.14')->and($current->assigned_ips)->toBe(['192.0.2.15'])
        ->and($current->encrypted_credentials)->toBe(['username' => 'root', 'password' => 'PRIVATE-CLOUD-PASSWORD', 'port' => 22, 'os' => 'Ubuntu-20.04.1-x64'])
        ->and($current->getRawOriginal('encrypted_credentials'))->not->toContain('PRIVATE-CLOUD-PASSWORD')
        ->and($current->active_config['upstream_panel'])->toBe(['machine_status' => true, 'charts' => ['cpu', 'disk', 'memory', 'flow'], 'information' => false]);
    $this->actingAs($instance->user)->get('/console/instances/'.$instance->id)->assertOk()
        ->assertSee('192.0.2.14')->assertSee('登录端口：22')->assertSee('Ubuntu-20.04.1-x64')
        ->assertSee('PRIVATE-CLOUD-PASSWORD')->assertSee('查看机器状态与监控图表')->assertDontSee('登录面板');
    expect(DB::table('upstream_operations')->pluck('response_body')->implode(' '))->not->toContain('PRIVATE-CLOUD-PASSWORD', 'PRIVATE-CLOUD-JWT');
});

it('renders authenticated machine status and four supplier charts without changing the instance or billing', function (): void {
    $instance = cloudTelemetryInstance();
    $before = $instance->getRawOriginal();
    fakeCloudTelemetry();
    $response = $this->actingAs($instance->user)->post('/console/instances/'.$instance->id.'/telemetry')->assertOk()
        ->assertSee('机器状态：开机')->assertSee('CPU使用量')->assertSee('硬盘IO')->assertSee('内存用量')->assertSee('网卡')
        ->assertSee('2 个有效采样点')->assertSee('最新值 5.59')->assertDontSee('PRIVATE-CLOUD-JWT')->assertDontSee('PRIVATE-CLOUD-PASSWORD')
        ->assertHeader('Cache-Control', 'no-store, private');
    expect(substr_count($response->getContent(), '<svg '))->toBe(4)
        ->and($instance->fresh()->getRawOriginal())->toBe($before);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/provision/default') && $r->method() === 'POST' && $r['id'] === '4253' && $r['func'] === 'status');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/provision/chart/4253') && $r->method() === 'GET'
        && $r['end'] - $r['start'] === 86400000);
    expect(DB::table('upstream_operations')->where('path', '/provision/chart/4253')->count())->toBe(4);
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('fulfillment_tasks', 0);
});

it('blocks anonymous and foreign users from cloud telemetry before HTTP', function (): void {
    $instance = cloudTelemetryInstance();
    Http::fake();
    $this->post('/console/instances/'.$instance->id.'/telemetry')->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->post('/console/instances/'.$instance->id.'/telemetry')->assertForbidden();
    Http::assertNothingSent();
});

it('blocks inactive services and disabled accounts before telemetry HTTP', function (string $case): void {
    $instance = cloudTelemetryInstance();
    if ($case === 'disabled account') {
        $instance->upstreamApi->update(['is_active' => false]);
    } else {
        $instance->update(['status' => InstanceStatus::SUSPENDED]);
    }
    Http::fake();
    $this->actingAs($instance->user)->postJson('/console/instances/'.$instance->id.'/telemetry')->assertUnprocessable();
    Http::assertNothingSent();
})->with(['disabled account', 'suspended']);

it('discards telemetry when a service changes binding during HTTP', function (): void {
    $instance = cloudTelemetryInstance();
    fakeCloudTelemetry(function ($path) use ($instance) {
        if ($path === '/provision/default') {
            Instance::whereKey($instance->id)->update(['upstream_instance_id' => 'different-host']);
        }

        return null;
    });
    $this->actingAs($instance->user)->postJson('/console/instances/'.$instance->id.'/telemetry')
        ->assertUnprocessable()->assertDontSee('5.59');
});

it('marks a failed chart explicitly unavailable while retaining successful charts', function (): void {
    $instance = cloudTelemetryInstance();
    fakeCloudTelemetry(fn ($path, $r) => $path === '/provision/chart/4253' && $r['type'] === 'cpu' ? Http::response(['status' => 400]) : null);
    $response = $this->actingAs($instance->user)->post('/console/instances/'.$instance->id.'/telemetry')->assertOk()
        ->assertSee('上游暂未返回可用数据')->assertSee('机器状态：开机');
    expect(substr_count($response->getContent(), '<svg '))->toBe(3);
});

it('does not confuse business Active with an off or unavailable machine state', function (array $status, string $expected): void {
    $instance = cloudTelemetryInstance();
    fakeCloudTelemetry(fn ($path) => $path === '/provision/default' ? Http::response($status) : null);
    $this->actingAs($instance->user)->post('/console/instances/'.$instance->id.'/telemetry')->assertOk()->assertSee($expected);
    expect($instance->fresh()->status)->toBe(InstanceStatus::ACTIVE);
})->with([
    'off' => [['status' => 200, 'data' => ['status' => 'off']], '机器状态：关机'],
    'failure' => [['status' => 400], '上游暂未返回可用数据'],
]);

it('rejects malformed chart samples instead of plotting a healthy zero', function (mixed $value): void {
    $instance = cloudTelemetryInstance();
    fakeCloudTelemetry(fn ($path, $r) => $path === '/provision/chart/4253' && $r['type'] === 'cpu' ? Http::response(['status' => 200,
        'data' => ['chart_type' => 'area', 'unit' => '%', 'label' => ['CPU'], 'list' => [[['time' => '2026-11-18 00:00:00', 'value' => $value]]]]]) : null);
    $response = $this->actingAs($instance->user)->post('/console/instances/'.$instance->id.'/telemetry')->assertOk()->assertSee('上游暂未返回可用数据');
    expect(substr_count($response->getContent(), '<svg '))->toBe(3);
})->with(['invalid string' => ['invalid'], 'nested value' => [['unsafe']]]);

it('reports missing samples as no data while retaining legitimate zero samples', function (): void {
    $instance = cloudTelemetryInstance();
    fakeCloudTelemetry(fn ($path, $r) => $path === '/provision/chart/4253' && $r['type'] === 'cpu' ? Http::response(['status' => 200,
        'data' => ['chart_type' => 'area', 'unit' => '%', 'label' => ['CPU'], 'list' => [[['time' => '2026-11-18 00:00:00', 'value' => null]]]]]) : null);
    $this->actingAs($instance->user)->post('/console/instances/'.$instance->id.'/telemetry')->assertOk()
        ->assertSee('该时间段暂无采样数据')->assertSee('2 个有效采样点');
});
