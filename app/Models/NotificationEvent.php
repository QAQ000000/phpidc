<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['encrypted_payload' => 'encrypted:array', 'expanded_at' => 'datetime'];
    }
}
