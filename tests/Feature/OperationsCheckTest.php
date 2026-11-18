<?php

declare(strict_types=1);

use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\OperationalEvent;
use App\Models\User;
use App\Services\OperationsCheck;
use App\Support\MaintenanceState;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    config(['maintenance.cache_store' => 'array']);
    $this->travelTo(now()->startOfSecond());
    Queue::fake();
    Http::preventStrayRequests();
    $redis = Mockery::mock();
    $redis->shouldReceive('ping')->andReturn('PONG');
    Redis::shouldReceive('connection')->andReturn($redis);
    $operator = User::factory()->create();
    $operator->givePermissionTo(Permission::findOrCreate('finance.view', 'web'));
    foreach (config('maintenance.kinds') as $kind) {
        app(MaintenanceState::class)->heartbeat($kind, 'completed');
    }
});

function operationsCheckEvent(User $owner): NotificationEvent
{
    return NotificationEvent::create(['user_id' => $owner->id, 'scope' => 'user', 'event_type' => 'invoice.paid',
        'event_key' => 'isolated-operations-'.bin2hex(random_bytes(8)), 'dedupe_key' => hash('sha256', random_bytes(20)),
        'encrypted_payload' => ['target' => 'PRIVATE-TARGET', 'variables' => ['secret' => 'PRIVATE-SECRET']], 'expanded_at' => now()]);
}

it('reports healthy state using only database reads and does not send or dispatch anything', function (): void {
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    expect(Artisan::call('app:operations-check', ['--json' => true]))->toBe(0);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($result['status'])->toBe('ok')->and($result['checks'])->toHaveCount(19);
    foreach ($queries as $query) {
        expect(preg_match('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $query))->toBe(0);
    }
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('bounds samples and excludes recipient payloads while detecting stale due and uncertain notifications', function (): void {
    $owner = User::factory()->create();
    $event = operationsCheckEvent($owner);
    foreach (range(1, 25) as $slot) {
        NotificationDelivery::create(['notification_event_id' => $event->id, 'user_id' => $owner->id, 'channel' => 'email',
            'recipient_key' => 'private:'.$slot, 'status' => 'uncertain', 'updated_at' => now()->subSeconds(901),
            'encrypted_payload' => ['target' => 'PRIVATE-MAIL-ADDRESS']]);
    }
    NotificationDelivery::create(['notification_event_id' => $event->id, 'user_id' => $owner->id, 'channel' => 'email',
        'recipient_key' => 'private:pending', 'status' => 'pending', 'available_at' => now()->subSeconds(301)]);
    $result = app(OperationsCheck::class)->execute();
    $checks = collect($result['checks'])->keyBy('key');
    expect($result['status'])->toBe('critical')->and($checks['notification_uncertain']['count'])->toBe(25)
        ->and($checks['notification_uncertain']['sample_ids'])->toHaveCount(20)
        ->and($checks['notification_uncertain']['status'])->toBe('critical')->and($checks['outbox_due']['status'])->toBe('critical')
        ->and(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain('PRIVATE-', 'private:');
    expect(NotificationDelivery::where('status', 'uncertain')->count())->toBe(25);
});

it('returns a warning exit for due age above two minutes and before escalation', function (): void {
    $owner = User::factory()->create();
    $event = operationsCheckEvent($owner);
    NotificationDelivery::create(['notification_event_id' => $event->id, 'user_id' => $owner->id, 'channel' => 'email',
        'recipient_key' => 'due-warning', 'status' => 'pending', 'available_at' => now()->subSeconds(180)]);
    expect(Artisan::call('app:operations-check', ['--json' => true]))->toBe(1);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['status'])->toBe('warning');
});

it('detects absent stale and failed maintenance independently from a recovered earlier failure', function (): void {
    $state = app(MaintenanceState::class);
    $state->heartbeat('invoices', 'failed');
    $this->travel(1)->seconds();
    $state->heartbeat('invoices', 'completed');
    expect(collect(app(OperationsCheck::class)->execute()['checks'])->keyBy('key')['maintenance:invoices']['status'])->toBe('ok');
    $this->travel(181)->seconds();
    $checks = collect(app(OperationsCheck::class)->execute()['checks'])->keyBy('key');
    expect($checks['maintenance:invoices']['status'])->toBe('critical')->and($checks['maintenance:catalog']['status'])->toBe('ok');
    $state->store()->forget('maintenance:heartbeat:cards');
    expect(collect(app(OperationsCheck::class)->execute()['checks'])->keyBy('key')['maintenance:cards']['status'])->toBe('critical');
});

it('observes retained failure facts and escalates undelivered incident age without exposing variables', function (): void {
    foreach (range(1, 25) as $slot) {
        OperationalEvent::create(['event_type' => 'fulfillment.failed', 'event_key' => 'fact:'.$slot,
            'variables' => ['secret' => 'PRIVATE-OBSERVED'], 'observed_at' => now()->subSeconds(180)]);
    }
    OperationalEvent::create(['event_type' => 'upstream.rollback-failed', 'event_key' => 'fact:rollback',
        'variables' => [], 'observed_at' => now()->subSeconds(301), 'notified_at' => now()]);
    $checks = collect(app(OperationsCheck::class)->execute()['checks'])->keyBy('key');
    expect($checks['operational_events_pending']['count'])->toBe(25)
        ->and($checks['operational_events_pending']['status'])->toBe('warning')
        ->and($checks['operational_events_pending']['sample_ids'])->toHaveCount(20)
        ->and($checks['observed_fulfillment_failures']['count'])->toBe(25)
        ->and($checks['observed_rollback_failures']['count'])->toBe(1)
        ->and(json_encode($checks, JSON_THROW_ON_ERROR))->not->toContain('PRIVATE-OBSERVED');
    $this->travel(121)->seconds();
    expect(collect(app(OperationsCheck::class)->execute()['checks'])->keyBy('key')['operational_events_pending']['status'])->toBe('critical');
});

it('reports unavailable Redis without exposing infrastructure exception messages', function (): void {
    $unavailable = Mockery::mock();
    $unavailable->shouldReceive('connection')->andThrow(new RuntimeException('PRIVATE-REDIS-PASSWORD'));
    Redis::swap($unavailable);
    $result = app(OperationsCheck::class)->execute();
    expect($result['status'])->toBe('critical')->and(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain('PRIVATE-');
    expect(collect($result['checks'])->contains(fn (array $check): bool => ($check['code'] ?? '') === 'redis_or_heartbeat_unavailable'))->toBeTrue();
});

it('reports unavailable database separately and keeps the check read only on that failure path', function (): void {
    DB::shouldReceive('select')->with('SELECT 1')->once()->andThrow(new RuntimeException('PRIVATE-DATABASE-DSN'));
    $result = app(OperationsCheck::class)->execute();
    expect($result['status'])->toBe('critical')->and(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain('PRIVATE-');
    expect(collect($result['checks'])->contains(fn (array $check): bool => ($check['code'] ?? '') === 'database_unavailable'))->toBeTrue();
});
