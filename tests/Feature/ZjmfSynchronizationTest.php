<?php

declare(strict_types=1);

use App\Actions\ReconcileHostSyncAction;
use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\UpstreamApi;
use App\Services\DriverManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function classicInstance(array $attributes = []): Instance
{
    $account = UpstreamApi::create(['name' => 'Classic', 'driver' => DriverType::ZJMF_API,
        'base_url' => 'https://classic.test', 'username' => 'operator@example.test', 'encrypted_secret' => 'api-secret']);

    return Instance::factory()->create($attributes + ['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id,
        'upstream_instance_id' => '999', 'status' => InstanceStatus::ACTIVE]);
}

beforeEach(fn () => Http::preventStrayRequests());

it('re-queries authenticated host data and atomically records state, encrypted credentials and redacted operations', function (): void {
    $instance = classicInstance();
    $level = DB::transactionLevel();
    Http::fake(function ($request) use ($level) {
        expect(DB::transactionLevel())->toBe($level);
        if (str_ends_with($request->url(), '/zjmf_api_login')) {
            expect($request['password'])->toBe('api-secret');

            return Http::response(['status' => 200, 'jwt' => 'private-jwt']);
        }
        expect($request->header('Authorization'))->toBe(['Bearer private-jwt']);

        return Http::response(['status' => 200, 'data' => ['host_data' => ['domainstatus' => 'Suspended',
            'suspendreason' => 'Upstream expired', 'password' => 'host-password', 'dedicatedip' => '192.0.2.9']]]);
    });
    app(ReconcileHostSyncAction::class)->execute($instance->id, $instance->upstream_api_id, '999', $instance->downstream_token);
    expect($instance->fresh()->status)->toBe(InstanceStatus::SUSPENDED)
        ->and($instance->fresh()->encrypted_credentials['password'])->toBe('host-password')
        ->and(DB::table('instances')->value('encrypted_credentials'))->not->toContain('host-password');
    $this->assertDatabaseHas('audit_logs', ['action' => 'instance.upstream_sync', 'subject_id' => $instance->id]);
    $this->assertDatabaseCount('upstream_operations', 2);
    $logs = json_encode(DB::table('upstream_operations')->get());
    foreach (['api-secret', 'private-jwt', 'host-password'] as $secret) {
        expect($logs)->not->toContain($secret);
    }
});

it('keeps drivers read only and caches encrypted JWTs with one bounded reauthentication', function (): void {
    $instance = classicInstance();
    Http::fakeSequence()->push(['status' => 200, 'jwt' => 'old-jwt'])->push(['status' => 405])
        ->push(['status' => 200, 'jwt' => 'new-jwt'])->push(['status' => 200, 'data' => ['host_data' => ['domainstatus' => 'Suspended']]])
        ->push(['status' => 200, 'data' => ['host_data' => ['domainstatus' => 'Active']]]);
    $driver = app(DriverManager::class)->fulfillment($instance->driver_type, $instance->upstream_api_id);
    expect($driver->syncInfo($instance)->upstreamStatus)->toBe('Suspended');
    expect($driver->syncInfo($instance)->upstreamStatus)->toBe('Active')
        ->and($instance->fresh()->status)->toBe(InstanceStatus::ACTIVE);
    $this->assertDatabaseCount('upstream_operations', 0);
    Http::assertSentCount(5);
    $key = 'zjmf:jwt:'.hash('sha256', $instance->upstream_api_id.'|https://classic.test|operator@example.test|api-secret');
    expect(Cache::get($key))->not->toContain('new-jwt');
});

it('ignores unsigned callback fields and rejects bad signatures without poisoning replay keys', function (): void {
    Queue::fake();
    $instance = classicInstance(['suspend_reason' => 'Keep reason']);
    $params = ['id' => (string) $instance->id, 'token' => $instance->downstream_token, 'rand_str' => 'ABC123'];
    ksort($params, SORT_STRING);
    $payload = ['id' => (string) $instance->id, 'rand_str' => 'ABC123', 'signature' => str_repeat('f', 32),
        'domainstatus' => 'Deleted', 'password' => 'forged-password', 'suspendreason' => 'forged'];
    $this->post('/api/host/sync', $payload)->assertUnprocessable();
    $payload['signature'] = md5(json_encode($params));
    $this->post('/api/host/sync', $payload)->assertOk();
    expect($instance->fresh()->status)->toBe(InstanceStatus::ACTIVE)
        ->and($instance->fresh()->suspend_reason)->toBe('Keep reason')
        ->and($instance->fresh()->encrypted_credentials)->toBeNull();
    $this->post('/api/host/sync', $payload)->assertStatus(400);
    Http::assertNothingSent();
});

it('discards a queued host sync after rebinding and marks tasks stale on authenticated deletion', function (): void {
    $instance = classicInstance(['status' => InstanceStatus::SUSPENDED]);
    $task = FulfillmentTask::factory()->create(['instance_id' => $instance->id, 'user_id' => $instance->user_id,
        'driver_type' => $instance->driver_type, 'upstream_api_id' => $instance->upstream_api_id]);
    $action = app(ReconcileHostSyncAction::class);
    $action->execute($instance->id, $instance->upstream_api_id, 'old-host', $instance->downstream_token);
    Http::assertNothingSent();
    Http::fakeSequence()->push(['status' => 200, 'jwt' => 'jwt'])->push(['status' => 200, 'data' => ['host_data' => ['domainstatus' => 'Deleted']]]);
    $action->execute($instance->id, $instance->upstream_api_id, '999', $instance->downstream_token);
    expect($instance->fresh()->status)->toBe(InstanceStatus::DELETED)
        ->and($task->fresh()->status)->toBe(FulfillmentTaskStatus::STALE);
});
