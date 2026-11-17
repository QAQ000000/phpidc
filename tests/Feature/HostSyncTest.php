<?php

declare(strict_types=1);

use App\Enums\InstanceStatus;
use App\Jobs\ReconcileHostSyncJob;
use App\Models\Instance;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('verifies zjmf callback signature, blocks rand_str replay, and never clears suspend_reason', function (): void {
    $instance = Instance::factory()->create([
        'driver_type' => 'zjmf_api',
        'downstream_token' => str_repeat('a', 32),
        'suspend_reason' => 'Overdue billing',
        'status' => InstanceStatus::SUSPENDED->value,
    ]);

    $params = ['id' => $instance->id, 'token' => $instance->downstream_token, 'rand_str' => 'AbCd12'];
    ksort($params, SORT_STRING);
    $signature = strtoupper(md5(json_encode($params)));

    $payload = [
        'id' => $instance->id,
        'rand_str' => 'AbCd12',
        'signature' => $signature,
        'domainstatus' => 'Active',
        'suspendreason' => '',
    ];

    $this->postJson('/api/host/sync', $payload)->assertOk();
    expect($instance->fresh()->suspend_reason)->toBe('Overdue billing');

    // Same rand_str replays: rejected (INV-Q4).
    $this->postJson('/api/host/sync', $payload)->assertStatus(400);

    // A different instance may reuse the same rand_str (key is scoped to instance).
    $other = Instance::factory()->create([
        'driver_type' => 'zjmf_api',
        'downstream_token' => str_repeat('b', 32),
    ]);
    $otherParams = ['id' => $other->id, 'token' => $other->downstream_token, 'rand_str' => 'AbCd12'];
    ksort($otherParams, SORT_STRING);

    $this->postJson('/api/host/sync', [
        'id' => $other->id,
        'rand_str' => 'AbCd12',
        'signature' => strtoupper(md5(json_encode($otherParams))),
        'domainstatus' => 'Active',
    ])->assertOk();
});

it('responds to host sync before reconciling upstream', function (): void {
    Http::fake(['*/host/header*' => Http::response(['status' => 200, 'data' => ['host_data' => []]])]);
    Queue::fake();

    $instance = Instance::factory()->create([
        'driver_type' => 'zjmf_api',
        'downstream_token' => str_repeat('c', 32),
    ]);
    $params = ['id' => $instance->id, 'token' => $instance->downstream_token, 'rand_str' => 'Zz12Ab'];
    ksort($params, SORT_STRING);

    $this->postJson('/api/host/sync', [
        'id' => $instance->id,
        'rand_str' => 'Zz12Ab',
        'signature' => strtoupper(md5(json_encode($params))),
    ])->assertOk();

    // INV-Q3: the handler must not perform the upstream round trip inline.
    Http::assertNothingSent();
    Queue::assertPushed(ReconcileHostSyncJob::class);
});
