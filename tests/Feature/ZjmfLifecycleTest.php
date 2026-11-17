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
