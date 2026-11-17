<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class FinancialTransaction extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['type' => TransactionType::class, 'amount' => 'decimal:2', 'balance_after' => 'decimal:2', 'frozen_after' => 'decimal:2', 'meta' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Financial transactions are append-only.'));
        static::deleting(fn () => throw new LogicException('Financial transactions are append-only.'));
    }
}
