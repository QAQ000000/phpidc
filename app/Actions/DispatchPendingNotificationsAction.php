<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\OperationalEvent;
use App\Models\User;
use App\Support\FairMaintenanceBatch;
use App\Support\MaintenanceBudget;
use App\Support\MaintenanceState;
use Closure;

class DispatchPendingNotificationsAction
{
    public function execute(): void
    {
        $this->run(new MaintenanceBudget);
    }

    public function run(MaintenanceBudget $budget): array
    {
        $state = app(MaintenanceState::class);
        $phase = (int) $state->store()->get('maintenance:notifications:phase', 0) % 4;
        $state->store()->forever('maintenance:notifications:phase', ($phase + 1) % 4);
        $names = ['expand', 'recover', 'dispatch', 'incidents'];
        $summary = ['selected' => 0, 'attempted' => 0, 'succeeded' => 0, 'failed' => 0, 'deferred' => 0, 'units' => 0];
        for ($offset = 0; $offset < 4 && $budget->available(); $offset++) {
            $name = $names[($phase + $offset) % 4];
            [$query, $oldest, $consume] = match ($name) {
                'expand' => [NotificationEvent::whereNull('expanded_at'), 'created_at',
                    fn (int $id, int $quota): int => app(ExpandOperationsNotificationsAction::class)->expandOne($id, $quota, fn (): bool => $budget->available())],
                'recover' => [NotificationDelivery::where('status', 'running')->where('lease_expires_at', '<=', now()), 'lease_expires_at',
                    fn (int $id) => $this->recoverOne($id)],
                'dispatch' => [NotificationDelivery::whereIn('status', ['pending', 'retry_wait'])->where('available_at', '<=', now())
                    ->where(fn ($query) => $query->whereNull('last_dispatched_at')->orWhere('last_dispatched_at', '<=', now()->subMinute())), 'available_at',
                    fn (int $id) => app(DispatchNotificationDeliveryAction::class)->execute($id)],
                default => [OperationalEvent::whereNull('notified_at'), 'observed_at',
                    fn (int $id) => app(DispatchOperationalIncidentAction::class)->execute($id)],
            };
            $result = app(FairMaintenanceBatch::class)->execute('notifications-'.$name, $query, $oldest, $consume,
                budget: $budget, priorityLimit: 12, rotationLimit: 13);
            foreach (array_keys($summary) as $key) {
                $summary[$key] += $result[$key];
            }
        }

        return [...$summary, 'budget_exhausted' => ! $budget->available()];
    }

    public function recoverOne(int $id): void
    {
        app(ExecuteNotificationDeliveryAction::class)->locked($id, function (NotificationDelivery $delivery, User $user, Closure $observe): void {
            if ($delivery->status !== 'running' || $delivery->lease_expires_at?->isFuture()) {
                return;
            }
            $action = app(ExecuteNotificationDeliveryAction::class);
            if ($delivery->send_started_at !== null) {
                $action->finish($delivery, $user, 'uncertain', 'expired_sending_lease', 'Delivery uncertain; manual verification required. No automatic replay.', $observe);
            } elseif ($delivery->attempts >= $delivery->max_attempts) {
                $action->finish($delivery, $user, 'failed', 'attempts_exhausted', observe: $observe);
            } else {
                $delivery->available_at = now()->addSeconds([60, 300, 900][min(max($delivery->attempts - 1, 0), 2)]);
                $action->finish($delivery, $user, 'retry_wait', 'expired_preparation_lease', 'Worker stopped before sending intent.', $observe);
            }
        });
    }
}
