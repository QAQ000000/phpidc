<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class OperationalEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['variables' => 'array', 'notification_suppressed' => 'boolean', 'observed_at' => 'datetime', 'notified_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (OperationalEvent $event): void {
            if ($event->isDirty(['event_type', 'event_key', 'variables', 'notification_suppressed', 'observed_at'])) {
                throw new LogicException('An observed operational fact cannot be rewritten.');
            }
        });
        static::deleting(fn () => throw new LogicException('Observed operational facts are retained.'));
    }
}
