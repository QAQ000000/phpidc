<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\SendNotificationDeliveryJob;
use App\Models\NotificationDelivery;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class DispatchNotificationDeliveryAction
{
    public function execute(int $id): void
    {
        $delivery = NotificationDelivery::findOrFail($id);
        if (! in_array($delivery->status, ['pending', 'retry_wait'], true) || $delivery->getRawOriginal('encrypted_payload') === null) {
            return;
        }
        $job = new SendNotificationDeliveryJob($id);
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
