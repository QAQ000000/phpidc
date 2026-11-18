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

function progressiveTelemetryInstance(): Instance
{
    $account = UpstreamApi::factory()->create();
    $product = Product::factory()->create(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id, 'upstream_pid' => 14]);

    return Instance::factory()->create(['product_id' => $product->id, 'driver_type' => DriverType::ZJMF_API,
        'upstream_api_id' => $account->id, 'upstream_instance_id' => '4253', 'status' => InstanceStatus::ACTIVE,
        'active_config' => ['upstream_panel' => ['machine_status' => true, 'charts' => ['cpu', 'disk', 'memory', 'flow'], 'information' => false]]])->fresh();
}

function progressiveTelemetryWindow(): array
{
    $end = now()->getTimestamp() * 1000;

    return ['start' => $end - 86400000, 'end' => $end];
}

function fakeProgressiveTelemetry(?Closure $override = null): void
{
    Http::preventStrayRequests();
    Http::fake(function ($request) use ($override) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/zjmf_api_login') {
            return Http::response(['status' => 200, 'jwt' => 'PRIVATE-PROGRESSIVE-JWT']);
        }
        expect($path)->toBe('/provision/chart/4253');
        if ($override !== null && ($result = $override($request)) !== null) {
            return $result;
        }

        return Http::response(['status' => 200, 'data' => ['chart_type' => 'line', 'unit' => '%', 'label' => ['Sample'],
            'list' => [[['time' => '2026-11-19 00:00:00', 'value' => 5.59]]]]]);
    });
}

it('opens the monitor shell and service link without any upstream call', function (): void {
    $instance = progressiveTelemetryInstance();
    Http::fake();
    $page = $this->actingAs($instance->user)->get('/console/instances/'.$instance->id.'/telemetry')->assertOk()
        ->assertSee('data-telemetry', false)->assertSee('/js/instance-telemetry.js')->assertSee('CPU使用量')
        ->assertSee('硬盘IO')->assertSee('内存用量')->assertSee('网卡')->assertHeader('Cache-Control', 'no-store, private');
    expect(substr_count($page->getContent(), 'data-telemetry-read='))->toBe(5);
    $this->get('/console/instances/'.$instance->id)->assertOk()
        ->assertSee('href="/console/instances/'.$instance->id.'/telemetry"', false);
    Http::assertNothingSent();
});

it('reads only the selected chart with the shared server-validated window and no cached values', function (): void {
    $instance = progressiveTelemetryInstance();
    $before = $instance->getRawOriginal();
    $window = progressiveTelemetryWindow();
    fakeProgressiveTelemetry();
    $path = '/console/instances/'.$instance->id.'/telemetry/chart/cpu';
    foreach (range(1, 2) as $read) {
        $response = $this->actingAs($instance->user)->postJson($path, $window + ['path' => '/provision/default', 'func' => 'off',
            'id' => 'another-host', 'password' => 'PRIVATE-IGNORED-INPUT'])->assertOk()->assertJsonPath('available', true)
            ->assertHeader('Cache-Control', 'no-store, private');
        expect($response->json('html'))->toContain('CPU使用量', '<svg ', '最新值 5.59')->not->toContain('PRIVATE-PROGRESSIVE-JWT');
        expect(DB::table('upstream_operations')->where('path', '/provision/chart/4253')->count())->toBe($read);
    }
    Http::assertSent(fn ($request) => parse_url($request->url(), PHP_URL_PATH) === '/provision/chart/4253'
        && $request['type'] === 'cpu' && $request['start'] === $window['start'] && $request['end'] === $window['end']
        && array_diff(array_keys($request->data()), ['type', 'start', 'end', 'is_api']) === []);
    expect($instance->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('fulfillment_tasks', 0);
});

it('escapes supplier chart strings in the local rendered partial', function (): void {
    $instance = progressiveTelemetryInstance();
    fakeProgressiveTelemetry(fn () => Http::response(['status' => 200, 'data' => ['chart_type' => 'line', 'unit' => '<script>bad()</script>',
        'label' => ['<img src=x onerror=bad()>'], 'list' => [[['time' => '2026-11-19 00:00:00', 'value' => 0]]]]]));
    $response = $this->actingAs($instance->user)->postJson('/console/instances/'.$instance->id.'/telemetry/chart/cpu', progressiveTelemetryWindow())->assertOk();
    expect($response->json('html'))->toContain('&lt;script&gt;', '&lt;img')->not->toContain('<script>', '<img src=x');
});

it('rejects foreign and anonymous monitor reads before upstream IO', function (): void {
    $instance = progressiveTelemetryInstance();
    Http::fake();
    $base = '/console/instances/'.$instance->id.'/telemetry';
    $this->get($base)->assertRedirect('/login');
    $this->post($base.'/chart/cpu', progressiveTelemetryWindow())->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->get($base)->assertForbidden();
    $this->postJson($base.'/chart/cpu', progressiveTelemetryWindow())->assertForbidden();
    Http::assertNothingSent();
});

it('rejects suspended or disabled monitor shells and chart requests before IO', function (string $case): void {
    $instance = progressiveTelemetryInstance();
    if ($case === 'disabled') {
        $instance->upstreamApi->update(['is_active' => false]);
    } else {
        $instance->update(['status' => InstanceStatus::SUSPENDED]);
    }
    Http::fake();
    $base = '/console/instances/'.$instance->id.'/telemetry';
    $this->actingAs($instance->user)->getJson($base)->assertUnprocessable();
    $this->postJson($base.'/chart/cpu', progressiveTelemetryWindow())->assertUnprocessable();
    Http::assertNothingSent();
})->with(['disabled', 'suspended']);

it('rejects unadvertised charts and malformed or expired windows before IO', function (string $case): void {
    $instance = progressiveTelemetryInstance();
    $window = progressiveTelemetryWindow();
    $chart = 'cpu';
    if ($case === 'unadvertised') {
        $instance->update(['active_config' => ['upstream_panel' => ['charts' => ['disk']]]]);
    } elseif ($case === 'unknown chart') {
        $chart = 'secret';
    } elseif ($case === 'missing') {
        $window = [];
    } elseif ($case === 'invalid span') {
        $window['start']++;
    } elseif ($case === 'expired') {
        $window = array_map(fn ($value) => $value - 301000, $window);
    } else {
        $window = array_map(fn ($value) => $value + 300000, $window);
    }
    Http::fake();
    $response = $this->actingAs($instance->user)->postJson('/console/instances/'.$instance->id.'/telemetry/chart/'.$chart, $window);
    $response->assertUnprocessable();
    Http::assertNothingSent();
})->with(['unadvertised', 'unknown chart', 'missing', 'invalid span', 'expired', 'future']);

it('discards a chart if ownership status binding endpoint or credentials change during IO', function (string $case): void {
    $instance = progressiveTelemetryInstance();
    $owner = $instance->user;
    fakeProgressiveTelemetry(function () use ($instance, $case) {
        match ($case) {
            'owner' => $instance->fresh()->update(['user_id' => User::factory()->create()->id]),
            'status' => $instance->fresh()->update(['status' => InstanceStatus::SUSPENDED]),
            'binding' => $instance->fresh()->update(['upstream_instance_id' => '9999']),
            'disabled' => $instance->upstreamApi->update(['is_active' => false]),
            'endpoint' => $instance->upstreamApi->update(['base_url' => 'https://changed.example.invalid']),
            'auth mode' => $instance->upstreamApi->update(['auth_header_mode' => 'raw_jwt']),
            'secret' => $instance->upstreamApi->update(['encrypted_secret' => 'changed-private-secret']),
        };

        return null;
    });
    $this->actingAs($owner)->postJson('/console/instances/'.$instance->id.'/telemetry/chart/cpu', progressiveTelemetryWindow())
        ->assertUnprocessable()->assertJsonMissingPath('html');
})->with(['owner', 'status', 'binding', 'disabled', 'endpoint', 'auth mode', 'secret']);

it('marks one timed-out chart unavailable and can still read the next chart', function (): void {
    $instance = progressiveTelemetryInstance();
    fakeProgressiveTelemetry(function ($request) {
        if ($request['type'] === 'disk') {
            throw new ConnectionException('Isolated disk timeout.');
        }

        return null;
    });
    $base = '/console/instances/'.$instance->id.'/telemetry/chart/';
    $window = progressiveTelemetryWindow();
    $this->actingAs($instance->user)->postJson($base.'disk', $window)->assertStatus(502)->assertJsonPath('available', false);
    $this->postJson($base.'cpu', $window)->assertOk()->assertJsonPath('available', true);
    expect(DB::table('upstream_operations')->where('outcome', 'failed')->count())->toBe(1)
        ->and(DB::table('upstream_operations')->where('path', '/provision/chart/4253')->count())->toBe(2);
});

it('keeps the existing telemetry rate limit for individual chart reads', function (): void {
    $instance = progressiveTelemetryInstance();
    fakeProgressiveTelemetry();
    $window = progressiveTelemetryWindow();
    $path = '/console/instances/'.$instance->id.'/telemetry/chart/cpu';
    $this->actingAs($instance->user);
    foreach (range(1, 10) as $read) {
        $this->postJson($path, $window)->assertOk();
    }
    $this->postJson($path, $window)->assertStatus(429)->assertHeader('Retry-After');
    expect(DB::table('upstream_operations')->where('path', '/provision/chart/4253')->count())->toBe(10);
});

it('requires CSRF before querying an individual chart', function (): void {
    $instance = progressiveTelemetryInstance();
    Http::fake();
    $this->actingAs($instance->user);
    $this->app->detectEnvironment(fn () => 'local');
    $this->withSession(['_token' => 'progressive-telemetry-csrf']);
    $this->post('/console/instances/'.$instance->id.'/telemetry/chart/cpu', progressiveTelemetryWindow())->assertStatus(419);
    Http::assertNothingSent();
});
