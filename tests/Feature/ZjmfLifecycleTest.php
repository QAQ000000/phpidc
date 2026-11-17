<?php

declare(strict_types=1);

use App\Actions\SuspendInstanceAction;
use App\Enums\InstanceStatus;
use App\Models\Instance;
use Illuminate\Support\Facades\Http;

it('pre-syncs upstream status before suspend and reconciles idempotently if already suspended', function (): void {
    Http::fake([
        '*/zjmf_api_login' => Http::response(['status' => 200, 'jwt' => 'a.b.c']),
        '*/host/header*' => Http::response([
            'status' => 200,
            'data' => ['host_data' => ['domainstatus' => 'Suspended', 'suspendreason' => 'Upstream expired']],
        ]),
        '*/provision/default' => Http::response(['status' => 400, 'msg' => 'Should not be called'], 400),
    ]);

    $instance = Instance::factory()->create([
        'driver_type' => 'zjmf_api',
        'upstream_instance_id' => '999',
        'status' => InstanceStatus::ACTIVE->value,
    ]);

    $result = app(SuspendInstanceAction::class)->execute($instance, 'Overdue');

    expect($result->success)->toBeTrue()
        ->and($instance->fresh()->status)->toBe(InstanceStatus::SUSPENDED);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/provision/default'));
});

it('pre-syncs and suspends an active upstream host with an audited operator reason', function (): void {
    $instance = Instance::factory()->create(['driver_type' => 'zjmf_api', 'upstream_instance_id' => '999', 'status' => InstanceStatus::ACTIVE]);
    Http::preventStrayRequests();
    Http::fakeSequence()->push(['status' => 200, 'jwt' => 'jwt'])
        ->push(['status' => 200, 'data' => ['host_data' => ['domainstatus' => 'Active']]])->push(['status' => 'success']);
    $this->artisan('upstream:classic-state', ['instance' => $instance->id, 'operation' => 'suspend', '--reason' => 'Operator approved suspension'])->assertSuccessful();
    expect($instance->fresh()->status)->toBe(InstanceStatus::SUSPENDED);
    $this->assertDatabaseHas('audit_logs', ['action' => 'instance.suspend', 'reason' => 'Operator approved suspension']);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/provision/default') && $request['func'] === 'suspend');
});

it('unsuspends only after a fresh upstream snapshot and preserves a nonempty suspension history', function (): void {
    $instance = Instance::factory()->create(['driver_type' => 'zjmf_api', 'upstream_instance_id' => '999',
        'status' => InstanceStatus::SUSPENDED, 'suspend_reason' => 'Prior suspension']);
    Http::preventStrayRequests();
    Http::fakeSequence()->push(['status' => 200, 'jwt' => 'jwt'])
        ->push(['status' => 200, 'data' => ['host_data' => ['domainstatus' => 'Suspended']]])->push(['status' => 200]);
    $this->artisan('upstream:classic-state', ['instance' => $instance->id, 'operation' => 'unsuspend', '--reason' => 'Invoice settled'])->assertSuccessful();
    expect($instance->fresh()->status)->toBe(InstanceStatus::ACTIVE)->and($instance->fresh()->suspend_reason)->toBe('Prior suspension');
    $this->assertDatabaseHas('audit_logs', ['action' => 'instance.unsuspend', 'reason' => 'Invoice settled']);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/provision/default') && $request['func'] === 'unsuspend');
});

it('audits an operator request even when the upstream and local host are already suspended', function (): void {
    $instance = Instance::factory()->create(['driver_type' => 'zjmf_api', 'upstream_instance_id' => '999', 'status' => InstanceStatus::SUSPENDED]);
    Http::preventStrayRequests();
    Http::fake(['*/zjmf_api_login' => Http::response(['status' => 200, 'jwt' => 'jwt']),
        '*/host/header*' => Http::response(['status' => 200, 'data' => ['host_data' => ['domainstatus' => 'Suspended']]])]);
    $this->artisan('upstream:classic-state', ['instance' => $instance->id, 'operation' => 'suspend', '--reason' => 'Confirm maintenance hold'])->assertSuccessful();
    $this->assertDatabaseHas('audit_logs', ['action' => 'instance.suspend', 'subject_id' => $instance->id, 'reason' => 'Confirm maintenance hold']);
    expect($instance->fresh()->status)->toBe(InstanceStatus::SUSPENDED);
    Http::assertNotSent(fn ($r) => parse_url($r->url(), PHP_URL_PATH) === '/provision/default');
});
