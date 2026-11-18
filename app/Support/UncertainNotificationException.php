<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

class UncertainNotificationException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Notification delivery uncertain; verify with provider before replay.');
    }
}
