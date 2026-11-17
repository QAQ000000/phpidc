<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RedemptionCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RedemptionCode extends Model
{
    /** @use HasFactory<RedemptionCodeFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['code', 'code_hash'];

    protected function casts(): array
    {
        return ['code' => 'encrypted', 'reserved_until' => 'datetime', 'sold_at' => 'datetime'];
    }
}
