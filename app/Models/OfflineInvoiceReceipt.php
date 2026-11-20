<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** An actual completed offline receipt; never a synthetic gateway callback. */
class OfflineInvoiceReceipt extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['encrypted_evidence', 'intent'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'received_at' => 'datetime', 'encrypted_evidence' => 'encrypted',
            'intent' => 'array', 'allocation_snapshot' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Offline receipt evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Offline receipt evidence must be retained.'));
    }
}
