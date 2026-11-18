<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\NotificationDelivery;
use App\Models\User;

class DispatchPendingNotificationsAction
{
    public function execute(): void
    {
        app(ExpandOperationsNotificationsAction::class)->execute();
        NotificationDelivery::where('status', 'running')->where('lease_expires_at', '<=', now())->orderBy('id')->limit(200)->pluck('id')->each(function (int $id): void {
            app(ExecuteNotificationDeliveryAction::class)->locked($id, function (NotificationDelivery $delivery, User $user): void {
                if ($delivery->status !== 'running' || $delivery->lease_expires_at?->isFuture()) {
                    return;
                }
                $action = app(ExecuteNotificationDeliveryAction::class);
                if ($delivery->send_started_at !== null) {
                    $action->finish($delivery, $user, 'uncertain', 'expired_sending_lease', 'Delivery uncertain; manual verification required. No automatic replay.');
                } elseif ($delivery->attempts >= $delivery->max_attempts) {
                    $action->finish($delivery, $user, 'failed', 'attempts_exhausted');
                } else {
                    $delivery->available_at = now()->addSeconds([60, 300, 900][min(max($delivery->attempts - 1, 0), 2)]);
                    $action->finish($delivery, $user, 'retry_wait', 'expired_preparation_lease', 'Worker stopped before sending intent.');
                }
            });
        });
        NotificationDelivery::whereIn('status', ['pending', 'retry_wait'])->where('available_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('last_dispatched_at')->orWhere('last_dispatched_at', '<=', now()->subMinute()))
            ->orderBy('id')->limit(200)->pluck('id')->each(fn (int $id) => app(DispatchNotificationDeliveryAction::class)->execute($id));
    }
}
