<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DriverType;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'driver_type' => DriverType::class,
            'upstream_base_prices' => 'array',
            'local_prices' => 'array',
            'provisioning_rules' => 'array',
            'is_hidden' => 'boolean',
            'require_kyc' => 'boolean',
            'require_phone' => 'boolean',
            'stock_control' => 'boolean',
            'price_locked' => 'boolean',
            'send_welcome_email' => 'boolean',
            // Money and percentages are strings, never floats (INV-M1).
            'markup_percent' => 'string',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function upstreamApi(): BelongsTo
    {
        return $this->belongsTo(UpstreamApi::class);
    }

    public function optionGroups(): HasMany
    {
        return $this->hasMany(ProductOptionGroup::class);
    }

    public function customFields(): HasMany
    {
        return $this->hasMany(ProductCustomField::class);
    }

    public function instances(): HasMany
    {
        return $this->hasMany(Instance::class);
    }
}
