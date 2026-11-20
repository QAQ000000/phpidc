<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ExternalCollectionReceipt extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'released_at' => 'datetime', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $receipt): void {
            if (($receipt->offline_invoice_receipt_id === null) === ($receipt->receivable_settlement_id === null)) {
                throw new LogicException('A collection receipt needs exactly one real source.');
            }
        });
        static::updating(function (self $receipt): void {
            if ($receipt->receivable_settlement_id === null || $receipt->getOriginal('released_at') !== null
                || $receipt->released_at === null || $receipt->active_receipt_key !== null
                || array_diff(array_keys($receipt->getDirty()), ['active_receipt_key', 'released_at']) !== []) {
                throw new LogicException('Only a completed receivable reversal may release its collection claim.');
            }
        });
        static::deleting(fn () => throw new LogicException('Collection receipt history must be retained.'));
    }
}
