<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\SendLifecycleNotificationAction;
use App\Support\RetryableNotificationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public array $backoff = [60, 300, 900];

    public function __construct(public int $invoiceId, public string $channel, public string $target, public bool $fallback = false) {}

    public function handle(SendLifecycleNotificationAction $action): void
    {
        try {
            $action->execute($this->invoiceId, $this->channel, $this->target, $this->fallback, $this->attempts());
        } catch (RetryableNotificationException $e) {
            if ($this->job === null || $this->attempts() >= $this->tries) {
                throw $e;
            }
            $this->release(max($e->retryAfter, $this->backoff[$this->attempts() - 1]));
        }
    }
}
