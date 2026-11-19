<?php

declare(strict_types=1);

use App\Actions\DispatchNotificationDeliveryAction;
use App\Actions\ExecuteNotificationDeliveryAction;
use App\Actions\RecordNotificationEventAction;
use App\Jobs\SendNotificationDeliveryJob;
use App\Models\Invoice;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Models\UserNotificationBinding;
use App\Services\SettingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Http::preventStrayRequests();
});

function deliveryJobFixture(bool $renewal = false): NotificationDelivery
{
    $queue = Queue::getFacadeRoot();
    Queue::fake();
    $user = User::factory()->create(['email' => 'delivery-private@example.test']);
    UserNotificationBinding::create(['user_id' => $user->id, 'channel' => 'telegram', 'target' => 'DELIVERY-PRIVATE-CHAT', 'verified_at' => now()]);
    $invoice = $renewal ? Invoice::factory()->create(['user_id' => $user->id]) : null;
    DB::table('system_settings')->updateOrInsert(['group' => 'telegram', 'key' => 'bot_token'], ['value' => 'DELIVERY-PRIVATE-TOKEN', 'is_encrypted' => false]);
    app(SettingService::class)->forget('telegram.bot_token');
    app(RecordNotificationEventAction::class)->execute($user->id, $renewal ? 'renewal.invoice' : 'invoice.paid',
        ['invoice_no' => 'DELIVERY-PRIVATE-INVOICE', 'amount' => '10.00', 'currency' => 'CNY', 'password' => 'DO-NOT-PERSIST'],
        'delivery-job:'.($renewal ? 'renewal' : 'event'), $invoice?->id);
    Queue::swap($queue);

    return NotificationDelivery::where('channel', 'telegram')->sole();
}

function deliveryJobDatabaseQueue(): void
{
    config(['queue.default' => 'database', 'queue.connections.database.after_commit' => false,
        'queue.failed.driver' => 'database-uuids', 'queue.failed.database' => config('database.default')]);
}

it('dispatches one ID-only wakeup without decrypting either kind of recipient snapshot', function (bool $renewal): void {
    $delivery = deliveryJobFixture($renewal);
    DB::table('notification_deliveries')->where('id', $delivery->id)->update(['encrypted_payload' => 'UNREADABLE-RECIPIENT-SNAPSHOT']);
    Queue::fake();
    config(['queue.default' => 'sync']);
    $delivery->update(['available_at' => now()->addMinute()]);
    app(DispatchNotificationDeliveryAction::class)->execute($delivery->id);
    Queue::assertPushed(SendNotificationDeliveryJob::class, 1);
    $job = Queue::pushed(SendNotificationDeliveryJob::class)->sole();
    expect($job->deliveryId)->toBe($delivery->id)->and($job->connection)->toBe('database')
        ->and($job->delay->eq($delivery->fresh()->available_at))->toBeTrue();
    $serialized = serialize($job);
    expect($serialized)->not->toContain('DELIVERY-PRIVATE', 'UNREADABLE-RECIPIENT-SNAPSHOT', 'invoiceId', 'userId', 'target', 'variables')
        ->and(unserialize($serialized)->deliveryId)->toBe($delivery->id)
        ->and($delivery->fresh()->last_dispatched_at)->not->toBeNull();
    Http::assertNothingSent();
})->with([false, true]);

it('consumes the persisted ID through the real database worker and rejects a duplicate wakeup', function (): void {
    $delivery = deliveryJobFixture();
    deliveryJobDatabaseQueue();
    $job = new SendNotificationDeliveryJob($delivery->id);
    Http::fake(function ($request) use ($delivery, $job) {
        $running = $delivery->fresh();
        expect($job->timeout)->toBe(60)->and($running->status)->toBe('running')->and($running->attempts)->toBe(1)
            ->and($running->send_started_at)->not->toBeNull()
            ->and($running->lease_expires_at->diffInSeconds($running->locked_at, true))->toBe(120.0);

        return Http::response(['ok' => true]);
    });
    Queue::push($job);
    Queue::push(new SendNotificationDeliveryJob($delivery->id));
    foreach (DB::table('jobs')->pluck('payload') as $payload) {
        expect(json_decode($payload, true, flags: JSON_THROW_ON_ERROR)['data']['commandName'])->toBe(SendNotificationDeliveryJob::class)
            ->and($payload)->not->toContain('DELIVERY-PRIVATE', 'DO-NOT-PERSIST', 'target', 'variables');
    }
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
    expect($delivery->fresh()->status)->toBe('sent')->and($delivery->fresh()->attempts)->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0)->and(DB::table('failed_jobs')->count())->toBe(0);
    $this->assertDatabaseHas('notification_logs', ['notification_delivery_id' => $delivery->id, 'attempt_no' => 1,
        'status' => 'sent', 'target' => 'DELIVERY-PRIVATE-CHAT']);
    Http::assertSentCount(1);
});

it('releases confirmed rejections for the original delivery and fails once its claim budget is consumed', function (): void {
    $delivery = deliveryJobFixture();
    deliveryJobDatabaseQueue();
    Http::fake(['*' => Http::response(['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 600]], 429)]);
    Queue::push(new SendNotificationDeliveryJob($delivery->id));
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
        expect($delivery->fresh()->attempts)->toBe($attempt)->and($delivery->fresh()->status)->toBe($attempt === 3 ? 'failed' : 'retry_wait');
        if ($attempt < 3) {
            $queued = DB::table('jobs')->sole();
            expect($queued->attempts)->toBe($attempt)->and($queued->available_at)->toBeGreaterThanOrEqual(now()->addSeconds(599)->timestamp)
                ->and($queued->payload)->not->toContain('DELIVERY-PRIVATE', 'target', 'variables');
            $this->travel(601)->seconds();
        }
    }
    expect(DB::table('jobs')->count())->toBe(0)->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->sole()->payload)->not->toContain('DELIVERY-PRIVATE', 'target', 'variables');
    $this->assertDatabaseCount('notification_logs', 3);
    Http::assertSentCount(3);
});

it('acknowledges an uncertain transport result without another send from a repeated ID wakeup', function (): void {
    $delivery = deliveryJobFixture();
    deliveryJobDatabaseQueue();
    Http::fake(['*' => Http::response(['unknown' => 'DELIVERY-PRIVATE-PROVIDER-DETAIL'], 500)]);
    Queue::push(new SendNotificationDeliveryJob($delivery->id));
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
    (new SendNotificationDeliveryJob($delivery->id))->handle(app(ExecuteNotificationDeliveryAction::class));
    expect($delivery->fresh()->status)->toBe('uncertain')->and($delivery->fresh()->attempts)->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0)->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('notification_logs')->sole()->error_message)->toContain('No automatic replay')->not->toContain('DELIVERY-PRIVATE');
    Http::assertSentCount(1);
});
