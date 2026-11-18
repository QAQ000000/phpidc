<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_category_slug_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cms_category_id')->constrained('cms_categories')->restrictOnDelete();
            $table->string('type', 16);
            $table->string('slug');
            $table->timestamps();
            $table->unique(['type', 'slug']);
        });
        Schema::create('cms_article_slug_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cms_category_id')->constrained('cms_categories')->restrictOnDelete();
            $table->foreignId('cms_article_id')->constrained('cms_articles')->restrictOnDelete();
            $table->string('slug');
            $table->timestamps();
            $table->unique(['cms_category_id', 'slug']);
        });

        // Current names share the same reservation namespace as historical names.
        DB::table('cms_categories')->select(['id', 'type', 'slug'])->chunkById(100, function ($categories): void {
            foreach ($categories as $category) {
                DB::table('cms_category_slug_aliases')->insert(['cms_category_id' => $category->id, 'type' => $category->type,
                    'slug' => $category->slug, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        DB::table('cms_articles')->select(['id', 'cms_category_id', 'slug'])->chunkById(100, function ($articles): void {
            foreach ($articles as $article) {
                DB::table('cms_article_slug_aliases')->insert(['cms_category_id' => $article->cms_category_id, 'cms_article_id' => $article->id,
                    'slug' => $article->slug, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_article_slug_aliases');
        Schema::dropIfExists('cms_category_slug_aliases');
    }
};
