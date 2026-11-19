<?php

declare(strict_types=1);

use App\Actions\CancelUserNotificationsAction;
use App\Actions\DispatchPendingNotificationsAction;
use App\Actions\ExecuteNotificationDeliveryAction;
use App\Actions\ExpandOperationsNotificationsAction;
use App\Actions\ReencryptSecretsAction;
use App\Actions\ResolveNotificationDeliveryAction;
use App\Actions\SendEventNotificationAction;
use App\Actions\SendLifecycleNotificationAction;
use App\Jobs\SendEventNotificationJob;
use App\Jobs\SendNotificationDeliveryJob;
use App\Jobs\SendNotificationJob;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Models\UserNotificationBinding;
use App\Services\NotificationManager;
use App\Services\SettingService;
use App\Support\InstallationState;
use App\Support\RetryableNotificationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Queue::fake();
    Http::preventStrayRequests();
    $this->travelTo(now()->startOfSecond());
});

function notificationOutboxFixture(): array
{
    $user = User::factory()->create(['email' => 'private-notification@example.test']);
    $binding = UserNotificationBinding::create(['user_id' => $user->id, 'channel' => 'telegram', 'target' => 'private-chat-target', 'verified_at' => now()]);
    DB::table('system_settings')->updateOrInsert(['group' => 'telegram', 'key' => 'bot_token'], ['value' => 'OFFLINE-ONLY-TOKEN', 'is_encrypted' => false]);
    app(SettingService::class)->forget('telegram.bot_token');
    app(NotificationManager::class)->queue($user->id, 'invoice.paid', ['invoice_no' => 'INV-OUTBOX', 'amount' => '10.00', 'currency' => 'CNY', 'password' => 'MUST-NOT-PERSIST'], 'invoice:125:paid');

    return [$user, NotificationDelivery::where('channel', 'telegram')->sole(), $binding];
}

function notificationOutboxOperator(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo(Permission::findOrCreate('notification.manage', 'web'));

    return $actor;
}

it('commits unique event channel snapshots without leaking secrets into serialized wakeups', function (): void {
    [$user, $delivery] = notificationOutboxFixture();
    app(NotificationManager::class)->queue($user->id, 'invoice.paid', ['currency' => 'CNY', 'amount' => '10.00', 'invoice_no' => 'INV-OUTBOX'], 'invoice:125:paid');
    $this->assertDatabaseCount('notification_events', 1);
    $this->assertDatabaseCount('notification_deliveries', 2);
    Queue::assertPushed(SendNotificationDeliveryJob::class, 2);
    $raw = DB::table('notification_deliveries')->where('id', $delivery->id)->value('encrypted_payload');
    expect($raw)->not->toContain('private-chat-target', 'MUST-NOT-PERSIST', 'OFFLINE-ONLY-TOKEN');
    foreach (Queue::pushed(SendNotificationDeliveryJob::class) as $job) {
        $serialized = serialize($job);
        expect($serialized)->not->toContain('private-notification', 'private-chat-target', 'OFFLINE-ONLY-TOKEN', 'INV-OUTBOX');
        $restored = unserialize($serialized);
        expect($restored->deliveryId)->toBe($job->deliveryId);
    }
    expect(fn () => app(NotificationManager::class)->queue($user->id, 'invoice.paid', ['amount' => '11.00'], 'invoice:125:paid'))->toThrow(DomainException::class);
});

it('rolls events deliveries and wakeups back with the enclosing business transaction', function (): void {
    $user = User::factory()->create();
    expect(fn () => DB::transaction(function () use ($user): void {
        User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        app(NotificationManager::class)->queue($user->id, 'ticket.reply', ['ticket_id' => 7], 'ticket-reply:44');
        throw new RuntimeException('Simulated business rollback');
    }))->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('notification_events', 0);
    $this->assertDatabaseCount('notification_deliveries', 0);
    Queue::assertNothingPushed();
});

it('keeps a committed pending delivery recoverable when its wakeup was lost', function (): void {
    [, $delivery] = notificationOutboxFixture();
    Queue::fake();
    NotificationDelivery::query()->update(['last_dispatched_at' => null]);
    app(DispatchPendingNotificationsAction::class)->execute();
    Queue::assertPushed(SendNotificationDeliveryJob::class, 2);
    Http::fake(['*' => Http::response(['ok' => true])]);
    $action = app(ExecuteNotificationDeliveryAction::class);
    $action->execute($delivery->id);
    $action->execute($delivery->id);
    Http::assertSentCount(1);
    expect($delivery->fresh()->status)->toBe('sent')->and($delivery->fresh()->attempts)->toBe(1);
});

it('commits sending intent before transport and fences a second worker', function (): void {
    [, $delivery] = notificationOutboxFixture();
    $level = DB::transactionLevel();
    Http::fake(function () use ($delivery, $level) {
        expect(DB::transactionLevel())->toBe($level)->and($delivery->fresh()->status)->toBe('running')
            ->and($delivery->fresh()->send_started_at)->not->toBeNull();
        app(ExecuteNotificationDeliveryAction::class)->execute($delivery->id);

        return Http::response(['ok' => true]);
    });
    app(ExecuteNotificationDeliveryAction::class)->execute($delivery->id);
    Http::assertSentCount(1);
    $this->assertDatabaseCount('notification_logs', 1);
});

it('never resends an accepted message after its local result transaction crashes', function (): void {
    [, $delivery] = notificationOutboxFixture();
    Http::fake(['*' => Http::response(['ok' => true])]);
    $state = (object) ['fail' => true];
    DB::listen(function (QueryExecuted $event) use ($state): void {
        if ($state->fail && str_contains($event->sql, 'notification_logs') && str_starts_with(strtolower($event->sql), 'insert')) {
            $state->fail = false;
            throw new RuntimeException('Simulated result commit failure');
        }
    });
    expect(fn () => app(ExecuteNotificationDeliveryAction::class)->execute($delivery->id))->toThrow(RuntimeException::class);
    expect($delivery->fresh()->status)->toBe('running')->and($delivery->fresh()->send_started_at)->not->toBeNull();
    $this->travel(121)->seconds();
    app(DispatchPendingNotificationsAction::class)->execute();
    app(ExecuteNotificationDeliveryAction::class)->execute($delivery->id);
    expect($delivery->fresh()->status)->toBe('uncertain');
    Http::assertSentCount(1);
});

it('recovers only expired leases that have no persisted sending intent', function (bool $started): void {
    [, $delivery] = notificationOutboxFixture();
    $delivery->update(['status' => 'running', 'attempts' => 1, 'locked_by' => '00000000-0000-0000-0000-000000000001',
        'lease_expires_at' => now()->subSecond(), 'send_started_at' => $started ? now()->subMinute() : null]);
    app(DispatchPendingNotificationsAction::class)->execute();
    expect($delivery->fresh()->status)->toBe($started ? 'uncertain' : 'retry_wait')->and($delivery->fresh()->attempts)->toBe(1);
    Http::assertNothingSent();
})->with([false, true]);

it('bounds confirmed temporary rejection retries using the durable claim count', function (): void {
    [, $delivery] = notificationOutboxFixture();
    Http::fake(['*' => Http::response(['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 90]], 429)]);
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        expect(fn () => app(ExecuteNotificationDeliveryAction::class)->execute($delivery->id))->toThrow(RetryableNotificationException::class);
        expect($delivery->fresh()->attempts)->toBe($attempt)->and($delivery->fresh()->status)->toBe($attempt === 3 ? 'failed' : 'retry_wait');
        $this->travel(301)->seconds();
    }
    app(ExecuteNotificationDeliveryAction::class)->execute($delivery->id);
    Http::assertSentCount(3);
    $this->assertDatabaseCount('notification_logs', 3);
});

it('retains the original template snapshot while honoring revocation and disablement', function (string $change): void {
    [$user, $delivery, $binding] = notificationOutboxFixture();
    match ($change) {
        'binding' => $binding->update(['verified_at' => null]),
        'email' => $user->update(['email' => 'new@example.test']),
        'template' => NotificationTemplate::create(['event_key' => 'invoice.paid', 'channel' => 'telegram', 'is_enabled' => false]),
    };
    Http::fake(['*' => Http::response(['ok' => true])]);
    $selected = $change === 'email' ? NotificationDelivery::where('channel', 'email')->sole() : $delivery;
    app(ExecuteNotificationDeliveryAction::class)->execute($selected->id);
    expect($selected->fresh()->status)->toBe($change === 'template' ? 'disabled' : 'skipped');
    Http::assertNothingSent();
})->with(['binding', 'email', 'template']);

it('sends the committed template snapshot after an operator edits the active template', function (): void {
    $template = NotificationTemplate::create(['event_key' => 'invoice.paid', 'channel' => 'telegram', 'is_enabled' => true,
        'body_markdown' => 'Original {{invoice_no}}']);
    [, $delivery] = notificationOutboxFixture();
    $template->update(['body_markdown' => 'Replacement {{invoice_no}}']);
    Http::fake(['*' => Http::response(['ok' => true])]);
    app(ExecuteNotificationDeliveryAction::class)->execute($delivery->id);
    Http::assertSent(fn ($request) => $request['text'] === 'Original INV-OUTBOX');
    expect($delivery->fresh()->status)->toBe('sent');
});

it('scrubs pending and in-flight snapshots on closure without transferring events to a reused email', function (bool $started): void {
    [$user, $delivery] = notificationOutboxFixture();
    if ($started) {
        $delivery->update(['status' => 'running', 'send_started_at' => now(), 'attempts' => 1]);
    }
    DB::table('notification_logs')->insert(['user_id' => $user->id, 'event_key' => 'invoice.paid', 'channel' => 'email', 'target' => $user->email, 'status' => 'sent', 'created_at' => now(), 'updated_at' => now()]);
    $oldEmail = $user->email;
    DB::transaction(function () use ($user): void {
        $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        app(CancelUserNotificationsAction::class)->execute($locked);
        $locked->forceFill(['email' => 'closed-'.$user->id.'@example.invalid', 'anonymized_at' => now()])->save();
        $locked->delete();
    });
    User::factory()->create(['email' => $oldEmail]);
    app(ExecuteNotificationDeliveryAction::class)->execute($delivery->id);
    expect($delivery->fresh()->status)->toBe($started ? 'uncertain' : 'skipped')->and($delivery->fresh()->encrypted_payload)->toBeNull()
        ->and(NotificationEvent::sole()->encrypted_payload)->toBeNull()->and(DB::table('notification_logs')->sole()->target)->toBe('[anonymized]');
    Http::assertNothingSent();
})->with([false, true]);

it('requires authorized evidence and a stopped worker before replaying one uncertain delivery', function (): void {
    [, $delivery] = notificationOutboxFixture();
    $delivery->update(['status' => 'uncertain', 'send_started_at' => now(), 'attempts' => 1]);
    $action = app(ResolveNotificationDeliveryAction::class);
    expect(fn () => $action->execute(User::factory()->create(), $delivery, 'retry', 'Supplier record', 'Review', true, true))->toThrow(AuthorizationException::class);
    $actor = notificationOutboxOperator();
    expect(fn () => $action->execute($actor, $delivery, 'retry', 'No acceptance', 'Review', false, true))->toThrow(ValidationException::class);
    $action->execute($actor, $delivery, 'retry', 'Supplier confirms not accepted; worker stopped', 'Reconcile original delivery', true, true);
    expect($delivery->fresh()->status)->toBe('pending')->and($delivery->fresh()->attempts)->toBe(1)->and($delivery->fresh()->send_started_at)->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['action' => 'notification.resolve', 'subject_id' => $delivery->id]);
});

it('records operational attention without financial-tail user locks then expands only current operators', function (): void {
    app(NotificationManager::class)->queueOperations('payment.callback.attention', ['amount' => '20.00', 'currency' => 'CNY', 'status' => 'closed_account'], 'callback:68:attention');
    expect(NotificationEvent::sole()->scope)->toBe('operations');
    $this->assertDatabaseCount('notification_deliveries', 0);
    app(ExpandOperationsNotificationsAction::class)->execute();
    expect(NotificationEvent::sole()->suppression_reason)->toBe('awaiting_operations_recipient');
    $actor = User::factory()->create();
    $actor->givePermissionTo(Permission::findOrCreate('finance.view', 'web'));
    app(ExpandOperationsNotificationsAction::class)->execute();
    app(ExpandOperationsNotificationsAction::class)->execute();
    expect(NotificationDelivery::sole()->user_id)->toBe($actor->id)->and(NotificationEvent::sole()->expanded_at)->not->toBeNull();
    Http::assertNothingSent();
});

it('adopts a serialized legacy notification before sending and replays its same message as a no-op', function (): void {
    [$user, , $binding] = notificationOutboxFixture();
    $legacy = new SendEventNotificationJob($user->id, 'invoice.paid', 'telegram', $binding->target, [], $binding->id);
    $restored = unserialize(serialize($legacy));
    Http::fake(['*' => Http::response(['ok' => true])]);
    $restored->handle(app(SendEventNotificationAction::class));
    $restored->handle(app(SendEventNotificationAction::class));
    Http::assertSentCount(1);
    expect($restored->deliveryId)->not->toBeNull();
});

it('consumes the lifecycle ID payload without requiring its removed recipient fields', function (): void {
    [, $delivery] = notificationOutboxFixture();
    $job = new SendNotificationJob(125, 'telegram', 'private-chat-target', false, $delivery->id);
    $serialized = serialize($job);
    expect($serialized)->not->toContain('private-chat-target', 'invoiceId', 'target');
    $restored = unserialize($serialized);
    Http::fake(['*' => Http::response(['ok' => true])]);
    $restored->handle(app(SendLifecycleNotificationAction::class));
    $restored->handle(app(SendLifecycleNotificationAction::class));
    expect($delivery->fresh()->status)->toBe('sent')->and($delivery->fresh()->attempts)->toBe(1);
    Http::assertSentCount(1);
});

it('protects the reconciliation endpoint and never renders recipient snapshots in the admin page', function (): void {
    [, $delivery] = notificationOutboxFixture();
    $delivery->update(['status' => 'uncertain', 'attempts' => 1, 'send_started_at' => now()]);
    $path = '/admin/notification-outbox/'.$delivery->id.'/resolve';
    $input = ['decision' => 'accepted', 'evidence' => 'Verified supplier acceptance', 'reason' => 'Offline review', 'confirm' => true];
    $this->postJson($path, $input)->assertUnauthorized();
    $this->actingAs(User::factory()->create())->postJson($path, $input)->assertForbidden();
    $operator = notificationOutboxOperator();
    $operator->givePermissionTo(Permission::findOrCreate('notification.view', 'web'));
    $this->actingAs($operator)->get('/admin/notification-outbox')->assertOk()->assertSee('通知事件与投递核对')
        ->assertDontSee('private-chat-target')->assertDontSee('private-notification@example.test')->assertDontSee('INV-OUTBOX')->assertDontSee('OFFLINE-ONLY-TOKEN');
    $this->postJson($path, array_diff_key($input, ['confirm' => true]))->assertUnprocessable()->assertJsonValidationErrors('confirm');
    $this->post($path, $input)->assertRedirect('/admin/notification-outbox');
    expect($delivery->fresh()->status)->toBe('sent')->and($delivery->fresh()->error_code)->toBe('manually_verified_accepted');
    Http::assertNothingSent();
});

it('rotates both encrypted outbox snapshots and keeps the original message and delivery identity', function (): void {
    [$user, $delivery] = notificationOutboxFixture();
    $event = NotificationEvent::sole();
    $original = $delivery->encrypted_payload;
    $old = config('app.key');
    $new = 'base64:'.base64_encode(random_bytes(32));
    $installation = app(InstallationState::class);
    $installation->write('installed.lock', $installation->seal(['format' => 1, 'admin_id' => $user->id], $old));
    File::put(storage_path('framework/down'), '{}');
    $before = DB::table('notification_deliveries')->where('id', $delivery->id)->value('encrypted_payload');
    app(ReencryptSecretsAction::class)->execute($old, $new, 'Offline outbox key rotation', true);
    expect(DB::table('notification_deliveries')->where('id', $delivery->id)->value('encrypted_payload'))->not->toBe($before);
    $previous = app('encrypter');
    try {
        config(['app.key' => $new]);
        app()->instance('encrypter', $installation->encrypter($new));
        Crypt::clearResolvedInstance('encrypter');
        expect($delivery->fresh()->encrypted_payload)->toBe($original)->and($event->fresh()->encrypted_payload['variables']['invoice_no'])->toBe('INV-OUTBOX');
        Http::fake(['*' => Http::response(['ok' => true])]);
        app(ExecuteNotificationDeliveryAction::class)->execute($delivery->id);
        expect($delivery->fresh()->status)->toBe('sent')->and($delivery->fresh()->attempts)->toBe(1);
        Http::assertSentCount(1);
    } finally {
        config(['app.key' => $old]);
        app()->instance('encrypter', $previous);
        Crypt::clearResolvedInstance('encrypter');
    }
});
