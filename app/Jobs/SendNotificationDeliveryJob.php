<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ExecuteNotificationDeliveryAction;
use App\Models\NotificationDelivery;
use App\Support\RetryableNotificationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendNotificationDeliveryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $deliveryId) {}

    public function handle(ExecuteNotificationDeliveryAction $action): void
    {
        try {
            $action->execute($this->deliveryId);
        } catch (RetryableNotificationException $error) {
            if ($this->job !== null && NotificationDelivery::find($this->deliveryId)?->status === 'failed') {
                $this->fail($error);

                return;
            }
            if ($this->job === null || $this->attempts() >= $this->tries) {
                throw $error;
            }
            $this->release(max($error->retryAfter, $this->backoff[$this->attempts() - 1]));
        }
    }
}
