<?php

declare(strict_types=1);

use App\Actions\DispatchStalePendingTasksAction;
use App\Actions\ExecuteFulfillmentTaskAction;
use App\Contracts\FulfillmentDriver;
use App\Data\ProvisionResult;
use App\Drivers\SelfHosted\RedemptionCodeDriver;
use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus as Status;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Jobs\ExecuteFulfillmentTaskJob;
use App\Jobs\SendNotificationDeliveryJob;
use App\Models\FulfillmentTask;
use App\Models\RedemptionCode;
use App\Services\DriverManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    $this->travelTo(now()->startOfSecond());
});

function claimBudgetDriver(FulfillmentTask $task, Closure $provision, int $calls): void
{
    RedemptionCode::factory()->create(['product_id' => $task->instance->product_id]);
    $driver = Mockery::mock(FulfillmentDriver::class);
    $driver->shouldReceive('provision')->times($calls)->andReturnUsing($provision);
    $manager = Mockery::mock(DriverManager::class);
    $manager->shouldReceive('fulfillment')->andReturn($driver);
    app()->instance(DriverManager::class, $manager);
}

it('executes a never-run purchase after five scheduler-first recovery ticks without spending its claim budget', function (): void {
    $task = FulfillmentTask::factory()->create(['created_at' => now()->subMinutes(2), 'available_at' => now()->subMinute()]);
    $due = $task->available_at->toDateTimeString();
    $balance = $task->user->getRawOriginal('credit');
    RedemptionCode::factory()->create(['product_id' => $task->instance->product_id]);
    for ($tick = 0; $tick < 5; $tick++) {
        app(DispatchStalePendingTasksAction::class)->execute();
        expect($task->fresh()->attempts)->toBe(0)->and($task->fresh()->available_at->toDateTimeString())->toBe($due)
            ->and($task->fresh()->status)->toBe(Status::PENDING);
        $this->travel(61)->seconds();
    }
    Queue::assertPushed(ExecuteFulfillmentTaskJob::class, 5);
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->status)->toBe(Status::SUCCESS)->and($task->fresh()->attempts)->toBe(1)
        ->and($task->instance->fresh()->status)->toBe(InstanceStatus::ACTIVE)
        ->and($task->user->fresh()->getRawOriginal('credit'))->toBe($balance);
    $this->assertDatabaseCount('fulfillment_tasks', 1);
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseHas('redemption_codes', ['instance_id' => $task->instance_id, 'status' => 'sold']);
});

it('deduplicates recovery delivery briefly while database claims survive cache loss', function (): void {
    $task = FulfillmentTask::factory()->create(['created_at' => now()->subMinutes(2), 'available_at' => null]);
    RedemptionCode::factory()->create(['product_id' => $task->instance->product_id]);
    for ($i = 0; $i < 3; $i++) {
        app(DispatchStalePendingTasksAction::class)->execute();
    }
    Queue::assertPushed(ExecuteFulfillmentTaskJob::class, 1);
    Cache::flush();
    app(DispatchStalePendingTasksAction::class)->execute();
    Queue::assertPushed(ExecuteFulfillmentTaskJob::class, 2);
    expect($task->fresh()->attempts)->toBe(0)->and($task->fresh()->available_at)->toBeNull();
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->attempts)->toBe(1)->and($task->fresh()->status)->toBe(Status::SUCCESS);
});

it('persists a failed recovery delivery without consuming budget and permits immediate redelivery', function (): void {
    $task = FulfillmentTask::factory()->create(['created_at' => now()->subMinutes(2)]);
    $before = $task->fresh()->getRawOriginal();
    $bus = Bus::getFacadeRoot();
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable.'));
    expect(fn () => app(DispatchStalePendingTasksAction::class)->execute())->toThrow(RuntimeException::class, 'Queue unavailable.');
    expect($task->fresh()->getRawOriginal())->toBe($before);
    Bus::swap($bus);
    app(DispatchStalePendingTasksAction::class)->execute();
    Queue::assertPushed(ExecuteFulfillmentTaskJob::class, 1);
});

it('counts each retryable execution once and allows exactly five claims', function (): void {
    $task = FulfillmentTask::factory()->create();
    $level = DB::transactionLevel();
    $observed = [];
    claimBudgetDriver($task, function () use ($task, $level, &$observed): ProvisionResult {
        $current = $task->fresh();
        expect(DB::transactionLevel())->toBe($level)->and($current->status)->toBe(Status::RUNNING)
            ->and($current->locked_by)->not->toBeNull()->and($current->lease_expires_at->isFuture())->toBeTrue();
        $observed[] = $current->attempts;

        return ProvisionResult::fail('Temporary supplier failure.', retryable: true);
    }, 5);
    foreach ([60, 300, 900, 900, 900] as $index => $backoff) {
        app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
        $current = $task->fresh();
        expect($current->attempts)->toBe($index + 1)->and($current->locked_by)->toBeNull()
            ->and($current->available_at->eq(now()->addSeconds($backoff)))->toBeTrue()
            ->and($current->status)->toBe($index === 4 ? Status::FAILED : Status::PENDING);
        if ($index < 4) {
            Queue::assertPushed(ExecuteFulfillmentTaskJob::class, fn ($job) => $job->taskId === $task->id && $job->delay->eq($current->available_at));
            app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
            expect($task->fresh()->attempts)->toBe($index + 1);
        }
        $this->travel($backoff)->seconds();
    }
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    app(DispatchStalePendingTasksAction::class)->execute();
    expect($observed)->toBe([1, 2, 3, 4, 5])->and($task->fresh()->attempts)->toBe(5)
        ->and($task->instance->fresh()->status)->toBe(InstanceStatus::PROVISION_FAILED);
    Queue::assertPushed(ExecuteFulfillmentTaskJob::class, 4);
    $this->assertDatabaseMissing('redemption_codes', ['status' => 'sold']);
});

it('permits success on the last allowed claim and never claims it again', function (): void {
    $task = FulfillmentTask::factory()->create(['attempts' => 4]);
    RedemptionCode::factory()->create(['product_id' => $task->instance->product_id]);
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->status)->toBe(Status::SUCCESS)->and($task->fresh()->attempts)->toBe(5)
        ->and($task->instance->fresh()->status)->toBe(InstanceStatus::ACTIVE);
    Queue::assertNotPushed(ExecuteFulfillmentTaskJob::class);
    Queue::assertPushed(SendNotificationDeliveryJob::class, 1);
});

it('dispatches a retry only after the enclosing transaction commits', function (): void {
    $task = FulfillmentTask::factory()->create();
    claimBudgetDriver($task, fn () => ProvisionResult::fail('Retry after commit.', retryable: true), 1);
    DB::transaction(function () use ($task): void {
        app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
        expect($task->fresh()->attempts)->toBe(1)->and($task->fresh()->status)->toBe(Status::PENDING);
        Queue::assertNothingPushed();
    });
    Queue::assertPushed(ExecuteFulfillmentTaskJob::class, fn ($job) => $job->taskId === $task->id && $job->delay->eq(now()->addMinute()));
});

it('does not leave a dispatch marker or send a job when lease recovery rolls back', function (): void {
    $task = FulfillmentTask::factory()->create(['status' => Status::RUNNING, 'attempts' => 1,
        'locked_by' => 'lost-worker', 'lease_expires_at' => now()->subSecond()]);
    $before = $task->fresh()->getRawOriginal();
    expect(fn () => DB::transaction(function (): void {
        app(DispatchStalePendingTasksAction::class)->execute();
        Queue::assertNothingPushed();
        throw new RuntimeException('Roll back recovery.');
    }))->toThrow(RuntimeException::class, 'Roll back recovery.');
    expect($task->fresh()->getRawOriginal())->toBe($before);
    Queue::assertNothingPushed();
    app(DispatchStalePendingTasksAction::class)->execute();
    Queue::assertPushed(ExecuteFulfillmentTaskJob::class, 1);
    expect($task->fresh()->attempts)->toBe(1)->and($task->fresh()->status)->toBe(Status::PENDING);
});

it('recovers a retry delivery failure from its committed pending state', function (): void {
    $task = FulfillmentTask::factory()->create(['created_at' => now()->subMinutes(2)]);
    claimBudgetDriver($task, fn () => ProvisionResult::fail('Retryable result.', retryable: true), 1);
    $bus = Bus::getFacadeRoot();
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue failed after commit.'));
    expect(fn () => app(ExecuteFulfillmentTaskAction::class)->execute($task->id))->toThrow(RuntimeException::class, 'Queue failed after commit.');
    expect($task->fresh()->attempts)->toBe(1)->and($task->fresh()->status)->toBe(Status::PENDING)
        ->and($task->fresh()->available_at->eq(now()->addMinute()))->toBeTrue();
    Bus::swap($bus);
    $this->travel(60)->seconds();
    app(DispatchStalePendingTasksAction::class)->execute();
    Queue::assertPushed(ExecuteFulfillmentTaskJob::class, 1);
    expect($task->fresh()->attempts)->toBe(1)->and($task->fresh()->available_at->eq(now()))->toBeTrue();
});

it('does not claim an already exhausted pending task', function (): void {
    $task = FulfillmentTask::factory()->create(['attempts' => 5]);
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->attempts)->toBe(5)->and($task->fresh()->status)->toBe(Status::FAILED);
    Queue::assertNothingPushed();
});

it('stops a permanent failure after its first consumed claim', function (): void {
    $task = FulfillmentTask::factory()->create();
    claimBudgetDriver($task, fn () => ProvisionResult::fail('Invalid supplier request.'), 1);
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->status)->toBe(Status::FAILED)->and($task->fresh()->attempts)->toBe(1);
    Queue::assertNothingPushed();
});

it('retains a crashed claim and recovers only after lease expiry and backoff', function (): void {
    $task = FulfillmentTask::factory()->create(['created_at' => now()->subMinutes(2)]);
    $calls = 0;
    claimBudgetDriver($task, function ($instance, $params) use (&$calls): ProvisionResult {
        if (++$calls === 1) {
            throw new RuntimeException('Worker process interrupted.');
        }

        return (new RedemptionCodeDriver)->provision($instance, $params);
    }, 2);
    expect(fn () => app(ExecuteFulfillmentTaskAction::class)->execute($task->id))->toThrow(RuntimeException::class, 'Worker process interrupted.');
    $running = $task->fresh()->getRawOriginal();
    app(DispatchStalePendingTasksAction::class)->execute();
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->getRawOriginal())->toBe($running)->and($calls)->toBe(1);
    $this->travel(211)->seconds();
    app(DispatchStalePendingTasksAction::class)->execute();
    expect($task->fresh()->attempts)->toBe(1)->and($task->fresh()->status)->toBe(Status::PENDING)
        ->and($task->fresh()->locked_by)->toBeNull()->and($task->fresh()->lease_expires_at)->toBeNull()
        ->and($task->fresh()->available_at->eq(now()->addMinute()))->toBeTrue();
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($calls)->toBe(1);
    $this->travel(60)->seconds();
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->attempts)->toBe(2)->and($task->fresh()->status)->toBe(Status::SUCCESS);
    $this->assertDatabaseCount('redemption_codes', 1);
});

it('fences a late driver result after sweeper recovery without consuming another attempt', function (): void {
    $task = FulfillmentTask::factory()->create();
    claimBudgetDriver($task, function ($instance, $params): ProvisionResult {
        $result = (new RedemptionCodeDriver)->provision($instance, $params);
        $this->travel(211)->seconds();
        app(DispatchStalePendingTasksAction::class)->execute();

        return $result;
    }, 1);
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->attempts)->toBe(1)->and($task->fresh()->status)->toBe(Status::PENDING)
        ->and($task->instance->fresh()->encrypted_credentials)->toBeNull()
        ->and($task->instance->fresh()->status)->toBe(InstanceStatus::PENDING);
    $this->assertDatabaseHas('redemption_codes', ['instance_id' => $task->instance_id, 'status' => 'reserved']);
});

it('exhausts a crashed final claim without scheduling a sixth', function (): void {
    $task = FulfillmentTask::factory()->create(['attempts' => 4]);
    claimBudgetDriver($task, function (): never {
        throw new RuntimeException('Last claim interrupted.');
    }, 1);
    expect(fn () => app(ExecuteFulfillmentTaskAction::class)->execute($task->id))->toThrow(RuntimeException::class, 'Last claim interrupted.');
    expect($task->fresh()->attempts)->toBe(5);
    $this->travel(211)->seconds();
    app(DispatchStalePendingTasksAction::class)->execute();
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->attempts)->toBe(5)->and($task->fresh()->status)->toBe(Status::FAILED)
        ->and($task->fresh()->locked_by)->toBeNull()->and($task->fresh()->lease_expires_at)->toBeNull();
    Queue::assertNothingPushed();
});

it('preserves the consumed count when reclaiming each expired lease', function (int $attempts, int $delay): void {
    $task = FulfillmentTask::factory()->create(['status' => Status::RUNNING, 'attempts' => $attempts,
        'locked_by' => 'lost-worker', 'lease_expires_at' => now()->subSecond()]);
    app(DispatchStalePendingTasksAction::class)->execute();
    expect($task->fresh()->attempts)->toBe($attempts)->and($task->fresh()->status)->toBe(Status::PENDING)
        ->and($task->fresh()->available_at->eq(now()->addSeconds($delay)))->toBeTrue();
})->with([[1, 60], [2, 300], [3, 900], [4, 900]]);

it('discards unpaid or refunded tasks before recovery or execution', function (InvoiceStatus $status, bool $sweep): void {
    $task = FulfillmentTask::factory()->create(['created_at' => now()->subMinutes(2)]);
    $task->invoice->update(['status' => $status]);
    RedemptionCode::factory()->create(['product_id' => $task->instance->product_id]);
    if ($sweep) {
        app(DispatchStalePendingTasksAction::class)->execute();
    }
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->attempts)->toBe(0)->and($task->fresh()->status)->toBe(Status::STALE)
        ->and($task->instance->fresh()->encrypted_credentials)->toBeNull();
    Queue::assertNothingPushed();
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseHas('redemption_codes', ['status' => 'available', 'instance_id' => null]);
})->with([InvoiceStatus::UNPAID, InvoiceStatus::EXPIRED, InvoiceStatus::CANCELLED, InvoiceStatus::REFUNDED])->with([false, true]);

it('does not consume a claim for future work or a changed binding', function (bool $changed): void {
    $task = FulfillmentTask::factory()->create(['created_at' => now()->subMinutes(2),
        'available_at' => $changed ? now() : now()->addMinutes(10)]);
    if ($changed) {
        $task->instance->update(['driver_type' => DriverType::SUB2API]);
    }
    $due = $task->available_at->toDateTimeString();
    app(DispatchStalePendingTasksAction::class)->execute();
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->attempts)->toBe(0)->and($task->fresh()->status)->toBe($changed ? Status::STALE : Status::PENDING)
        ->and($task->fresh()->available_at->toDateTimeString())->toBe($due);
    Queue::assertNothingPushed();
})->with([false, true]);
