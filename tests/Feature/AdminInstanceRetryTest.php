<?php

declare(strict_types=1);

use App\Actions\RetryFulfillmentTaskAction;
use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Jobs\ExecuteFulfillmentTaskJob;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\UpstreamApi;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Queue::fake();
    Http::preventStrayRequests();
});

afterEach(function (): void {
    Http::assertNothingSent();
});

function c4RetryActor(bool $classic = false, ?array $permissions = null): User
{
    $actor = User::factory()->create();
    foreach ($permissions ?? ($classic ? ['upstream.view', 'upstream.lifecycle'] : ['inventory.view', 'inventory.retry']) as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $actor;
}

function c4RetryTask(bool $classic = false): FulfillmentTask
{
    $instance = Instance::factory()->create(['status' => InstanceStatus::PROVISION_FAILED,
        'driver_type' => $classic ? DriverType::ZJMF_API : DriverType::REDEMPTION,
        'upstream_api_id' => $classic ? UpstreamApi::factory()->create()->id : null,
        'upstream_instance_id' => $classic ? '432' : null]);

    return FulfillmentTask::factory()->create(['instance_id' => $instance->id, 'status' => FulfillmentTaskStatus::FAILED,
        'attempts' => 5, 'locked_by' => 'old-worker', 'locked_at' => now()->subHour(),
        'lease_expires_at' => now()->subMinutes(50), 'error_message' => 'Original failure',
        'payload' => ['params' => ['token' => 'PRIVATE-C4-RETRY-PAYLOAD']]])->fresh();
}

it('retries the same paid local task with the original owner payload and real administrator audit', function (): void {
    $actor = c4RetryActor();
    $task = c4RetryTask();
    $owner = User::findOrFail($task->user_id);
    $owner->forceFill(['credit' => '12.34', 'disabled_at' => now()])->save();
    $payload = $task->getRawOriginal('payload');
    $invoice = $task->invoice->getRawOriginal();

    app(RetryFulfillmentTaskAction::class)->execute($task, '库存已补齐', $actor);

    $current = $task->fresh();
    expect($current->status)->toBe(FulfillmentTaskStatus::PENDING)
        ->and($current->attempts)->toBe(0)->and($current->locked_by)->toBeNull()
        ->and($current->locked_at)->toBeNull()->and($current->lease_expires_at)->toBeNull()
        ->and($current->error_message)->toBeNull()->and($current->getRawOriginal('payload'))->toBe($payload)
        ->and($current->user_id)->toBe($owner->id)
        ->and($task->instance->fresh()->status)->toBe(InstanceStatus::PENDING)
        ->and($task->invoice->fresh()->getRawOriginal())->toBe($invoice)
        ->and($owner->fresh()->credit)->toBe('12.34');
    $audit = DB::table('audit_logs')->where('action', 'fulfillment.retry')->sole();
    expect($audit->actor_id)->toBe($actor->id)->and($audit->actor_type)->toBe('user')
        ->and($audit->request_id)->not->toBeNull()
        ->and($audit->after)->not->toContain('PRIVATE-C4-RETRY-PAYLOAD');
    Queue::assertPushed(ExecuteFulfillmentTaskJob::class, fn ($job) => $job->taskId === $task->id);
    $this->assertDatabaseCount('fulfillment_tasks', 1);
    $this->assertDatabaseCount('financial_transactions', 0);
    expect(fn () => app(RetryFulfillmentTaskAction::class)->execute($task, 'Repeated click', $actor))->toThrow(DomainException::class);
    Queue::assertPushed(ExecuteFulfillmentTaskJob::class, 1);
    $this->assertDatabaseCount('audit_logs', 1);
});

it('keeps classic paid retries and invoice-free lifecycle retries in their established scope', function (bool $lifecycle): void {
    $actor = c4RetryActor(true);
    $task = c4RetryTask(true);
    if ($lifecycle) {
        $task->update(['invoice_id' => null, 'action' => 'suspend']);
        $task->instance->update(['status' => InstanceStatus::ACTIVE]);
    }
    $payload = ['classic_order' => ['status' => 'unknown', 'idempotency_key' => 'original-do-not-reissue']];
    $task->update(['payload' => $payload]);
    app(RetryFulfillmentTaskAction::class)->execute($task, '核对原任务', $actor);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::PENDING)
        ->and($task->fresh()->payload)->toBe($payload)
        ->and($task->fresh()->invoice_id)->toBe($lifecycle ? null : $task->invoice_id);
    Queue::assertPushed(ExecuteFulfillmentTaskJob::class, fn ($job) => $job->taskId === $task->id);
    $this->assertDatabaseCount('fulfillment_tasks', 1);
    $this->assertDatabaseHas('audit_logs', ['action' => 'fulfillment.retry', 'actor_id' => $actor->id]);
})->with([false, true]);

it('requires current domain permissions from the locked actual actor', function (bool $classic, array $permissions): void {
    $actor = c4RetryActor($classic, $permissions);
    $task = c4RetryTask($classic);
    expect(fn () => app(RetryFulfillmentTaskAction::class)->execute($task, 'Requested retry', $actor))->toThrow(AuthorizationException::class);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::FAILED);
    Queue::assertNothingPushed();
    $this->assertDatabaseCount('audit_logs', 0);
})->with([
    [false, ['inventory.view']], [false, ['inventory.retry']], [false, ['upstream.view', 'upstream.lifecycle']],
    [true, ['upstream.view']], [true, ['upstream.lifecycle']], [true, ['inventory.view', 'inventory.retry']],
]);

it('rejects revoked or closed actors supplied as stale model objects', function (string $change): void {
    $actor = c4RetryActor();
    $task = c4RetryTask();
    expect($actor->can('inventory.retry'))->toBeTrue();
    match ($change) {
        'revoked' => $actor->fresh()->revokePermissionTo('inventory.retry'),
        'disabled' => User::whereKey($actor->id)->update(['disabled_at' => now()]),
        'deleted' => User::whereKey($actor->id)->delete(),
        'anonymized' => User::whereKey($actor->id)->update(['anonymized_at' => now()]),
    };
    expect(fn () => app(RetryFulfillmentTaskAction::class)->execute($task, 'Requested retry', $actor))
        ->toThrow($change === 'revoked' ? AuthorizationException::class : HttpException::class);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::FAILED);
    Queue::assertNothingPushed();
})->with(['revoked', 'disabled', 'deleted', 'anonymized']);

it('does not retry closed owners or inconsistent financial ownership', function (string $change): void {
    $actor = c4RetryActor();
    $task = c4RetryTask();
    $otherOwner = User::factory()->create();
    $otherInstance = Instance::factory()->create(['user_id' => $task->user_id]);
    match ($change) {
        'closed-owner' => User::whereKey($task->user_id)->delete(),
        'anonymized-owner' => User::whereKey($task->user_id)->update(['anonymized_at' => now()]),
        'task-owner' => $task->update(['user_id' => $otherOwner->id]),
        'instance-owner' => $task->instance->update(['user_id' => $otherOwner->id]),
        'invoice-owner' => $task->invoice->update(['user_id' => $otherOwner->id]),
        'invoice-instance' => $task->invoice->update(['instance_id' => $otherInstance->id]),
    };
    expect(fn () => app(RetryFulfillmentTaskAction::class)->execute($task, 'Requested retry', $actor))->toThrow(DomainException::class);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::FAILED)
        ->and($task->fresh()->attempts)->toBe(5);
    Queue::assertNothingPushed();
    $this->assertDatabaseCount('audit_logs', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
})->with(['closed-owner', 'anonymized-owner', 'task-owner', 'instance-owner', 'invoice-owner', 'invoice-instance']);

it('retains existing payment delivery binding and state restrictions', function (string $change): void {
    $actor = c4RetryActor();
    $task = c4RetryTask();
    match ($change) {
        'refunded' => $task->invoice->update(['status' => InvoiceStatus::REFUNDED]),
        'unpaid' => $task->invoice->update(['status' => InvoiceStatus::UNPAID]),
        'missing-invoice' => $task->update(['invoice_id' => null]),
        'running' => $task->update(['status' => FulfillmentTaskStatus::RUNNING]),
        'success' => $task->update(['status' => FulfillmentTaskStatus::SUCCESS]),
        'stale' => $task->update(['status' => FulfillmentTaskStatus::STALE]),
        'terminal' => $task->instance->update(['status' => InstanceStatus::DELETED]),
        'active' => $task->instance->update(['status' => InstanceStatus::ACTIVE]),
        'delivered' => $task->instance->update(['encrypted_credentials' => ['card_key' => 'ALREADY-ISSUED']]),
        'binding' => $task->instance->update(['driver_type' => DriverType::SUB2API]),
        'action' => $task->update(['action' => 'renew']),
    };
    $before = $task->fresh()->getRawOriginal();
    expect(fn () => app(RetryFulfillmentTaskAction::class)->execute($task, 'Requested retry', $actor))->toThrow(DomainException::class);
    expect($task->fresh()->getRawOriginal())->toBe($before);
    Queue::assertNothingPushed();
    $this->assertDatabaseCount('audit_logs', 0);
})->with(['refunded', 'unpaid', 'missing-invoice', 'running', 'success', 'stale', 'terminal', 'active', 'delivered', 'binding', 'action']);

it('never permits generic web retry of a remote card or rewrites its unknown delivery evidence', function (): void {
    $actor = c4RetryActor();
    $task = c4RetryTask();
    $account = UpstreamApi::factory()->create(['driver' => DriverType::SUB2API]);
    $task->instance->update(['driver_type' => DriverType::SUB2API, 'upstream_api_id' => $account->id]);
    $task->update(['driver_type' => DriverType::SUB2API, 'upstream_api_id' => $account->id,
        'payload' => ['remote_delivery' => ['status' => 'unknown', 'request_id' => 'keep-existing-remote-intent']]]);
    $before = $task->getRawOriginal();
    expect(fn () => app(RetryFulfillmentTaskAction::class)->execute($task, 'Requested retry', $actor))->toThrow(DomainException::class);
    expect($task->fresh()->getRawOriginal())->toBe($before);
    Queue::assertNothingPushed();
    $this->assertDatabaseCount('audit_logs', 0);
});

it('keeps trusted CLI recovery and system audit but still enforces original owner retention', function (): void {
    $task = c4RetryTask();
    app(RetryFulfillmentTaskAction::class)->execute($task, 'Trusted CLI recovery');
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::PENDING);
    $this->assertDatabaseHas('audit_logs', ['action' => 'fulfillment.retry', 'actor_type' => 'system', 'actor_id' => null, 'request_id' => null]);
    $closedTask = c4RetryTask();
    User::whereKey($closedTask->user_id)->delete();
    expect(fn () => app(RetryFulfillmentTaskAction::class)->execute($closedTask, 'Trusted CLI recovery'))->toThrow(DomainException::class);
    Queue::assertPushed(ExecuteFulfillmentTaskJob::class, 1);
});

it('rolls back the task instance and job dispatch when retry audit fails', function (): void {
    $actor = c4RetryActor();
    $task = c4RetryTask();
    $before = $task->getRawOriginal();
    DB::unprepared("CREATE TRIGGER c4_retry_audit_failure BEFORE INSERT ON audit_logs WHEN NEW.action = 'fulfillment.retry' BEGIN SELECT RAISE(ABORT, 'synthetic audit failure'); END");
    expect(fn () => app(RetryFulfillmentTaskAction::class)->execute($task, 'Requested retry', $actor))->toThrow(QueryException::class);
    expect($task->fresh()->getRawOriginal())->toBe($before)
        ->and($task->instance->fresh()->status)->toBe(InstanceStatus::PROVISION_FAILED);
    Queue::assertNothingPushed();
    $this->assertDatabaseCount('audit_logs', 0);
});

it('rejects reasons that cannot fit the immutable audit contract', function (string $reason): void {
    $actor = c4RetryActor();
    $task = c4RetryTask();
    expect(fn () => app(RetryFulfillmentTaskAction::class)->execute($task, $reason, $actor))->toThrow(DomainException::class);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::FAILED);
    Queue::assertNothingPushed();
    $this->assertDatabaseCount('audit_logs', 0);
})->with(['', '  ', str_repeat('x', 256)]);
