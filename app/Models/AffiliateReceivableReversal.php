<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AffiliateReceivableReversal extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Receivable reversal evidence is append-only.'));
        static::deleting(fn () => throw new LogicException('Receivable reversal evidence is append-only.'));
    }
}
