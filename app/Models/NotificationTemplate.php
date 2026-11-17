<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['variable_mapping' => 'array', 'is_enabled' => 'boolean'];
    }
}
