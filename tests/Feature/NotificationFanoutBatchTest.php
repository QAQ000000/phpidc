<?php

declare(strict_types=1);

use App\Actions\CancelUserNotificationsAction;
use App\Actions\ExpandOperationsNotificationsAction;
use App\Actions\RecordNotificationEventAction;
use App\Exceptions\NotificationExpansionFailed;
use App\Jobs\SendNotificationDeliveryJob;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Models\UserNotificationBinding;
use App\Services\NotificationManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Queue::fake();
    Http::preventStrayRequests();
});

function fanoutBindings(User $user, int $count, string $channel = 'telegram', bool $verified = true): void
{
    $rows = [];
    for ($index = 0; $index < $count; $index++) {
        $rows[] = ['user_id' => $user->id, 'channel' => $channel, 'target' => 'offline-'.$channel.'-'.$index,
            'verified_at' => $verified ? now() : null, 'created_at' => now(), 'updated_at' => now()];
    }
    DB::table('user_notification_bindings')->insert($rows);
}

function fanoutOperator(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(Permission::findOrCreate('finance.view', 'web'));

    return $user;
}

it('bounds inline user expansion and resumes every original binding without following a growing tail', function (): void {
    $user = User::factory()->create();
    fanoutBindings($user, 305);
    $reads = (object) ['queries' => []];
    DB::listen(function (QueryExecuted $query) use ($reads): void {
        if (str_starts_with(strtolower($query->sql), 'select * from "user_notification_bindings"')) {
            $reads->queries[] = $query->sql;
        }
    });
    app(NotificationManager::class)->queue($user->id, 'ticket.reply', ['ticket_id' => 5], 'ticket-reply:fanout');
    $event = NotificationEvent::sole();
    expect(NotificationDelivery::count())->toBe(100)->and($event->expanded_at)->toBeNull()
        ->and($event->recipient_binding_cursor)->toBeGreaterThan(0);
    Queue::assertPushed(SendNotificationDeliveryJob::class, 100);
    $late = UserNotificationBinding::create(['user_id' => $user->id, 'channel' => 'telegram', 'target' => 'late-binding', 'verified_at' => now()]);
    $expand = app(ExpandOperationsNotificationsAction::class);
    expect($expand->expandOne($event->id, 100))->toBe(100);
    expect($expand->expandOne($event->id, 100))->toBe(100);
    expect($expand->expandOne($event->id, 100))->toBe(6);
    expect($expand->expandOne($event->id, 100))->toBe(0);
    expect(NotificationDelivery::count())->toBe(306)->and($event->fresh()->expanded_at)->not->toBeNull()
        ->and(NotificationDelivery::where('binding_id', $late->id)->exists())->toBeFalse()
        ->and(NotificationDelivery::where('is_fallback', true)->count())->toBe(1);
    foreach ($reads->queries as $sql) {
        expect($sql)->toMatch('/limit (100|[1-9][0-9]?)$/');
    }
    expect($reads->queries)->toHaveCount(4);
    Queue::assertPushed(SendNotificationDeliveryJob::class, 306);
    Http::assertNothingSent();
});

it('counts revoked and unsupported bindings toward the page quota while preserving the email fallback', function (): void {
    $user = User::factory()->create();
    fanoutBindings($user, 100, 'telegram', false);
    fanoutBindings($user, 100, 'unknown');
    app(NotificationManager::class)->queue($user->id, 'ticket.reply', [], 'ticket-reply:invalid-fanout');
    $event = NotificationEvent::sole();
    expect(NotificationDelivery::count())->toBe(0)->and($event->expanded_at)->toBeNull();
    expect(app(ExpandOperationsNotificationsAction::class)->expandOne($event->id, 100))->toBe(100);
    expect(NotificationDelivery::count())->toBe(0)->and($event->fresh()->expanded_at)->toBeNull();
    expect(app(ExpandOperationsNotificationsAction::class)->expandOne($event->id, 1))->toBe(1);
    expect(NotificationDelivery::sole()->is_fallback)->toBeTrue()->and($event->fresh()->expanded_at)->not->toBeNull();
});

it('skips an operator losing permission mid-page and continues other original operators', function (string $loss): void {
    $first = fanoutOperator();
    $second = fanoutOperator();
    fanoutBindings($first, 205);
    app(NotificationManager::class)->queueOperations('payment.callback.attention', [], 'callback:fanout:attention');
    $event = NotificationEvent::sole();
    $expand = app(ExpandOperationsNotificationsAction::class);
    expect($expand->expandOne($event->id, 100))->toBe(100)->and(NotificationDelivery::count())->toBe(100);
    $late = fanoutOperator();
    if ($loss === 'permission') {
        $first->revokePermissionTo('finance.view');
    } else {
        $first->update(['anonymized_at' => now()]);
        $first->delete();
    }
    expect($expand->expandOne($event->id, 100))->toBe(2);
    expect($event->fresh()->expanded_at)->not->toBeNull()
        ->and(NotificationDelivery::where('user_id', $second->id)->count())->toBe(1)
        ->and(NotificationDelivery::where('user_id', $late->id)->exists())->toBeFalse()
        ->and(NotificationDelivery::where('user_id', $first->id)->count())->toBe(100);
})->with(['permission', 'closed']);

it('continues beyond deleted and unverified bindings without duplicating earlier recipients', function (): void {
    $operator = fanoutOperator();
    fanoutBindings($operator, 205);
    app(NotificationManager::class)->queueOperations('payment.callback.attention', [], 'callback:deleted-binding:attention');
    $event = NotificationEvent::sole();
    $expand = app(ExpandOperationsNotificationsAction::class);
    expect($expand->expandOne($event->id, 100))->toBe(100);
    $cursor = $event->fresh()->recipient_binding_cursor;
    UserNotificationBinding::where('user_id', $operator->id)->where('id', '>', $cursor)->orderBy('id')->limit(3)->delete();
    UserNotificationBinding::where('user_id', $operator->id)->where('id', '>', $cursor)->orderBy('id')->limit(2)->update(['verified_at' => null]);
    expect($expand->expandOne($event->id, 100))->toBe(100);
    expect($expand->expandOne($event->id, 100))->toBe(3);
    $expand->execute();
    expect(NotificationDelivery::count())->toBe(201)->and($event->fresh()->expanded_at)->not->toBeNull()
        ->and(NotificationDelivery::select('recipient_key')->distinct()->count('recipient_key'))->toBe(201);
});

it('rolls an interrupted page back together with its durable cursor and wakeups', function (): void {
    $operator = fanoutOperator();
    fanoutBindings($operator, 205);
    app(NotificationManager::class)->queueOperations('payment.callback.attention', [], 'callback:rollback:attention');
    $event = NotificationEvent::sole();
    $expand = app(ExpandOperationsNotificationsAction::class);
    $expand->expandOne($event->id, 100);
    $position = $event->fresh()->getRawOriginal();
    Queue::fake();
    $state = (object) ['inserts' => 0, 'enabled' => true];
    DB::listen(function (QueryExecuted $query) use ($state): void {
        if ($state->enabled && str_starts_with(strtolower($query->sql), 'insert into "notification_deliveries"') && ++$state->inserts === 2) {
            $state->enabled = false;
            throw new RuntimeException('Interrupted recipient page');
        }
    });
    expect(fn () => $expand->expandOne($event->id, 100))->toThrow(NotificationExpansionFailed::class);
    expect(NotificationDelivery::count())->toBe(100)->and($event->fresh()->getRawOriginal())->toBe($position);
    Queue::assertNothingPushed();
    expect($expand->expandOne($event->id, 100))->toBe(100);
    expect(NotificationDelivery::count())->toBe(200);
    Queue::assertPushed(SendNotificationDeliveryJob::class, 100);
});

it('keeps a no-recipient operation visibly awaiting and discovers a later operator above the old bound', function (): void {
    User::factory()->create();
    app(NotificationManager::class)->queueOperations('payment.callback.attention', [], 'callback:awaiting:attention');
    $event = NotificationEvent::sole();
    $expand = app(ExpandOperationsNotificationsAction::class);
    expect($expand->expandOne($event->id, 100))->toBe(1);
    expect($event->fresh()->suppression_reason)->toBe('awaiting_operations_recipient')->and($event->fresh()->expanded_at)->toBeNull();
    $operator = fanoutOperator();
    expect($expand->expandOne($event->id, 100))->toBe(1);
    expect(NotificationDelivery::sole()->user_id)->toBe($operator->id)->and($event->fresh()->expanded_at)->not->toBeNull();
});

it('caps a whole compatibility sweep at one hundred recipients across events', function (): void {
    $operator = fanoutOperator();
    fanoutBindings($operator, 120);
    foreach (range(1, 3) as $id) {
        app(NotificationManager::class)->queueOperations('payment.callback.attention', [], 'callback:whole-sweep:'.$id);
    }
    app(ExpandOperationsNotificationsAction::class)->execute();
    expect(NotificationDelivery::count())->toBe(100)->and(NotificationEvent::whereNotNull('expanded_at')->count())->toBe(0);
    Http::assertNothingSent();
});

it('retains the invoice identity when a renewal fanout resumes and preserves explicit legacy recipients', function (): void {
    $user = User::factory()->create();
    fanoutBindings($user, 101);
    $record = app(RecordNotificationEventAction::class);
    $event = $record->execute($user->id, 'renewal.invoice', ['invoice_no' => 'INV-FANOUT', 'amount' => '1.00', 'currency' => 'CNY'], 'invoice:offline:renewal', 9876);
    expect($event->expanded_at)->toBeNull();
    expect(app(ExpandOperationsNotificationsAction::class)->expandOne($event->id, 100))->toBe(2);
    expect(NotificationDelivery::where('job_kind', 'renewal')->count())->toBe(102);
    expect(NotificationDelivery::where('notification_event_id', $event->id)->orderByDesc('id')->firstOrFail()->encrypted_payload['invoice_id'])->toBe(9876);
    Queue::assertPushed(SendNotificationDeliveryJob::class, 102);
    Queue::fake();
    $explicit = $record->execute($user->id, 'ticket.reply', [], 'legacy-message:offline:single', recipient: [
        'channel' => 'telegram', 'target' => 'explicit-original-target', 'binding_id' => null, 'fallback' => false,
    ]);
    expect(NotificationDelivery::where('notification_event_id', $explicit->id)->count())->toBe(1)
        ->and($explicit->expanded_at)->not->toBeNull();
    Queue::assertNothingPushed();
});

it('records a suppressed recursive operations fact while leaving ordinary fanout enabled', function (): void {
    fanoutOperator();
    $record = app(RecordNotificationEventAction::class);
    $fact = $record->execute(null, 'payment.callback.attention', ['status' => 'uncertain'], 'notification:fact-only', suppressOperationsFanout: true);
    app(NotificationManager::class)->queueOperations('payment.callback.attention', [], 'notification:ordinary');
    app(ExpandOperationsNotificationsAction::class)->execute();
    expect($fact->expanded_at)->not->toBeNull()->and($fact->suppression_reason)->toBe('operations_fanout_suppressed')
        ->and($fact->encrypted_payload['variables']['status'])->toBe('uncertain')
        ->and(NotificationDelivery::where('notification_event_id', $fact->id)->exists())->toBeFalse()
        ->and(NotificationDelivery::count())->toBe(1);
    expect(fn () => $record->execute(User::factory()->create()->id, 'ticket.reply', [], 'user:cannot-suppress', suppressOperationsFanout: true))->toThrow(DomainException::class);
});

it('bounds one event with many operators and resumes the rest within its original user bound', function (): void {
    $permission = Permission::findOrCreate('finance.view', 'web');
    $operators = User::factory()->count(105)->create();
    DB::table('model_has_permissions')->insert($operators->map(fn (User $user): array => [
        'permission_id' => $permission->id, 'model_type' => User::class, 'model_id' => $user->id,
    ])->all());
    app(NotificationManager::class)->queueOperations('payment.callback.attention', [], 'callback:many-operators:attention');
    $event = NotificationEvent::sole();
    $expand = app(ExpandOperationsNotificationsAction::class);
    expect($expand->expandOne($event->id, 1000))->toBe(100)
        ->and(NotificationDelivery::count())->toBe(100)->and($event->fresh()->expanded_at)->toBeNull();
    $late = fanoutOperator();
    expect($expand->expandOne($event->id, 100))->toBe(5)
        ->and(NotificationDelivery::count())->toBe(105)->and($event->fresh()->expanded_at)->not->toBeNull()
        ->and(NotificationDelivery::where('user_id', $late->id)->exists())->toBeFalse();
    app(NotificationManager::class)->queueOperations('payment.callback.attention', [], 'callback:many-operators:attention');
    expect($expand->expandOne($event->id, 100))->toBe(0)->and(NotificationDelivery::count())->toBe(105);
});

it('finishes exactly a hundred verified email bindings without adding a fallback', function (): void {
    $user = User::factory()->create();
    fanoutBindings($user, 100, 'email');
    app(NotificationManager::class)->queue($user->id, 'ticket.reply', [], 'ticket-reply:email-boundary');
    expect(NotificationDelivery::count())->toBe(100)->and(NotificationDelivery::where('is_fallback', true)->exists())->toBeFalse()
        ->and(NotificationEvent::sole()->expanded_at)->not->toBeNull();
});

it('scrubs a partly expanded user event on closure and never resumes it for a replacement identity', function (): void {
    $user = User::factory()->create();
    fanoutBindings($user, 205);
    app(NotificationManager::class)->queue($user->id, 'ticket.reply', [], 'ticket-reply:closed-mid-page');
    $event = NotificationEvent::sole();
    $email = $user->email;
    DB::transaction(function () use ($user): void {
        $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        app(CancelUserNotificationsAction::class)->execute($locked);
        UserNotificationBinding::where('user_id', $locked->id)->delete();
        $locked->update(['email' => 'closed-'.$locked->id.'@example.invalid', 'anonymized_at' => now()]);
        $locked->delete();
    });
    $replacement = User::factory()->create(['email' => $email]);
    app(ExpandOperationsNotificationsAction::class)->expandOne($event->id, 100);
    expect(NotificationDelivery::count())->toBe(100)->and(NotificationDelivery::where('user_id', $replacement->id)->exists())->toBeFalse()
        ->and(NotificationDelivery::whereNotNull('encrypted_payload')->exists())->toBeFalse()
        ->and($event->fresh()->encrypted_payload)->toBeNull()->and($event->fresh()->suppression_reason)->toBe('account_closed')
        ->and($event->fresh()->expanded_at)->not->toBeNull();
});

it('accounts for earlier committed users when a later recipient page fails', function (): void {
    fanoutOperator();
    fanoutOperator();
    app(NotificationManager::class)->queueOperations('payment.callback.attention', [], 'callback:partial-commits:attention');
    $event = NotificationEvent::sole();
    $state = (object) ['inserts' => 0, 'enabled' => true];
    DB::listen(function (QueryExecuted $query) use ($state): void {
        if ($state->enabled && str_starts_with(strtolower($query->sql), 'insert into "notification_deliveries"') && ++$state->inserts === 2) {
            $state->enabled = false;
            throw new RuntimeException('Later operator page interrupted');
        }
    });
    try {
        app(ExpandOperationsNotificationsAction::class)->expandOne($event->id, 25);
        $this->fail('The interrupted page must remain a visible failure.');
    } catch (NotificationExpansionFailed $error) {
        expect($error->consumedUnits)->toBe(25)->and($error->getPrevious()?->getMessage())->toBe('Later operator page interrupted');
    }
    expect(NotificationDelivery::count())->toBe(1)->and($event->fresh()->expanded_at)->toBeNull();
    expect(app(ExpandOperationsNotificationsAction::class)->expandOne($event->id, 25))->toBe(1);
    expect(NotificationDelivery::count())->toBe(2)->and($event->fresh()->expanded_at)->not->toBeNull();
});

it('stops expanding further users when the shared job time budget expires', function (): void {
    fanoutOperator();
    fanoutOperator();
    app(NotificationManager::class)->queueOperations('payment.callback.attention', [], 'callback:shared-time:attention');
    $event = NotificationEvent::sole();
    $calls = 0;
    $withinBudget = function () use (&$calls): bool {
        return ++$calls === 1;
    };
    expect(app(ExpandOperationsNotificationsAction::class)->expandOne($event->id, 100, $withinBudget))->toBe(1)
        ->and(NotificationDelivery::count())->toBe(1)->and($event->fresh()->expanded_at)->toBeNull();
    expect(app(ExpandOperationsNotificationsAction::class)->expandOne($event->id, 100))->toBe(1)
        ->and(NotificationDelivery::count())->toBe(2)->and($event->fresh()->expanded_at)->not->toBeNull();
});
