<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/** Only a protocol-confirmed temporary rejection permits replay. No raw transport exception is chained. */
class RetryableNotificationException extends RuntimeException
{
    public function __construct(public readonly int $retryAfter = 60)
    {
        parent::__construct('Notification temporarily rejected; delivery not accepted.');
    }
}
