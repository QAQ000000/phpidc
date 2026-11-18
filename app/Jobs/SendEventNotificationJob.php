<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\SendEventNotificationAction;
use App\Support\RetryableNotificationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendEventNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public int $timeout = 60;

    public function __construct(public int $userId, public string $event, public string $channel, public string $target, public array $variables = [], public ?int $bindingId = null) {}

    public function handle(SendEventNotificationAction $action): void
    {
        try {
            $action->execute($this);
        } catch (RetryableNotificationException $e) {
            if ($this->job === null || $this->attempts() >= $this->tries) {
                throw $e;
            }
            $this->release(max($e->retryAfter, $this->backoff[$this->attempts() - 1]));
        }
    }
}
