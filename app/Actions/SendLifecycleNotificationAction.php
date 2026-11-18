<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\SendNotificationJob;

class SendLifecycleNotificationAction
{
    public function execute(int $id, string $channel, string $target, bool $fallback = false, int $attempt = 1, ?SendNotificationJob $job = null): void
    {
        $job ??= new SendNotificationJob($id, $channel, $target, $fallback);
        $delivery = app(AdoptLegacyNotificationAction::class)->execute($job);
        if ($delivery !== 0) {
            app(ExecuteNotificationDeliveryAction::class)->execute($delivery);
        }
    }
}
