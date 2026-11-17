<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\SendEventNotificationAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendEventNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $userId, public string $event, public string $channel, public string $target, public array $variables = [], public ?int $bindingId = null) {}

    public function handle(SendEventNotificationAction $action): void
    {
        $action->execute($this);
    }
}
