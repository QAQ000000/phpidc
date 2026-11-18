<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\SendEventNotificationJob;
use App\Jobs\SendNotificationJob;
use App\Models\Invoice;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Models\UserNotificationBinding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdoptLegacyNotificationAction
{
    public function execute(SendEventNotificationJob|SendNotificationJob $job): int
    {
        if ($job->deliveryId !== null) {
            return $job->deliveryId;
        }
        $job->legacyKey ??= $job->job?->uuid() ?? (string) Str::ulid();
        $invoice = $job instanceof SendNotificationJob ? Invoice::findOrFail($job->invoiceId) : null;
        $userId = $invoice === null ? $job->userId : $invoice->user_id;

        return DB::transaction(function () use ($job, $invoice, $userId): int {
            User::withTrashed()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $channel = $job->channel === 'mail' ? 'email' : $job->channel;
            $bindingId = $job instanceof SendEventNotificationJob ? $job->bindingId : UserNotificationBinding::where('user_id', $userId)
                ->whereIn('channel', $channel === 'email' ? ['email', 'mail'] : [$channel])->where('target', $job->target)->whereNotNull('verified_at')->value('id');
            $record = app(RecordNotificationEventAction::class)->execute($userId, $invoice === null ? $job->event : 'renewal.invoice',
                $invoice === null ? $job->variables : ['invoice_no' => $invoice->invoice_no, 'amount' => $invoice->total_amount, 'currency' => $invoice->currency],
                'legacy-message:'.$job->legacyKey, $invoice?->id,
                ['channel' => $channel, 'target' => $job->target, 'binding_id' => $bindingId,
                    'fallback' => $job instanceof SendNotificationJob ? $job->fallback : $bindingId === null]);
            $delivery = NotificationDelivery::where('notification_event_id', $record->id)->first();
            if ($delivery === null) {
                // Closed users retain an event without recipient PII, including when an old payload is replayed.
                return 0;
            }

            return $job->deliveryId = $delivery->id;
        });
    }
}
