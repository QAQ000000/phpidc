<?php

declare(strict_types=1);

namespace App\Enums;

enum FulfillmentTaskStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case SUCCESS = 'success';
    case FAILED = 'failed';

    /** Marked when the instance was hot-swapped or cancelled mid-queue (INV-T5). */
    case STALE = 'stale';

    public function isOpen(): bool
    {
        return in_array($this, [self::PENDING, self::RUNNING], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::SUCCESS, self::FAILED, self::STALE], true);
    }
}
