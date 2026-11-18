<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\OperationalEvent;
use Illuminate\Support\Facades\DB;

class DispatchOperationalIncidentAction
{
    /** Only constructs durable notification work; never sends to an external transport. */
    public function execute(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $incident = OperationalEvent::whereKey($id)->lockForUpdate()->first();
            if ($incident === null || $incident->notified_at !== null) {
                return;
            }
            $event = $incident->notification_suppressed ? null : app(RecordNotificationEventAction::class)->execute(null,
                $incident->event_type, $incident->variables, 'operational-event:'.$incident->id);
            $incident->update(['notification_event_id' => $event?->id, 'notified_at' => now()]);
        });
    }
}
