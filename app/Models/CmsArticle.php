<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CmsContentFormat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CmsArticle extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['content_format' => CmsContentFormat::class, 'is_published' => 'boolean', 'published_at' => 'datetime'];
    }

    /** @return BelongsTo<CmsCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(CmsCategory::class, 'cms_category_id');
    }

    /** @param Builder<self> $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('is_published', true)->whereNotNull('published_at')->where('published_at', '<=', now())
            ->whereHas('category', fn (Builder $category) => $category->where('is_hidden', false));
    }

    public function publicPath(): string
    {
        return $this->category->publicPath().'/'.rawurlencode($this->slug);
    }
}
