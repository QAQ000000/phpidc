<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RedemptionCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RedemptionCode extends Model
{
    /** @use HasFactory<RedemptionCodeFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['code', 'code_hash'];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function casts(): array
    {
        return ['code' => 'encrypted', 'reserved_until' => 'datetime', 'sold_at' => 'datetime'];
    }
}
