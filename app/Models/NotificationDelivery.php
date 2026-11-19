<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationDelivery extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['encrypted_payload'];

    protected function casts(): array
    {
        return ['encrypted_payload' => 'encrypted:array', 'is_fallback' => 'boolean', 'attempts' => 'integer', 'max_attempts' => 'integer',
            'available_at' => 'datetime', 'last_dispatched_at' => 'datetime', 'locked_at' => 'datetime', 'lease_expires_at' => 'datetime',
            'send_started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
