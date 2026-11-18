<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ReconciliationEntry extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'evidence' => 'encrypted:array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Reconciliation evidence is append-only.'));
        static::deleting(fn () => throw new LogicException('Reconciliation evidence is append-only.'));
    }
}
