<?php

declare(strict_types=1);

use App\Actions\DispatchStalePendingTasksAction;
use App\Actions\ExecuteFulfillmentTaskAction;
use App\Actions\ReapRedemptionReservationsAction;
use App\Actions\RetryFulfillmentTaskAction;
use App\Contracts\FulfillmentDriver;
use App\Drivers\SelfHosted\RedemptionCodeDriver;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Jobs\ExecuteFulfillmentTaskJob;
use App\Models\FulfillmentTask;
use App\Models\RedemptionCode;
use App\Services\DriverManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

it('recovers stale pending tasks via the outbox sweeper', function (): void {
    Queue::fake();
    $task = FulfillmentTask::factory()->create([
        'status' => 'pending',
        'created_at' => now()->subMinutes(2),
    ]);

    app(DispatchStalePendingTasksAction::class)->execute();
    Queue::assertPushed(ExecuteFulfillmentTaskJob::class, fn ($job) => $job->taskId === $task->id);
});

it('keeps live task leases and future backoff untouched', function (): void {
    Queue::fake();
    $running = FulfillmentTask::factory()->create(['status' => 'running', 'locked_by' => 'worker', 'lease_expires_at' => now()->addMinute()]);
    $delayed = FulfillmentTask::factory()->create(['created_at' => now()->subMinutes(5), 'available_at' => now()->addMinute()]);
    app(DispatchStalePendingTasksAction::class)->execute();
    expect($running->fresh()->status)->toBe(FulfillmentTaskStatus::RUNNING)
        ->and($delayed->fresh()->attempts)->toBe(0);
    Queue::assertNothingPushed();
});

it('releases only expired undelivered reservations and never reclaims a sold card', function (): void {
    $task = FulfillmentTask::factory()->create();
    $instance = $task->instance;
    $expired = RedemptionCode::factory()->create(['product_id' => $instance->product_id, 'instance_id' => $instance->id, 'status' => 'reserved', 'reserved_until' => now()->subMinute()]);
    $sold = RedemptionCode::factory()->create(['product_id' => $instance->product_id, 'status' => 'sold', 'sold_at' => now(), 'reserved_until' => now()->subMinute()]);
    app(ReapRedemptionReservationsAction::class)->execute();
    expect($expired->fresh()->status)->toBe('available')->and($expired->fresh()->instance_id)->toBeNull()
        ->and($sold->fresh()->status)->toBe('sold');
});

it('records a failed empty-stock task and permits an audited operator retry after restocking', function (): void {
    Queue::fake();
    $task = FulfillmentTask::factory()->create(['max_attempts' => 1]);
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::FAILED)
        ->and($task->instance->fresh()->status)->toBe(InstanceStatus::PROVISION_FAILED);
    RedemptionCode::factory()->create(['product_id' => $task->instance->product_id]);
    app(RetryFulfillmentTaskAction::class)->execute($task, 'Stock replenished by operator.');
    $this->assertDatabaseHas('audit_logs', ['action' => 'fulfillment.retry', 'reason' => 'Stock replenished by operator.']);
    Queue::assertPushed(ExecuteFulfillmentTaskJob::class);
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS)
        ->and($task->instance->fresh()->status)->toBe(InstanceStatus::ACTIVE);
});

it('reclaims a running task whose lease expired', function (): void {
    Queue::fake();
    $task = FulfillmentTask::factory()->create([
        'status' => 'running',
        'locked_by' => 'worker-1',
        'locked_at' => now()->subMinutes(10),
        'lease_expires_at' => now()->subMinutes(5),
    ]);

    app(DispatchStalePendingTasksAction::class)->execute();

    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::PENDING)
        ->and($task->fresh()->attempts)->toBe(1);
    Queue::assertPushed(ExecuteFulfillmentTaskJob::class);
});

it('fails a task permanently once max_attempts is exhausted', function (): void {
    $task = FulfillmentTask::factory()->create([
        'status' => 'running',
        'attempts' => 5,
        'max_attempts' => 5,
        'lease_expires_at' => now()->subMinute(),
    ]);

    app(DispatchStalePendingTasksAction::class)->execute();

    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::FAILED);
});

it('discards credentials returned by a worker after it loses the task lease', function (): void {
    $task = FulfillmentTask::factory()->create();
    $code = RedemptionCode::factory()->create(['product_id' => $task->instance->product_id]);
    $real = new RedemptionCodeDriver;
    $driver = Mockery::mock(FulfillmentDriver::class);
    $driver->shouldReceive('provision')->once()->andReturnUsing(function ($instance, $params) use ($real, $task) {
        $result = $real->provision($instance, $params);
        DB::table('fulfillment_tasks')->where('id', $task->id)->update(['locked_by' => 'replacement-worker']);

        return $result;
    });
    $manager = Mockery::mock(DriverManager::class);
    $manager->shouldReceive('fulfillment')->andReturn($driver);
    $this->app->instance(DriverManager::class, $manager);
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($code->fresh()->status)->toBe('reserved')
        ->and($task->instance->fresh()->encrypted_credentials)->toBeNull()
        ->and($task->fresh()->locked_by)->toBe('replacement-worker');
});
