<?php

declare(strict_types=1);

use App\Actions\SaveCmsArticleAction;
use App\Actions\SaveCmsCategoryAction;
use App\Models\CmsArticle;
use App\Models\CmsCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Queue::fake();
    Http::preventStrayRequests();
    config(['app.url' => 'https://canonical.example.test']);
});

function reviewAliasCategory(string $slug = 'original-category', string $type = 'help', ?CmsCategory $existing = null): CmsCategory
{
    return app(SaveCmsCategoryAction::class)->execute(['type' => $type, 'slug' => $slug, 'name' => 'Category '.$slug],
        'Isolated historical URL review', categoryId: $existing?->id);
}

function reviewAliasArticle(CmsCategory $category, string $slug = 'original-article', ?CmsArticle $existing = null): CmsArticle
{
    return app(SaveCmsArticleAction::class)->execute($category, ['slug' => $slug, 'title' => 'Published article '.$slug,
        'body' => '## Public documentation', 'content_format' => 'markdown', 'is_published' => true, 'published_at' => now()->subMinute()],
        'Isolated historical URL review', articleId: $existing?->id);
}

it('reserves current names together with the first audited CMS save', function (): void {
    $category = reviewAliasCategory();
    $article = reviewAliasArticle($category);

    $this->assertDatabaseHas('cms_category_slug_aliases', ['type' => 'help', 'slug' => $category->slug, 'cms_category_id' => $category->id]);
    $this->assertDatabaseHas('cms_article_slug_aliases', ['cms_category_id' => $category->id, 'slug' => $article->slug, 'cms_article_id' => $article->id]);
    $this->get('/help/original-category/original-article')->assertOk()->assertSee('Public documentation');
    expect(DB::table('audit_logs')->whereIn('action', ['cms.category_save', 'cms.article_save'])->count())->toBe(2);
});

it('resolves every combination of consecutive category and article renames in one redirect', function (): void {
    $category = reviewAliasCategory();
    $article = reviewAliasArticle($category);
    $category = reviewAliasCategory('middle-category', existing: $category);
    $article = reviewAliasArticle($category, 'middle-article', $article);
    $category = reviewAliasCategory('final-category', existing: $category);
    $article = reviewAliasArticle($category, 'final-article', $article);

    foreach (['original-category', 'middle-category', 'final-category'] as $categorySlug) {
        foreach (['original-article', 'middle-article', 'final-article'] as $articleSlug) {
            $path = '/help/'.$categorySlug.'/'.$articleSlug;
            $response = $this->get($path);
            if ($path === '/help/final-category/final-article') {
                $response->assertOk()->assertSee('<link rel="canonical" href="https://canonical.example.test/help/final-category/final-article"', false);
            } else {
                $response->assertStatus(301)->assertRedirect('https://canonical.example.test/help/final-category/final-article');
                $this->get($response->headers->get('Location'))->assertOk()->assertHeaderMissing('Location');
            }
        }
    }
    expect(DB::table('cms_category_slug_aliases')->where('cms_category_id', $category->id)->count())->toBe(3)
        ->and(DB::table('cms_article_slug_aliases')->where('cms_article_id', $article->id)->count())->toBe(3);
});

it('preserves Markdown suffix negotiation and query parameters during permanent redirects', function (): void {
    $category = reviewAliasCategory();
    $article = reviewAliasArticle($category);
    $category = reviewAliasCategory('final-category', existing: $category);
    reviewAliasArticle($category, 'final-article', $article);

    $this->get('/help/original-category/original-article.md?q=network&page=2')
        ->assertStatus(301)->assertRedirect('https://canonical.example.test/help/final-category/final-article.md?page=2&q=network')
        ->assertHeader('Vary', 'Accept')->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get('/help/final-category/final-article.md?page=2&q=network')->assertOk()
        ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')->assertSee('Public documentation');
    $this->get('/help/original-category/original-article', ['Accept' => 'text/markdown'])
        ->assertStatus(301)->assertRedirect('https://canonical.example.test/help/final-category/final-article')->assertHeader('Vary', 'Accept');
    $this->get('/help/final-category/final-article', ['Accept' => 'text/markdown'])->assertOk()
        ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')
        ->assertHeader('Link', '<https://canonical.example.test/help/final-category/final-article>; rel="canonical"');
});

it('preserves category search and pagination when redirecting an old category URL', function (): void {
    $category = reviewAliasCategory();
    reviewAliasArticle($category);
    reviewAliasCategory('final-category', existing: $category);

    $response = $this->get('/help/original-category?q=Public&page=2')
        ->assertStatus(301)->assertRedirect('https://canonical.example.test/help/final-category?page=2&q=Public');
    $this->get($response->headers->get('Location'))->assertOk()->assertViewHas('search', 'Public');
});

it('keeps aliases scoped to the category type and each article category', function (): void {
    $help = reviewAliasCategory('shared-category');
    $news = reviewAliasCategory('shared-category', 'news');
    $other = reviewAliasCategory('other-category');
    $helpArticle = reviewAliasArticle($help, 'shared-article');
    reviewAliasArticle($news, 'shared-article');
    reviewAliasArticle($other, 'shared-article');
    $help = reviewAliasCategory('renamed-help', existing: $help);
    reviewAliasArticle($help, 'renamed-help-article', $helpArticle);

    $this->get('/help/shared-category/shared-article')->assertStatus(301)
        ->assertRedirect('https://canonical.example.test/help/renamed-help/renamed-help-article');
    $this->get('/news/shared-category/shared-article')->assertOk()->assertHeaderMissing('Location');
    $this->get('/help/other-category/shared-article')->assertOk()->assertHeaderMissing('Location');
    foreach (['/news/renamed-help/shared-article', '/help/other-category/renamed-help-article', '/news/shared-category/renamed-help-article'] as $path) {
        $this->get($path)->assertNotFound()->assertHeaderMissing('Location');
    }
});

it('rejects another category claiming a current or historical name and rolls back all changes', function (): void {
    $owner = reviewAliasCategory();
    reviewAliasCategory('final-category', existing: $owner);
    $other = reviewAliasCategory('other-category');
    $beforeAudits = DB::table('audit_logs')->count();

    foreach (['original-category', 'final-category'] as $claimed) {
        expect(fn () => reviewAliasCategory($claimed, existing: $other))->toThrow(ValidationException::class);
    }
    expect(fn () => reviewAliasCategory('original-category'))->toThrow(ValidationException::class);
    expect($other->fresh()->slug)->toBe('other-category')->and(CmsCategory::count())->toBe(2)
        ->and(DB::table('audit_logs')->count())->toBe($beforeAudits)
        ->and(DB::table('cms_category_slug_aliases')->where('cms_category_id', $other->id)->pluck('slug')->all())->toBe(['other-category']);
});

it('rejects another article claiming a current or historical name while allowing another category', function (): void {
    $category = reviewAliasCategory();
    $owner = reviewAliasArticle($category);
    reviewAliasArticle($category, 'final-article', $owner);
    $other = reviewAliasArticle($category, 'other-article');
    $beforeAudits = DB::table('audit_logs')->count();

    foreach (['original-article', 'final-article'] as $claimed) {
        expect(fn () => reviewAliasArticle($category, $claimed, $other))->toThrow(ValidationException::class);
    }
    expect(fn () => reviewAliasArticle($category, 'original-article'))->toThrow(ValidationException::class);
    expect($other->fresh()->slug)->toBe('other-article')->and(CmsArticle::count())->toBe(2)
        ->and(DB::table('audit_logs')->count())->toBe($beforeAudits)
        ->and(DB::table('cms_article_slug_aliases')->where('cms_article_id', $other->id)->pluck('slug')->all())->toBe(['other-article']);

    $different = reviewAliasCategory('different-category');
    reviewAliasArticle($different, 'original-article');
    $this->get('/help/different-category/original-article')->assertOk()->assertHeaderMissing('Location');
});

it('allows an object to reclaim its own previous name without redirect cycles', function (): void {
    $category = reviewAliasCategory();
    $article = reviewAliasArticle($category);
    $category = reviewAliasCategory('middle-category', existing: $category);
    $article = reviewAliasArticle($category, 'middle-article', $article);
    $category = reviewAliasCategory('original-category', existing: $category);
    reviewAliasArticle($category, 'original-article', $article);

    $this->get('/help/original-category/original-article')->assertOk()->assertHeaderMissing('Location');
    $this->get('/help/middle-category/middle-article')->assertStatus(301)
        ->assertRedirect('https://canonical.example.test/help/original-category/original-article')->assertHeader('Cache-Control', 'no-store, private');
    $this->get('/help/middle-category')->assertStatus(301)->assertRedirect('https://canonical.example.test/help/original-category')
        ->assertHeader('Cache-Control', 'no-store, private');
    expect(DB::table('cms_category_slug_aliases')->count())->toBe(2)->and(DB::table('cms_article_slug_aliases')->count())->toBe(2);
});

it('returns 404 for every old and current URL when its destination is not public', function (string $state): void {
    $category = reviewAliasCategory();
    $article = reviewAliasArticle($category);
    $category = reviewAliasCategory('final-category', existing: $category);
    $article = reviewAliasArticle($category, 'final-article', $article);
    match ($state) {
        'hidden-category' => $category->update(['is_hidden' => true]),
        'draft' => $article->update(['is_published' => false, 'published_at' => null]),
        'withdrawn' => $article->update(['is_published' => false]),
        'undated' => $article->update(['published_at' => null]),
        'future' => $article->update(['published_at' => now()->addHour()]),
    };

    foreach (['original-category', 'final-category'] as $categorySlug) {
        foreach (['original-article', 'final-article'] as $articleSlug) {
            foreach (['', '.md'] as $suffix) {
                $this->get('/help/'.$categorySlug.'/'.$articleSlug.$suffix)->assertNotFound()->assertHeaderMissing('Location');
            }
        }
    }
    if ($state === 'hidden-category') {
        $this->get('/help/original-category')->assertNotFound()->assertHeaderMissing('Location');
        $this->get('/help/final-category')->assertNotFound()->assertHeaderMissing('Location');
    }
})->with(['hidden-category', 'draft', 'withdrawn', 'undated', 'future']);

it('exports only canonical current addresses in listings sitemap and llms', function (): void {
    $category = reviewAliasCategory();
    $article = reviewAliasArticle($category);
    $category = reviewAliasCategory('final-category', existing: $category);
    reviewAliasArticle($category, 'final-article', $article);

    foreach (['/help', '/sitemap.xml', '/llms.txt'] as $path) {
        $this->get($path)->assertOk()->assertSee('/help/final-category/final-article')
            ->assertDontSee('/help/original-category')->assertDontSee('/original-article');
    }
    $this->get('/sitemap.xml')->assertSee('<loc>https://canonical.example.test/help/final-category</loc>', false);
    $this->get('/llms.txt')->assertSee('/help/final-category/final-article.md');
});

it('rolls back canonical and alias changes together when the audit write fails', function (string $kind): void {
    $category = reviewAliasCategory();
    $article = reviewAliasArticle($category);
    $beforeAudits = DB::table('audit_logs')->count();
    $rejectAudit = true;
    DB::connection()->beforeExecuting(function (string $query) use (&$rejectAudit): void {
        if ($rejectAudit && str_starts_with(strtolower($query), 'insert into') && str_contains($query, 'audit_logs')) {
            throw new RuntimeException('Isolated audit failure');
        }
    });

    try {
        expect(fn () => $kind === 'category' ? reviewAliasCategory('failed-category', existing: $category)
            : reviewAliasArticle($category, 'failed-article', $article))->toThrow(RuntimeException::class, 'Isolated audit failure');
    } finally {
        $rejectAudit = false;
    }
    expect($category->fresh()->slug)->toBe('original-category')->and($article->fresh()->slug)->toBe('original-article')
        ->and(DB::table('audit_logs')->count())->toBe($beforeAudits)
        ->and(DB::table('cms_category_slug_aliases')->count())->toBe(1)
        ->and(DB::table('cms_article_slug_aliases')->count())->toBe(1);
    $this->get('/help/original-category/original-article')->assertOk();
    $this->get('/help/failed-category/original-article')->assertNotFound();
    $this->get('/help/original-category/failed-article')->assertNotFound();
})->with(['category', 'article']);
