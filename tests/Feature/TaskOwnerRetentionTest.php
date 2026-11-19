<?php

declare(strict_types=1);

use App\Actions\ExecuteFulfillmentTaskAction;
use App\Actions\ProvisionInstanceAction;
use App\Data\InstanceInfoResult;
use App\Data\ProvisionResult;
use App\Drivers\SelfHosted\RedemptionCodeDriver;
use App\Drivers\SelfHosted\Sub2ApiRemoteDriver;
use App\Drivers\Upstream\ZjmfFinanceDriver;
use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\InstanceUpstreamBinding;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\RedemptionCode;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Services\DriverManager;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
});

function retainedOwnerServiceTask(string $action = 'suspend', array $operation = []): FulfillmentTask
{
    $instance = Instance::factory()->active()->create([
        'driver_type' => DriverType::ZJMF_API,
        'upstream_instance_id' => 'retained-owner-host',
    ]);
    $context = [
        'upstream_instance_id' => $instance->upstream_instance_id,
        'upstream_api_id' => $instance->upstream_api_id,
        'token_hash' => hash('sha256', $instance->downstream_token),
        'base_url' => $instance->upstreamApi->base_url,
        'reason' => 'Existing authorized operation',
        'cycle' => 'monthly',
        'due_boundary' => $instance->next_due_at->toIso8601String(),
        'renew_until' => $instance->next_due_at->addMonth()->toIso8601String(),
        'recurring_after' => '10.00',
    ];

    return FulfillmentTask::factory()->create([
        'instance_id' => $instance->id,
        'action' => $action,
        'trigger_source' => 'admin',
        'payload' => ['encrypted_operation' => Crypt::encryptString(json_encode($context, JSON_THROW_ON_ERROR)), ...$operation],
    ]);
}

function retainedOwnerDriver(object $driver): void
{
    $manager = Mockery::mock(DriverManager::class);
    $manager->shouldReceive('fulfillment')->andReturn($driver);
    app()->instance(DriverManager::class, $manager);
}

it('does not claim or call a driver for a stopped original owner', function (string $state): void {
    $task = FulfillmentTask::factory()->create();
    User::whereKey($task->user_id)->update([$state => now()]);
    $before = $task->fresh()->getRawOriginal();
    $manager = Mockery::mock(DriverManager::class);
    $manager->shouldNotReceive('fulfillment');
    app()->instance(DriverManager::class, $manager);

    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);

    expect($task->fresh()->getRawOriginal())->toBe($before)
        ->and($task->fresh()->status)->toBe(FulfillmentTaskStatus::PENDING)
        ->and($task->fresh()->attempts)->toBe(0)
        ->and($task->instance->fresh()->status)->toBe(InstanceStatus::PENDING);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
    $this->assertDatabaseCount('redemption_codes', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
})->with(['deleted_at', 'anonymized_at']);

it('rejects inconsistent original task instance and invoice owners before a claim', function (string $changed): void {
    $task = FulfillmentTask::factory()->create();
    $other = User::factory()->create();
    match ($changed) {
        'task' => $task->update(['user_id' => $other->id]),
        'instance' => $task->instance->update(['user_id' => $other->id]),
        'invoice' => $task->invoice->update(['user_id' => $other->id]),
    };
    $manager = Mockery::mock(DriverManager::class);
    $manager->shouldNotReceive('fulfillment');
    app()->instance(DriverManager::class, $manager);

    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);

    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::STALE)
        ->and($task->fresh()->attempts)->toBe(0)
        ->and($task->fresh()->locked_by)->toBeNull();
    Http::assertNothingSent();
    Queue::assertNothingPushed();
})->with(['task', 'instance', 'invoice']);

it('does not provision a previously claimed task after its original owner stops', function (): void {
    $task = FulfillmentTask::factory()->create([
        'status' => FulfillmentTaskStatus::RUNNING, 'attempts' => 1, 'locked_by' => 'old-worker', 'lease_expires_at' => now()->addMinute(),
    ]);
    User::whereKey($task->user_id)->update(['deleted_at' => now()]);
    $manager = Mockery::mock(DriverManager::class);
    $manager->shouldNotReceive('fulfillment');
    app()->instance(DriverManager::class, $manager);

    $result = app(ProvisionInstanceAction::class)->execute($task->instance, [], $task->id, 'old-worker');

    expect($result->success)->toBeFalse()
        ->and($task->fresh()->attempts)->toBe(1)
        ->and($task->fresh()->status)->toBe(FulfillmentTaskStatus::RUNNING);
    $this->assertDatabaseCount('redemption_codes', 0);
    Http::assertNothingSent();
});

it('finishes an already authorized provision for its original closed identity without notifying a replacement account', function (): void {
    $task = FulfillmentTask::factory()->create();
    $owner = $task->user;
    $originalEmail = $owner->email;
    $code = RedemptionCode::factory()->create(['product_id' => $task->instance->product_id]);
    $driver = Mockery::mock(RedemptionCodeDriver::class)->makePartial();
    $driver->shouldReceive('provision')->once()->andReturnUsing(function (Instance $instance, array $params) use ($owner): ProvisionResult {
        $result = (new RedemptionCodeDriver)->provision($instance, $params);
        $owner->forceFill(['email' => 'closed-'.$owner->id.'@deleted.invalid', 'deleted_at' => now(), 'anonymized_at' => now()])->save();

        return $result;
    });
    retainedOwnerDriver($driver);

    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    $replacement = User::factory()->create(['email' => $originalEmail]);
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);

    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS)
        ->and($task->fresh()->attempts)->toBe(1)
        ->and($task->fresh()->user_id)->toBe($owner->id)
        ->and($task->instance->fresh()->user_id)->toBe($owner->id)
        ->and($task->instance->fresh()->status)->toBe(InstanceStatus::ACTIVE)
        ->and($task->instance->fresh()->encrypted_credentials['card_key'])->toBe($code->code)
        ->and($code->fresh()->status)->toBe('sold')
        ->and(User::withTrashed()->findOrFail($owner->id)->anonymized_at)->not->toBeNull()
        ->and(NotificationEvent::where('user_id', $owner->id)->sole()->suppression_reason)->toBe('account_closed')
        ->and(NotificationDelivery::count())->toBe(0)
        ->and(Instance::where('user_id', $replacement->id)->count())->toBe(0)
        ->and(NotificationEvent::where('user_id', $replacement->id)->count())->toBe(0);
});

it('retains a confirmed upstream host and its binding when the original owner stops during provisioning', function (): void {
    $instance = Instance::factory()->create(['driver_type' => DriverType::ZJMF_API]);
    $task = FulfillmentTask::factory()->create([
        'instance_id' => $instance->id,
        'payload' => ['encrypted_purchase' => Crypt::encryptString(json_encode(['invoice_id' => 1], JSON_THROW_ON_ERROR))],
    ]);
    $driver = Mockery::mock(ZjmfFinanceDriver::class);
    $driver->shouldReceive('endpointIdentity')->once()->andReturn([
        'upstream_api_id' => $instance->upstream_api_id, 'base_url' => $instance->upstreamApi->base_url, 'protocol' => 'classic_zjmf',
    ]);
    $driver->shouldReceive('provision')->once()->andReturnUsing(function () use ($task): ProvisionResult {
        User::whereKey($task->user_id)->update(['deleted_at' => now()]);

        return ProvisionResult::ok(upstreamInstanceId: 'confirmed-old-owner-host', credentials: ['password' => 'old-owner-host-password']);
    });
    retainedOwnerDriver($driver);

    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);

    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS)
        ->and($task->fresh()->attempts)->toBe(1)
        ->and($instance->fresh()->user_id)->toBe($task->user_id)
        ->and($instance->fresh()->upstream_instance_id)->toBe('confirmed-old-owner-host')
        ->and($instance->fresh()->encrypted_credentials['password'])->toBe('old-owner-host-password')
        ->and(InstanceUpstreamBinding::sole()->instance_id)->toBe($instance->id)
        ->and(NotificationEvent::where('user_id', $task->user_id)->sole()->suppression_reason)->toBe('account_closed')
        ->and(NotificationDelivery::count())->toBe(0);
});

it('retains the one remote card result for its original stopped owner without issuing another code', function (): void {
    $account = UpstreamApi::factory()->create(['driver' => DriverType::SUB2API, 'base_url' => 'https://remote-card.test']);
    $instance = Instance::factory()->create(['driver_type' => DriverType::SUB2API, 'upstream_api_id' => $account->id]);
    $task = FulfillmentTask::factory()->create([
        'instance_id' => $instance->id,
        'payload' => ['encrypted_purchase' => Crypt::encryptString(json_encode([
            'sub2api_face_value' => '100.00', 'sub2api_base_url' => $account->base_url,
        ], JSON_THROW_ON_ERROR))],
    ]);
    $driver = Mockery::mock(Sub2ApiRemoteDriver::class);
    $driver->shouldReceive('provision')->once()->andReturnUsing(function () use ($task): ProvisionResult {
        User::whereKey($task->user_id)->update(['deleted_at' => now(), 'anonymized_at' => now()]);

        return ProvisionResult::ok(upstreamInstanceId: '71', credentials: ['card_key' => 'original-owner-only-card', 'face_value' => '100.00']);
    });
    retainedOwnerDriver($driver);

    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);

    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS)
        ->and($task->fresh()->attempts)->toBe(1)
        ->and($task->fresh()->payload['remote_delivery']['state'])->toBe('delivered')
        ->and($task->fresh()->payload['remote_delivery']['encrypted_result'])->toBeString()
        ->and($instance->fresh()->user_id)->toBe($task->user_id)
        ->and($instance->fresh()->upstream_instance_id)->toBe('71')
        ->and($instance->fresh()->encrypted_credentials['card_key'])->toBe('original-owner-only-card')
        ->and(RedemptionCode::sole()->status)->toBe('sold')
        ->and(RedemptionCode::sole()->instance_id)->toBe($instance->id)
        ->and(NotificationEvent::where('user_id', $task->user_id)->sole()->suppression_reason)->toBe('account_closed')
        ->and(NotificationDelivery::count())->toBe(0);
});

it('never applies a provision result to an instance transferred away from its original owner', function (string $kind): void {
    $account = $kind === 'local' ? null : UpstreamApi::factory()->create([
        'driver' => $kind === 'remote' ? DriverType::SUB2API : DriverType::ZJMF_API,
    ]);
    $instance = Instance::factory()->create([
        'driver_type' => match ($kind) {
            'remote' => DriverType::SUB2API,
            'upstream' => DriverType::ZJMF_API,
            default => DriverType::REDEMPTION,
        },
        'upstream_api_id' => $account?->id,
    ]);
    $task = FulfillmentTask::factory()->create([
        'instance_id' => $instance->id,
        'payload' => ['encrypted_purchase' => Crypt::encryptString(json_encode([
            'sub2api_face_value' => '100.00', 'sub2api_base_url' => $account?->base_url,
        ], JSON_THROW_ON_ERROR))],
    ]);
    $other = User::factory()->create();
    $code = $kind === 'local' ? RedemptionCode::factory()->create(['product_id' => $instance->product_id]) : null;
    $driver = Mockery::mock(match ($kind) {
        'remote' => Sub2ApiRemoteDriver::class,
        'upstream' => ZjmfFinanceDriver::class,
        default => RedemptionCodeDriver::class,
    });
    if ($kind === 'upstream') {
        $driver->shouldReceive('endpointIdentity')->once()->andReturn([
            'upstream_api_id' => $account->id, 'base_url' => $account->base_url, 'protocol' => 'classic_zjmf',
        ]);
    }
    $driver->shouldReceive('provision')->once()->andReturnUsing(function () use ($task, $instance, $other, $code): ProvisionResult {
        User::whereKey($task->user_id)->update(['deleted_at' => now()]);
        Instance::whereKey($instance->id)->update(['user_id' => $other->id]);

        return ProvisionResult::ok(upstreamInstanceId: 'original-owner-result', credentials: ['card_key' => $code?->code ?? 'original-owner-card']);
    });
    retainedOwnerDriver($driver);

    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);

    expect($task->fresh()->status)->not->toBe(FulfillmentTaskStatus::SUCCESS)
        ->and($task->fresh()->user_id)->not->toBe($other->id)
        ->and($instance->fresh()->encrypted_credentials)->toBeNull()
        ->and($instance->fresh()->status)->toBe(InstanceStatus::PENDING)
        ->and(InstanceUpstreamBinding::count())->toBe(0)
        ->and(NotificationDelivery::count())->toBe(0);
    if ($kind === 'remote') {
        expect($task->fresh()->payload['remote_delivery']['encrypted_result'])->toBeString();
        $this->assertDatabaseCount('redemption_codes', 0);
    }
    if ($code !== null) {
        expect($code->fresh()->status)->toBe('reserved');
    }
})->with(['local', 'upstream', 'remote']);

it('commits the confirmed service result when the owner stops during its external request', function (string $state): void {
    $task = retainedOwnerServiceTask();
    $ownerId = $task->user_id;
    $driver = Mockery::mock(ZjmfFinanceDriver::class);
    $driver->shouldReceive('syncInfo')->twice()->andReturn(new InstanceInfoResult('Active'), new InstanceInfoResult('Suspended'));
    $driver->shouldReceive('suspend')->once()->andReturnUsing(function () use ($ownerId, $state): ProvisionResult {
        User::whereKey($ownerId)->update([$state => now()]);

        return ProvisionResult::ok();
    });
    retainedOwnerDriver($driver);

    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);

    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS)
        ->and($task->fresh()->attempts)->toBe(1)
        ->and($task->fresh()->user_id)->toBe($ownerId)
        ->and($task->instance->fresh()->status)->toBe(InstanceStatus::SUSPENDED)
        ->and($task->instance->fresh()->user_id)->toBe($ownerId)
        ->and($task->fresh()->payload['operation']['state'])->toBe('delivered');
    $this->assertDatabaseHas('audit_logs', ['subject_id' => $task->instance_id, 'action' => 'instance.suspend.confirmed']);
})->with(['deleted_at', 'anonymized_at']);

it('does not start a resource control after the owner stops during its preliminary state read', function (): void {
    $task = retainedOwnerServiceTask();
    $driver = Mockery::mock(ZjmfFinanceDriver::class);
    $driver->shouldReceive('syncInfo')->once()->andReturnUsing(function () use ($task): InstanceInfoResult {
        User::whereKey($task->user_id)->update(['deleted_at' => now()]);

        return new InstanceInfoResult('Active');
    });
    $driver->shouldNotReceive('suspend');
    retainedOwnerDriver($driver);

    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);

    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::FAILED)
        ->and($task->fresh()->attempts)->toBe(1)
        ->and($task->fresh()->payload)->not->toHaveKey('operation')
        ->and($task->instance->fresh()->status)->toBe(InstanceStatus::ACTIVE);
});

it('does not pay an existing upstream operation after its owner stops during cost verification', function (): void {
    $task = retainedOwnerServiceTask('renew', ['operation' => ['state' => 'invoice_created', 'invoice_id' => 'old-operation']]);
    $task->invoice->update(['upstream_expected_cost' => '8.00']);
    $driver = Mockery::mock(ZjmfFinanceDriver::class);
    $driver->shouldReceive('operationBill')->once()->andReturnUsing(function () use ($task): array {
        User::whereKey($task->user_id)->update(['anonymized_at' => now()]);

        return ['status' => 200, 'data' => ['currency' => ['code' => 'CNY'], 'total' => '8.00']];
    });
    $driver->shouldNotReceive('payOperation');
    $driver->shouldNotReceive('renew');
    retainedOwnerDriver($driver);

    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);

    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::FAILED)
        ->and($task->fresh()->payload['operation'])->toBe(['state' => 'invoice_created', 'invoice_id' => 'old-operation'])
        ->and($task->invoice->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and($task->instance->fresh()->status)->toBe(InstanceStatus::ACTIVE);
});

it('still rejects a closed owner service result after its lease payment or binding changes', function (string $change): void {
    $task = retainedOwnerServiceTask();
    $driver = Mockery::mock(ZjmfFinanceDriver::class);
    $driver->shouldReceive('syncInfo')->twice()->andReturn(new InstanceInfoResult('Active'), new InstanceInfoResult('Suspended'));
    $driver->shouldReceive('suspend')->once()->andReturnUsing(function () use ($task, $change): ProvisionResult {
        User::whereKey($task->user_id)->update(['deleted_at' => now()]);
        match ($change) {
            'lease' => $task->update(['locked_by' => 'replacement-worker']),
            'payment' => $task->invoice->update(['status' => InvoiceStatus::REFUNDED]),
            'binding' => $task->instance->update(['upstream_instance_id' => 'different-host']),
            'terminal' => $task->instance->update(['status' => InstanceStatus::DELETED]),
        };

        return ProvisionResult::ok();
    });
    retainedOwnerDriver($driver);

    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);

    expect($task->fresh()->status)->not->toBe(FulfillmentTaskStatus::SUCCESS)
        ->and($task->fresh()->payload['operation']['state'])->toBe('control_accepted')
        ->and($task->instance->fresh()->status)->toBe($change === 'terminal' ? InstanceStatus::DELETED : InstanceStatus::ACTIVE);
    $this->assertDatabaseMissing('audit_logs', ['subject_id' => $task->instance_id, 'action' => 'instance.suspend.confirmed']);
})->with(['lease', 'payment', 'binding', 'terminal']);
