<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\CmsArticleType;
use App\Models\CmsArticle;
use App\Models\CmsCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CmsSlugAliases
{
    public function reserveCategory(CmsCategory $category): void
    {
        $key = ['type' => $category->type->value, 'slug' => $category->slug];
        $existing = DB::table('cms_category_slug_aliases')->where($key)->first();
        if ($existing !== null && (int) $existing->cms_category_id !== $category->id) {
            throw ValidationException::withMessages(['slug' => '此栏目 slug 已被其他栏目的当前或历史地址保留。']);
        }
        if ($existing === null) {
            DB::table('cms_category_slug_aliases')->insert($key + ['cms_category_id' => $category->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function reserveArticle(CmsArticle $article): void
    {
        $key = ['cms_category_id' => $article->cms_category_id, 'slug' => $article->slug];
        $existing = DB::table('cms_article_slug_aliases')->where($key)->first();
        if ($existing !== null && (int) $existing->cms_article_id !== $article->id) {
            throw ValidationException::withMessages(['slug' => '此文章 slug 已被本栏目其他文章的当前或历史地址保留。']);
        }
        if ($existing === null) {
            DB::table('cms_article_slug_aliases')->insert($key + ['cms_article_id' => $article->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function category(CmsArticleType $type, string $slug): CmsCategory
    {
        $query = CmsCategory::visible()->where('type', $type);
        $current = (clone $query)->where('slug', $slug)->first();
        if ($current !== null) {
            return $current;
        }
        $id = DB::table('cms_category_slug_aliases')->where('type', $type->value)->where('slug', $slug)->value('cms_category_id');

        return $query->whereKey($id)->firstOrFail();
    }

    public function article(CmsCategory $category, string $slug): CmsArticle
    {
        $query = CmsArticle::published()->with('category')->where('cms_category_id', $category->id);
        $current = (clone $query)->where('slug', $slug)->first();
        if ($current !== null) {
            return $current;
        }
        $id = DB::table('cms_article_slug_aliases')->where('cms_category_id', $category->id)->where('slug', $slug)->value('cms_article_id');

        return $query->whereKey($id)->firstOrFail();
    }
}
