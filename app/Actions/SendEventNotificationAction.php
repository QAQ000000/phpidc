<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\SendEventNotificationJob;

class SendEventNotificationAction
{
    public function execute(SendEventNotificationJob $job, ?int $attempt = null): void
    {
        $id = app(AdoptLegacyNotificationAction::class)->execute($job);
        if ($id !== 0) {
            app(ExecuteNotificationDeliveryAction::class)->execute($id);
        }
    }
}
