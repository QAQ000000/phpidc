<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\SendEventNotificationJob;
use App\Jobs\SendNotificationJob;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class DispatchNotificationDeliveryAction
{
    public function execute(int $id): void
    {
        $delivery = NotificationDelivery::findOrFail($id);
        if (! in_array($delivery->status, ['pending', 'retry_wait'], true) || $delivery->encrypted_payload === null) {
            return;
        }
        $payload = $delivery->encrypted_payload;
        $event = NotificationEvent::findOrFail($delivery->notification_event_id);
        $job = $delivery->job_kind === 'renewal'
            ? new SendNotificationJob($payload['invoice_id'], $delivery->channel, $payload['target'], $delivery->is_fallback, $id)
            : new SendEventNotificationJob($delivery->user_id, $event->event_type, $delivery->channel, $payload['target'], $payload['variables'], $delivery->binding_id, $id);
        try {
            // Keep network delivery asynchronous even when a local installation uses the sync default.
            if (config('queue.default') === 'sync') {
                $job->onConnection('database');
            }
            Bus::dispatch($job->delay($delivery->available_at));
            $delivery->update(['last_dispatched_at' => now()]);
        } catch (\Exception) {
            // The durable pending delivery remains visible to the sweeper; never roll back committed business effects.
            Log::error('Notification wakeup failed; durable delivery awaits dispatch.', ['notification_delivery_id' => $id]);
        }
    }
}
