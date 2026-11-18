<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\SendEventNotificationAction;
use App\Jobs\Concerns\NotificationOutboxPayload;
use App\Models\NotificationDelivery;
use App\Support\RetryableNotificationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendEventNotificationJob implements ShouldQueue
{
    use NotificationOutboxPayload, Queueable {
        NotificationOutboxPayload::__serialize insteadof Queueable;
        NotificationOutboxPayload::__unserialize insteadof Queueable;
    }

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public int $timeout = 60;

    public function __construct(public int $userId = 0, public string $event = '', public string $channel = '', public string $target = '', public array $variables = [], public ?int $bindingId = null, public ?int $deliveryId = null) {}

    public function handle(SendEventNotificationAction $action): void
    {
        try {
            $action->execute($this);
        } catch (RetryableNotificationException $e) {
            if ($this->job !== null && $this->deliveryId !== null && NotificationDelivery::find($this->deliveryId)?->status === 'failed') {
                $this->fail($e);

                return;
            }
            if ($this->job === null || $this->attempts() >= $this->tries) {
                throw $e;
            }
            $this->release(max($e->retryAfter, $this->backoff[$this->attempts() - 1]));
        }
    }
}
