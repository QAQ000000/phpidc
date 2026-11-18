<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\CmsArticleType;
use App\Models\CmsCategory;
use App\Models\User;
use App\Support\CmsSlugAliases;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveCmsCategoryAction
{
    public function execute(array $input, string $reason, ?User $actor = null, ?int $categoryId = null): CmsCategory
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('cms.view');
            Gate::forUser($actor)->authorize('cms.edit');
            Gate::forUser($actor)->authorize('cms.publish');
        }
        $data = validator([...$input, 'reason' => trim($reason)], [
            'type' => ['required', Rule::enum(CmsArticleType::class)], 'slug' => ['required', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'name' => ['required', 'string', 'max:255'], 'is_hidden' => ['sometimes', 'boolean'],
            'seo_title' => ['nullable', 'string', 'max:255'], 'seo_keywords' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:1000'], 'sort_order' => ['sometimes', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:1000'],
        ])->validate();
        unset($data['reason']);

        try {
            return DB::transaction(function () use ($data, $reason, $actor, $categoryId): CmsCategory {
                if ($actor !== null && CmsCategory::where('type', $data['type'])->where('slug', $data['slug'])
                    ->when($categoryId !== null, fn ($q) => $q->whereKeyNot($categoryId))->exists()) {
                    throw ValidationException::withMessages(['slug' => '此类型下已存在相同栏目 slug。']);
                }
                $category = $categoryId === null ? CmsCategory::firstOrNew(['type' => $data['type'], 'slug' => $data['slug']])
                    : CmsCategory::whereKey($categoryId)->lockForUpdate()->firstOrFail();
                if ($categoryId === null && $category->exists) {
                    $category = CmsCategory::whereKey($category->id)->lockForUpdate()->firstOrFail();
                }
                if ($category->exists && $category->type->value !== $data['type']) {
                    throw ValidationException::withMessages(['type' => '既有栏目的类型不可修改，以保留原公开路由。']);
                }
                if ($category->exists) {
                    app(CmsSlugAliases::class)->reserveCategory($category);
                }
                $category->fill($data)->save();
                app(CmsSlugAliases::class)->reserveCategory($category);
                DB::table('audit_logs')->insert(['actor_type' => $actor === null ? 'system' : 'user', 'actor_id' => $actor?->id,
                    'action' => 'cms.category_save', 'subject_type' => CmsCategory::class,
                    'subject_id' => $category->id, 'reason' => $reason, 'after' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

                return $category;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['slug' => '此栏目 slug 已被其他栏目的当前或历史地址保留。']);
        }
    }
}
