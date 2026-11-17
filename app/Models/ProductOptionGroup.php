<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductOptionGroup extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_upgradeable' => 'boolean',
            'downgrade_bounds' => 'array',
            'upstream_meta' => 'array',
            'is_hidden' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<ProductOptionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ProductOptionItem::class, 'option_group_id');
    }
}
