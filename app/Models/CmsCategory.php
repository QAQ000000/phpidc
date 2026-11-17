<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CmsArticleType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CmsCategory extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['type' => CmsArticleType::class, 'is_hidden' => 'boolean'];
    }

    /** @return HasMany<CmsArticle, $this> */
    public function articles(): HasMany
    {
        return $this->hasMany(CmsArticle::class);
    }

    /** @param Builder<self> $query */
    public function scopeVisible(Builder $query): void
    {
        $query->where('is_hidden', false);
    }

    public function publicPath(): string
    {
        return $this->type->routePrefix().'/'.rawurlencode($this->slug);
    }
}
