<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

class CancelUserNotificationsAction
{
    /** Caller holds the original user lock before entering the notification lock sequence. */
    public function execute(User $lockedUser): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Notification anonymization requires the account transaction and user lock.');
        }
        $eventIds = NotificationDelivery::where('user_id', $lockedUser->id)->pluck('notification_event_id');
        $events = NotificationEvent::where(fn ($query) => $query->where('user_id', $lockedUser->id)->orWhereIn('id', $eventIds))->orderBy('id')->lockForUpdate()->get();
        $deliveries = NotificationDelivery::where('user_id', $lockedUser->id)->orderBy('id')->lockForUpdate()->get();
        foreach ($events as $event) {
            if ($event->user_id === $lockedUser->id) {
                $event->update(['encrypted_payload' => null, 'suppression_reason' => 'account_closed']);
            }
        }
        foreach ($deliveries as $delivery) {
            $changes = ['encrypted_payload' => null];
            if (in_array($delivery->status, ['pending', 'retry_wait', 'running'], true)) {
                $changes += ['status' => $delivery->send_started_at === null ? 'skipped' : 'uncertain', 'error_code' => 'account_closed',
                    'locked_by' => null, 'locked_at' => null, 'lease_expires_at' => null, 'finished_at' => now()];
            }
            $delivery->update($changes);
            if ($delivery->status === 'uncertain') {
                $source = $events->firstWhere('id', $delivery->notification_event_id);
                app(RecordOperationalIncidentAction::class)->execute('notification.uncertain',
                    ['delivery_id' => $delivery->id, 'status' => 'uncertain'], 'notification-delivery:'.$delivery->id.':uncertain',
                    $source?->event_type === 'notification.uncertain');
            }
        }
        $logs = DB::table('notification_logs')->where('user_id', $lockedUser->id)->update(['target' => '[anonymized]', 'updated_at' => now()]);

        return ['events' => $events->where('user_id', $lockedUser->id)->count(), 'deliveries' => $deliveries->count(), 'logs' => $logs];
    }
}
