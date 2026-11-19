<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AffiliateWithdrawal extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['encrypted_payout_details', 'encrypted_payout_evidence', 'operation_requests'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'processed_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime',
            'encrypted_payout_details' => 'encrypted:array', 'encrypted_payout_evidence' => 'encrypted:array', 'operation_requests' => 'encrypted:array'];
    }
}
