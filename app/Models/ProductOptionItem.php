<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductOptionItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['upstream_base_prices' => 'array', 'local_prices' => 'array', 'is_hidden' => 'boolean'];
    }

    /** @return BelongsTo<ProductOptionGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductOptionGroup::class, 'option_group_id');
    }
}
