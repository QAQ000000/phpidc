<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\CmsContentFormat;
use App\Models\CmsArticle;
use App\Models\CmsCategory;
use App\Models\User;
use App\Support\CmsRenderer;
use App\Support\CmsSlugAliases;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveCmsArticleAction
{
    public function execute(CmsCategory $category, array $input, string $reason, ?User $actor = null, ?int $articleId = null): CmsArticle
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('cms.view');
            Gate::forUser($actor)->authorize('cms.edit');
        }
        $reason = trim($reason);
        $data = validator([...$input, 'reason' => $reason], [
            'slug' => ['required', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'], 'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:1000000'], 'content_format' => ['required', Rule::enum(CmsContentFormat::class)],
            'is_published' => ['required', 'boolean'], 'published_at' => ['nullable', 'date'],
            'seo_title' => ['nullable', 'string', 'max:255'], 'seo_keywords' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:1000'], 'sort_order' => ['sometimes', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:255'],
        ])->validate();
        unset($data['reason']);
        $data['published_at'] = $data['is_published']
            ? Carbon::parse($data['published_at'] ?? now())->setTimezone(config('app.timezone')) : null;
        if ($data['content_format'] === CmsContentFormat::HTML->value) {
            $data['body'] = app(CmsRenderer::class)->sanitize($data['body']);
        }

        try {
            return DB::transaction(function () use ($category, $data, $reason, $actor, $articleId): CmsArticle {
                CmsCategory::whereKey($category->id)->lockForUpdate()->firstOrFail();
                $article = $articleId === null
                    ? CmsArticle::where('cms_category_id', $category->id)->where('slug', $data['slug'])->lockForUpdate()->first()
                    : CmsArticle::whereKey($articleId)->lockForUpdate()->firstOrFail();
                if ($article !== null && $article->cms_category_id !== $category->id) {
                    throw ValidationException::withMessages(['category_id' => '现有文章不能跨栏目移动，请保留原栏目。']);
                }
                if ($actor !== null && $articleId === null && $article !== null) {
                    throw ValidationException::withMessages(['slug' => '此栏目已存在相同 slug，请选择该文章编辑。']);
                }
                if ($articleId !== null && CmsArticle::where('cms_category_id', $category->id)->where('slug', $data['slug'])->whereKeyNot($articleId)->exists()) {
                    throw ValidationException::withMessages(['slug' => '此栏目已存在相同 slug。']);
                }
                if ($actor !== null && ($data['is_published'] || $article?->is_published)) {
                    Gate::forUser($actor)->authorize('cms.publish');
                }
                $before = $article?->only(['slug', 'title', 'is_published', 'published_at']);
                if ($article !== null) {
                    app(CmsSlugAliases::class)->reserveArticle($article);
                }
                $article ??= new CmsArticle(['cms_category_id' => $category->id]);
                $article->fill([
                    'slug' => $data['slug'],
                    'title' => $data['title'], 'body' => $data['body'], 'content_format' => $data['content_format'],
                    'is_published' => $data['is_published'], 'published_at' => $data['published_at'],
                    'seo_title' => $data['seo_title'] ?? null, 'seo_description' => $data['seo_description'] ?? null,
                    'seo_keywords' => $data['seo_keywords'] ?? null, 'sort_order' => $data['sort_order'] ?? 0,
                ]);
                $article->save();
                app(CmsSlugAliases::class)->reserveArticle($article);
                DB::table('audit_logs')->insert(['actor_type' => $actor === null ? 'system' : 'user', 'actor_id' => $actor?->id,
                    'before' => json_encode($before, JSON_THROW_ON_ERROR), 'action' => 'cms.article_save', 'subject_type' => CmsArticle::class,
                    'subject_id' => $article->id, 'reason' => $reason, 'after' => json_encode(['slug' => $article->slug, 'is_published' => $article->is_published,
                        'published_at' => $article->published_at?->toIso8601String()], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

                return $article;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['slug' => '此文章 slug 已被本栏目其他文章的当前或历史地址保留。']);
        }
    }
}
