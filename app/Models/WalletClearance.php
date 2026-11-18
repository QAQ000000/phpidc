<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletClearance extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['payout_details', 'payout_evidence', 'operation_requests'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'payout_details' => 'encrypted:array', 'payout_evidence' => 'encrypted:array',
            'operation_requests' => 'encrypted:array', 'approved_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
