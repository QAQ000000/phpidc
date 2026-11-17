<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\CmsArticleType;
use App\Models\CmsCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SaveCmsCategoryAction
{
    public function execute(array $input, string $reason): CmsCategory
    {
        $data = validator([...$input, 'reason' => trim($reason)], [
            'type' => ['required', Rule::enum(CmsArticleType::class)], 'slug' => ['required', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'name' => ['required', 'string', 'max:255'], 'is_hidden' => ['sometimes', 'boolean'],
            'seo_title' => ['nullable', 'string', 'max:255'], 'seo_keywords' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:1000'], 'sort_order' => ['sometimes', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:1000'],
        ])->validate();
        unset($data['reason']);

        return DB::transaction(function () use ($data, $reason): CmsCategory {
            $category = CmsCategory::updateOrCreate(['type' => $data['type'], 'slug' => $data['slug']], $data);
            DB::table('audit_logs')->insert(['actor_type' => 'system', 'action' => 'cms.category_save', 'subject_type' => CmsCategory::class,
                'subject_id' => $category->id, 'reason' => $reason, 'after' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

            return $category;
        });
    }
}
