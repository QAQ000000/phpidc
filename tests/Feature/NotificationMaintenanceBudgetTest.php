<?php

declare(strict_types=1);

use App\Actions\DispatchNotificationDeliveryAction;
use App\Actions\DispatchOperationalIncidentAction;
use App\Actions\DispatchPendingNotificationsAction;
use App\Actions\ExpandOperationsNotificationsAction;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\OperationalEvent;
use App\Models\User;
use App\Support\MaintenanceBudget;
use App\Support\MaintenanceState;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config(['maintenance.cache_store' => 'array']);
    Queue::fake();
    Http::preventStrayRequests();
    $user = User::factory()->create();
    foreach (range(1, 40) as $slot) {
        $event = NotificationEvent::create(['user_id' => $user->id, 'scope' => 'user', 'event_type' => 'invoice.paid',
            'event_key' => 'budget:'.$slot, 'dedupe_key' => hash('sha256', 'budget:'.$slot), 'encrypted_payload' => []]);
        foreach (['running', 'pending'] as $status) {
            NotificationDelivery::create(['notification_event_id' => $event->id, 'user_id' => $user->id,
                'channel' => 'email', 'recipient_key' => 'budget:'.$slot.':'.$status, 'status' => $status,
                'available_at' => now()->subMinute(), 'lease_expires_at' => now()->subMinute(), 'encrypted_payload' => []]);
        }
        OperationalEvent::create(['event_type' => 'fulfillment.failed', 'event_key' => 'budget:'.$slot,
            'variables' => [], 'observed_at' => now()->subMinute()]);
    }
});

it('shares one hundred total work units across actual four phase queries and rotates their first opportunity', function (): void {
    $calls = [];
    $this->mock(ExpandOperationsNotificationsAction::class)->shouldReceive('expandOne')
        ->andReturnUsing(function (int $id, int $quota, Closure $withinBudget) use (&$calls): int {
            expect($withinBudget())->toBeTrue();
            $calls[] = 'expand';

            return $quota;
        });
    $dispatcher = $this->partialMock(DispatchPendingNotificationsAction::class);
    $dispatcher->shouldReceive('recoverOne')->andReturnUsing(function () use (&$calls): void {
        $calls[] = 'recover';
    });
    $this->mock(DispatchNotificationDeliveryAction::class)->shouldReceive('execute')
        ->andReturnUsing(function () use (&$calls): void {
            $calls[] = 'dispatch';
        });
    $this->mock(DispatchOperationalIncidentAction::class)->shouldReceive('execute')
        ->andReturnUsing(function () use (&$calls): void {
            $calls[] = 'incidents';
        });
    $budget = new MaintenanceBudget;
    $summary = $dispatcher->run($budget);
    expect($summary['units'])->toBe(100)->and($budget->used())->toBe(100)
        ->and(array_values(array_unique($calls)))->toBe(['expand', 'recover', 'dispatch', 'incidents'])
        ->and(array_count_values($calls))->toBe(['expand' => 3, 'recover' => 25, 'dispatch' => 25, 'incidents' => 25]);
    $calls = [];
    $dispatcher->run(new MaintenanceBudget);
    expect(array_values(array_unique($calls)))->toBe(['recover', 'dispatch', 'incidents', 'expand'])
        ->and(app(MaintenanceState::class)->store()->get('maintenance:notifications:phase'))->toBe(2);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('preserves dispatch opportunities after poisoned expansion and records bounded failed source ids', function (): void {
    $this->mock(ExpandOperationsNotificationsAction::class)->shouldReceive('expandOne')->andThrow(new DomainException('poison expansion'));
    $dispatcher = $this->partialMock(DispatchPendingNotificationsAction::class);
    $dispatcher->shouldReceive('recoverOne')->andReturnNull();
    $dispatched = 0;
    $this->mock(DispatchNotificationDeliveryAction::class)->shouldReceive('execute')->andReturnUsing(function () use (&$dispatched): void {
        $dispatched++;
    });
    $this->mock(DispatchOperationalIncidentAction::class)->shouldReceive('execute')->andReturnNull();
    $summary = $dispatcher->run(new MaintenanceBudget);
    expect($summary['units'])->toBe(100)->and($summary['failed'])->toBe(25)->and($dispatched)->toBe(25)
        ->and(app(MaintenanceState::class)->failures('notifications-expand'))->toHaveCount(25);
});
