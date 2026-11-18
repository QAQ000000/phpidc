<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Actions\CallUpstreamAction;
use App\Actions\CancelUserNotificationsAction;
use App\Actions\DispatchOperationalIncidentAction;
use App\Actions\ExecuteFulfillmentTaskAction;
use App\Actions\ExecuteNotificationDeliveryAction;
use App\Actions\ExpandOperationsNotificationsAction;
use App\Actions\RecordObservedOperationalEventAction;
use App\Actions\RecordOperationalIncidentAction;
use App\Enums\FulfillmentTaskStatus;
use App\Models\FulfillmentTask;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\OperationalEvent;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Models\UserNotificationBinding;
use App\Services\NotificationManager;
use App\Services\SettingService;
use App\Services\UpstreamOperationBuffer;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Queue::fake();
    Http::preventStrayRequests();
});

function operationalNotificationFixture(): array
{
    $owner = User::factory()->create();
    UserNotificationBinding::create(['user_id' => $owner->id, 'channel' => 'telegram', 'target' => 'PRIVATE-SOURCE-CHAT', 'verified_at' => now()]);
    DB::table('system_settings')->updateOrInsert(['group' => 'telegram', 'key' => 'bot_token'], ['value' => 'PRIVATE-OFFLINE-TOKEN', 'is_encrypted' => false]);
    app(SettingService::class)->forget('telegram.bot_token');
    app(NotificationManager::class)->queue($owner->id, 'invoice.paid', ['invoice_no' => 'INV-OFFLINE', 'amount' => '1.00', 'currency' => 'CNY'], 'invoice:offline:paid');

    return [$owner, NotificationDelivery::where('channel', 'telegram')->sole()];
}

it('persists a final task failure once and constructs its alert independently without executing or sending work', function (): void {
    $task = FulfillmentTask::factory()->create(['attempts' => 5, 'max_attempts' => 5]);
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::FAILED)->and(OperationalEvent::count())->toBe(1)
        ->and(NotificationEvent::count())->toBe(0);
    $incident = OperationalEvent::sole();
    expect($incident->variables['task_id'])->toBe((string) $task->id)->and($incident->notified_at)->toBeNull();
    $dispatch = app(DispatchOperationalIncidentAction::class);
    $dispatch->execute($incident->id);
    $dispatch->execute($incident->id);
    expect(NotificationEvent::count())->toBe(1)->and(NotificationDelivery::count())->toBe(0)
        ->and($incident->fresh()->notification_event_id)->toBe(NotificationEvent::sole()->id)->and($incident->fresh()->notified_at)->not->toBeNull();
    app(ExpandOperationsNotificationsAction::class)->execute();
    expect(NotificationEvent::sole()->suppression_reason)->toBe('awaiting_operations_recipient');
    Http::assertNothingSent();
});

it('retains an observed failure after its action rollback and rethrows the original exception', function (): void {
    $user = User::factory()->create();
    $originalName = $user->name;
    $original = new RuntimeException('PRIVATE-ORIGINAL-ERROR');
    try {
        app(RecordObservedOperationalEventAction::class)->transaction(function (Closure $observe) use ($user, $original): void {
            $user->update(['name' => 'Must roll back']);
            $observe('fulfillment.failed', ['task_id' => 900, 'status' => 'failed', 'password' => 'PRIVATE-UNSAFE-VARIABLE'], 'fulfillment:900:failed');
            throw $original;
        });
        test()->fail('The original failure must be rethrown.');
    } catch (RuntimeException $error) {
        expect($error)->toBe($original);
    }
    expect($user->fresh()->name)->toBe($originalName)->and(OperationalEvent::count())->toBe(1)
        ->and(OperationalEvent::sole()->variables)->toBe(['task_id' => '900', 'status' => 'failed'])
        ->and(NotificationEvent::count())->toBe(0);
});

it('retries event persistence only after source rollback when the initial incident insert failed', function (): void {
    $state = (object) ['remaining' => 1];
    DB::listen(function (QueryExecuted $query) use ($state): void {
        if ($state->remaining > 0 && str_starts_with(strtolower($query->sql), 'insert into "operational_events"')) {
            $state->remaining--;
            throw new RuntimeException('Injected original incident insertion failure');
        }
    });
    expect(fn () => app(RecordObservedOperationalEventAction::class)->transaction(fn (Closure $observe) => $observe('fulfillment.recovery-exhausted', ['task_id' => 901, 'status' => 'failed'], 'fulfillment:901:recovery-exhausted')))
        ->toThrow(RuntimeException::class, 'Injected original incident insertion failure');
    expect($state->remaining)->toBe(0)->and(OperationalEvent::count())->toBe(1)->and(NotificationEvent::count())->toBe(0);
});

it('keeps the original error and a safe independent journal if incident persistence is unavailable', function (): void {
    $journal = storage_path('operational-event-fallback.log');
    $previous = ini_set('error_log', $journal);
    $state = (object) ['remaining' => 2];
    DB::listen(function (QueryExecuted $query) use ($state): void {
        if ($state->remaining > 0 && str_starts_with(strtolower($query->sql), 'insert into "operational_events"')) {
            $state->remaining--;
            throw new RuntimeException('PRIVATE-DATABASE-ERROR-CONTENT');
        }
    });
    try {
        expect(fn () => app(RecordObservedOperationalEventAction::class)->transaction(fn (Closure $observe) => $observe('fulfillment.failed', ['task_id' => 902, 'status' => 'failed'], 'fulfillment:902:failed')))
            ->toThrow(RuntimeException::class, 'PRIVATE-DATABASE-ERROR-CONTENT');
        expect(OperationalEvent::count())->toBe(0)->and(file_get_contents($journal))->toContain('operational_event_persistence_failed', 'fulfillment:902:failed')
            ->not->toContain('PRIVATE-DATABASE-ERROR-CONTENT');
    } finally {
        ini_set('error_log', $previous);
    }
});

it('atomically rolls back alert construction and its delivered marker then resumes with one event', function (): void {
    $incident = DB::transaction(fn () => app(RecordOperationalIncidentAction::class)->execute('fulfillment.failed', ['task_id' => 903, 'status' => 'failed'], 'fulfillment:903:failed'));
    $state = (object) ['fail' => true];
    DB::listen(function (QueryExecuted $query) use ($state): void {
        if ($state->fail && str_starts_with(strtolower($query->sql), 'update "operational_events"')) {
            $state->fail = false;
            throw new RuntimeException('Cannot persist incident outbox marker');
        }
    });
    expect(fn () => app(DispatchOperationalIncidentAction::class)->execute($incident->id))->toThrow(RuntimeException::class);
    expect(NotificationEvent::count())->toBe(0)->and($incident->fresh()->notified_at)->toBeNull();
    app(DispatchOperationalIncidentAction::class)->execute($incident->id);
    expect(NotificationEvent::count())->toBe(1)->and($incident->fresh()->notified_at)->not->toBeNull();
});

it('preserves the upstream rollback failure and original exception with a unique source operation', function (bool $loggingFails): void {
    if ($loggingFails) {
        Log::shouldReceive('critical')->once()->andThrow(new RuntimeException('PRIVATE-LOGGER-DIAGNOSTIC'));
    }
    $account = UpstreamApi::factory()->create();
    $operation = ['upstream_api_id' => $account->id, 'instance_id' => null, 'invoice_id' => null, 'method' => 'POST', 'path' => '/apply_credit',
        'http_status' => 200, 'request_payload' => '{"use_credit":0}', 'response_body' => '{"status":400}', 'duration_ms' => 1,
        'correlation_key' => 'offline-rollback-failure', 'outcome' => 'failed'];
    $original = new DomainException('Original supplier rollback unconfirmed');
    try {
        app(CallUpstreamAction::class)->execute(function () use ($operation, $original): void {
            app(UpstreamOperationBuffer::class)->append($operation);
            throw $original;
        });
        test()->fail('The supplier failure must propagate.');
    } catch (DomainException $error) {
        expect($error)->toBe($original);
    }
    $upstream = DB::table('upstream_operations')->sole();
    expect(OperationalEvent::sole()->variables['operation_id'])->toBe((string) $upstream->id)
        ->and(OperationalEvent::sole()->event_key)->toBe('upstream-operation:'.$upstream->id.':rollback-failed');
    Http::assertNothingSent();
})->with([false, true]);

it('records uncertain sends without replay and stops alerts about uncertain alerts from recursively sending', function (): void {
    [, $delivery] = operationalNotificationFixture();
    Http::fake(fn () => throw new ConnectionException('PRIVATE-TRANSPORT-DIAGNOSTIC'));
    app(ExecuteNotificationDeliveryAction::class)->execute($delivery->id);
    app(ExecuteNotificationDeliveryAction::class)->execute($delivery->id);
    expect($delivery->fresh()->status)->toBe('uncertain')->and(OperationalEvent::count())->toBe(1)->and($delivery->fresh()->attempts)->toBe(1);
    $incident = OperationalEvent::sole();
    $operator = User::factory()->create();
    app(AssignAdminRoleAction::class)->execute($operator, 'finance-viewer', 'Isolated incident recipient');
    UserNotificationBinding::create(['user_id' => $operator->id, 'channel' => 'telegram', 'target' => 'PRIVATE-OPERATOR-CHAT', 'verified_at' => now()]);
    app(DispatchOperationalIncidentAction::class)->execute($incident->id);
    app(ExpandOperationsNotificationsAction::class)->execute();
    $alert = NotificationDelivery::where('notification_event_id', $incident->fresh()->notification_event_id)->where('channel', 'telegram')->sole();
    app(ExecuteNotificationDeliveryAction::class)->execute($alert->id);
    $recursive = OperationalEvent::where('event_key', 'notification-delivery:'.$alert->id.':uncertain')->sole();
    expect($recursive->notification_suppressed)->toBeTrue()->and(OperationalEvent::count())->toBe(2);
    $events = NotificationEvent::count();
    app(DispatchOperationalIncidentAction::class)->execute($recursive->id);
    expect(NotificationEvent::count())->toBe($events)->and($recursive->fresh()->notified_at)->not->toBeNull()
        ->and(json_encode(OperationalEvent::all()->toArray(), JSON_THROW_ON_ERROR))->not->toContain('PRIVATE-');
});

it('retains closed in flight uncertainty as an anonymous incident without moving original events or contacts', function (): void {
    [$owner, $delivery] = operationalNotificationFixture();
    $delivery->update(['status' => 'running', 'send_started_at' => now(), 'attempts' => 1]);
    DB::transaction(fn () => app(CancelUserNotificationsAction::class)->execute($owner));
    expect($delivery->fresh()->status)->toBe('uncertain')->and($delivery->fresh()->encrypted_payload)->toBeNull()
        ->and(OperationalEvent::sole()->variables)->toBe(['delivery_id' => (string) $delivery->id, 'status' => 'uncertain'])
        ->and(NotificationEvent::count())->toBe(1)->and(NotificationEvent::sole()->encrypted_payload)->toBeNull();
    Http::assertNothingSent();
});

it('rejects rewriting or reusing observed facts and prevents financial proposals from becoming rollback incidents', function (): void {
    $incident = DB::transaction(fn () => app(RecordOperationalIncidentAction::class)->execute('fulfillment.failed', ['task_id' => 904], 'fulfillment:904:failed'));
    expect(fn () => $incident->update(['variables' => ['task_id' => '905']]))->toThrow(LogicException::class);
    expect(fn () => $incident->delete())->toThrow(LogicException::class);
    expect(fn () => DB::transaction(fn () => app(RecordOperationalIncidentAction::class)->execute('fulfillment.failed', ['task_id' => 905], 'fulfillment:904:failed')))->toThrow(DomainException::class);
    expect(fn () => app(RecordObservedOperationalEventAction::class)->transaction(fn (Closure $observe) => $observe('affiliate.receivable.created', ['amount' => '1.00'], 'must-not-persist:1')))->toThrow(DomainException::class);
    expect(OperationalEvent::count())->toBe(1);
});
