<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class NotificationExpansionFailed extends RuntimeException
{
    public function __construct(public readonly int $consumedUnits, Throwable $previous)
    {
        parent::__construct('Notification expansion failed after bounded recipient work.', 0, $previous);
    }
}
