<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReconciliationCase extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['revision' => 1, 'status' => 'open'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'resolved_at' => 'datetime'];
    }
}
