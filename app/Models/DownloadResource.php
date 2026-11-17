<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DownloadAccessLevel;
use Illuminate\Database\Eloquent\Model;

class DownloadResource extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['access_level' => DownloadAccessLevel::class, 'is_active' => 'boolean'];
    }
}
