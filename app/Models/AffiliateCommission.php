<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AffiliateCommissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AffiliateCommission extends Model
{
    /** @use HasFactory<AffiliateCommissionFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'rate_snapshot' => 'decimal:2', 'available_at' => 'datetime', 'clawed_back_at' => 'datetime'];
    }
}
