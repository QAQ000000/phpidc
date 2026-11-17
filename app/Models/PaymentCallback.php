<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentCallbackOutcome;
use Database\Factories\PaymentCallbackFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The deduplication authority for gateway callbacks (INV-P1 / §4).
 * One row per (gateway, gateway_trade_no).
 */
class PaymentCallback extends Model
{
    /** @use HasFactory<PaymentCallbackFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => PaymentCallbackOutcome::class,
            'raw_payload' => 'array',
            'amount' => 'decimal:2',
            'processed_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
