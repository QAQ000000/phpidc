<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Applied evidence is append-only; subsequent confirmations create a new row. */
class InstanceMappingReview extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'checked_at' => 'datetime', 'confirmed_at' => 'datetime', 'applied_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Mapping review evidence is append-only.'));
        static::deleting(fn () => throw new LogicException('Mapping review evidence is append-only.'));
    }
}
