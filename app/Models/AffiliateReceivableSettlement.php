<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class AffiliateReceivableSettlement extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'received_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    /** @return HasOne<AffiliateReceivableReversal, $this> */
    public function reversal(): HasOne
    {
        return $this->hasOne(AffiliateReceivableReversal::class, 'settlement_id');
    }

    protected static function booted(): void
    {
        static::updating(function (self $entry): void {
            if (array_diff(array_keys($entry->getDirty()), ['active_receipt_key', 'reversed_at', 'updated_at']) !== []
                || $entry->getOriginal('reversed_at') !== null || $entry->reversed_at === null || $entry->active_receipt_key !== null) {
                throw new LogicException('Receivable evidence is immutable; only a complete audited reversal can release its receipt.');
            }
        });
        static::deleting(fn () => throw new LogicException('Receivable evidence cannot be deleted.'));
    }
}
