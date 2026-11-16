<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DriverType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UpstreamApi extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'driver' => DriverType::class,
            'encrypted_secret' => 'encrypted',
            'config' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function instances(): HasMany
    {
        return $this->hasMany(Instance::class);
    }
}
