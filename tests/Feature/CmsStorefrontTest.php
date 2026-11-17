<?php

declare(strict_types=1);

use App\Actions\CreateComboInvoiceAction;
use App\Actions\CreateLocalCardProductAction;
use App\Actions\ImportRedemptionCodesAction;
use App\Actions\SaveCmsArticleAction;
use App\Actions\SaveCmsCategoryAction;
use App\Enums\BillingCycle;
use App\Enums\DriverType;
use App\Models\Category;
use App\Models\CmsArticle;
use App\Models\CmsCategory;
use App\Models\Product;
use App\Models\UpstreamApi;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);
beforeEach(fn () => $this->installApplicationForTest());

function publicTestArticle(array $attributes = [], ?CmsCategory $category = null): CmsArticle
{
    $category ??= CmsCategory::firstOrCreate(['type' => 'help', 'slug' => 'guides'], ['name' => '产品指南']);

    return CmsArticle::create($attributes + ['cms_category_id' => $category->id, 'title' => '使用指南', 'slug' => Str::lower(Str::random(12)),
        'body' => '## 开始使用'."\n\n".'这是公开的使用方法。', 'content_format' => 'markdown', 'is_published' => true, 'published_at' => now()]);
}

it('serves useful empty public pages while retaining the installer gate', function (): void {
    foreach (['/', '/store', '/news', '/help', '/sitemap.xml', '/llms.txt'] as $path) {
        $this->get($path)->assertOk();
    }
    $this->get('/store')->assertSee('暂无上架产品');
    unlink(storage_path('installed.lock'));
    foreach (['/store', '/help', '/sitemap.xml', '/llms.txt'] as $path) {
        $this->get($path)->assertRedirect('/install');
    }
});

it('resolves article slugs within both their category and category type', function (): void {
    $help = publicTestArticle(['slug' => 'same-slug', 'title' => 'Help only']);
    $newsCategory = CmsCategory::create(['type' => 'news', 'slug' => 'guides', 'name' => '动态']);
    $news = publicTestArticle(['slug' => 'same-slug', 'title' => 'News only'], $newsCategory);
    $otherCategory = CmsCategory::create(['type' => 'help', 'slug' => 'other', 'name' => 'Other']);
    $this->get($help->publicPath())->assertOk()->assertSee('Help only')->assertDontSee('News only');
    $this->get($news->publicPath())->assertOk()->assertSee('News only')->assertDontSee('Help only');
    $this->get($otherCategory->publicPath().'/same-slug')->assertNotFound();
    $this->get($otherCategory->publicPath().'/same-slug.md')->assertNotFound();
    $this->get('/invalid/guides/same-slug')->assertNotFound();
});

it('excludes nonpublic articles from HTML, Markdown, indexes, sitemap and llms', function (string $state): void {
    $article = publicTestArticle(['title' => 'PRIVATE-CONTENT', 'slug' => 'private-content']);
    match ($state) {
        'draft' => $article->update(['is_published' => false]),
        'future' => $article->update(['published_at' => now()->addDay()]),
        'undated' => $article->update(['published_at' => null]),
        'hidden-category' => $article->category->update(['is_hidden' => true]),
    };
    $this->get($article->publicPath())->assertNotFound();
    $this->get($article->publicPath().'.md')->assertNotFound();
    $this->get($article->publicPath(), ['Accept' => 'text/markdown'])->assertNotFound();
    foreach (['/', '/help', '/sitemap.xml', '/llms.txt'] as $path) {
        $this->get($path)->assertOk()->assertDontSee('PRIVATE-CONTENT')->assertDontSee('private-content');
    }
})->with(['draft', 'future', 'undated', 'hidden-category']);

it('uses explicit audited publication commands and can schedule or withdraw the same article', function (): void {
    $this->artisan('cms:category', ['type' => 'news', 'slug' => 'updates', 'name' => '平台动态', '--reason' => 'Create news category'])->assertSuccessful();
    $category = CmsCategory::sole();
    $file = storage_path('article.md');
    file_put_contents($file, "## Release\n\nA published release.");
    $args = ['category' => $category->id, 'slug' => 'release', 'title' => 'Release', 'file' => $file, '--reason' => 'Editorial review'];
    $this->artisan('cms:article', $args)->assertSuccessful();
    $article = CmsArticle::sole();
    $this->get($article->publicPath())->assertNotFound();
    $this->artisan('cms:article', $args + ['--publish' => true])->assertSuccessful();
    $this->get($article->publicPath())->assertOk()->assertSee('A published release.');
    $this->artisan('cms:article', $args + ['--publish' => true, '--publish-at' => now()->addHour()->toDateTimeString()])->assertSuccessful();
    $this->get($article->publicPath())->assertNotFound();
    $this->travel(61)->minutes();
    $this->get($article->publicPath())->assertOk();
    $this->artisan('cms:article', $args)->assertSuccessful();
    $this->get($article->publicPath())->assertNotFound();
    expect(CmsArticle::count())->toBe(1);
    $this->assertDatabaseHas('audit_logs', ['action' => 'cms.article_save', 'reason' => 'Editorial review', 'actor_type' => 'system']);
    expect(fn () => app(SaveCmsCategoryAction::class)->execute(['type' => 'news', 'slug' => 'unsafe', 'name' => 'No reason', 'reason' => 'forged'], ' '))
        ->toThrow(ValidationException::class);
});

it('sanitizes HTML and Markdown before browser rendering', function (string $format): void {
    $body = $format === 'html' ? '<h2>Safe heading</h2><script>alert(1)</script><img src="x" onerror="alert(2)"><a href="javascript:alert(3)">unsafe</a><iframe src="https://bad.test"></iframe>'
        : "## Safe heading\n\n<script>alert(1)</script>\n\n[unsafe](javascript:alert(3))\n\n<img src=x onerror=alert(2)>";
    $article = publicTestArticle(['body' => $body, 'content_format' => $format]);
    $response = $this->get($article->publicPath())->assertOk()->assertSee('<h2>Safe heading</h2>', false);
    foreach (['<script', 'onerror=', 'href="javascript:', '<iframe'] as $unsafe) {
        $response->assertDontSee($unsafe, false);
    }
    if ($format === 'html') {
        $this->get($article->publicPath().'.md')->assertOk()->assertDontSee('<script', false)->assertDontSee('onerror=', false);
    }
})->with(['html', 'markdown']);

it('normalizes a scheduled publication timezone before comparing its visibility', function (): void {
    $this->travelTo(Carbon::parse('2026-11-17T08:00:00+08:00'));
    $category = CmsCategory::create(['type' => 'news', 'slug' => 'timezone', 'name' => 'News']);
    $article = app(SaveCmsArticleAction::class)->execute($category, ['slug' => 'scheduled', 'title' => 'Scheduled',
        'body' => 'Scheduled content', 'content_format' => 'markdown', 'is_published' => true,
        'published_at' => '2026-11-17T00:30:00Z'], 'Schedule publication');
    $this->get($article->publicPath())->assertNotFound();
    $this->travel(31)->minutes();
    $this->get($article->publicPath())->assertOk()->assertSee('Scheduled content');
});

it('negotiates Markdown without losing canonical URLs, visibility or SEO escaping', function (): void {
    DB::table('system_settings')->insert(['group' => 'site', 'key' => 'name', 'value' => 'Public Site', 'is_encrypted' => false, 'created_at' => now(), 'updated_at' => now()]);
    $article = publicTestArticle(['title' => 'Article title', 'seo_title' => 'Search title', 'seo_description' => '"><script>unsafe</script>', 'slug' => 'export']);
    $canonical = rtrim(config('app.url'), '/').$article->publicPath();
    $html = $this->get($article->publicPath())->assertOk()->assertHeader('Vary', 'Accept');
    $html->assertSee('<title>Search title · Public Site</title>', false)->assertSee('href="'.$canonical.'"', false)->assertDontSee('<script>unsafe</script>', false);
    $suffix = $this->get($article->publicPath().'.md')->assertOk()->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Link', '<'.$canonical.'>; rel="canonical"');
    $accept = $this->get($article->publicPath(), ['Accept' => 'text/markdown'])->assertOk()->assertHeader('Vary', 'Accept');
    expect($suffix->getContent())->toBe($accept->getContent())->toStartWith('# Article title');
});

it('exports valid XML and public Markdown links without accounts, credentials or drafts', function (): void {
    $article = publicTestArticle(['slug' => 'visible', 'title' => 'Visible article']);
    $product = app(CreateLocalCardProductAction::class)->execute('Public card', '12.34', '1.00', 'CNY', 'Publish product');
    $hidden = Product::factory()->hidden()->create(['slug' => 'private-product']);
    publicTestArticle(['slug' => 'draft', 'is_published' => false]);
    $sitemap = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
    $xml = simplexml_load_string($sitemap->getContent());
    expect($xml)->not->toBeFalse();
    $sitemap->assertSee($article->publicPath(), false)->assertSee($product->publicPath(), false)->assertDontSee($hidden->publicPath(), false)->assertDontSee('/draft');
    $llms = $this->get('/llms.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    $llms->assertSee($article->publicPath().'.md', false)->assertSee($product->publicPath().'.md', false)->assertDontSee('/console')->assertDontSee('/draft')->assertDontSee('private-product');
});

it('paginates and searches public article titles', function (): void {
    for ($i = 0; $i < 13; $i++) {
        publicTestArticle(['title' => 'Matching title '.$i]);
    }
    publicTestArticle(['title' => 'Unrelated title']);
    $this->get('/help?q=Matching')->assertOk()->assertViewHas('articles', fn ($articles) => $articles->total() === 13 && $articles->count() === 12)
        ->assertSee('q=Matching', false)->assertDontSee('Unrelated title');
    $this->get('/help?q=Matching&page=2')->assertOk()->assertViewHas('articles', fn ($articles) => $articles->count() === 1);
});

it('shows fixed card prices and cached upstream reference prices without calling a supplier', function (): void {
    Http::preventStrayRequests();
    $local = app(CreateLocalCardProductAction::class)->execute('Fixed card', '12.34', '1.00', 'CNY', 'Publish');
    app(ImportRedemptionCodesAction::class)->execute($local, ['AVAILABLE-CARD'], 'Stock for available product display');
    $this->get($local->publicPath())->assertOk()->assertSee('12.34')->assertSee('1.00')->assertSee('登录 / 注册后购买');
    $account = UpstreamApi::factory()->create();
    $upstream = Product::factory()->create(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id,
        'upstream_pid' => 17, 'local_prices' => ['monthly' => ['price' => '9.60', 'setup_fee' => '0.00', 'enabled' => true]]]);
    $this->get($upstream->publicPath())->assertOk()->assertSee('9.60')->assertSee('参考价格');
    $this->get($upstream->publicPath().'.md')->assertOk()->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')->assertSee('实时确认');
    Http::assertNothingSent();
});

it('rejects hidden categories at checkout before wallet mutation and rejects mismatched product URLs', function (): void {
    $product = app(CreateLocalCardProductAction::class)->execute('Hidden category card', '10.00', '0.00', 'CNY', 'Setup');
    $other = Category::factory()->create();
    $this->get('/store/'.$other->slug.'/'.$product->slug)->assertNotFound();
    $product->category->update(['is_hidden' => true]);
    foreach ([$product->publicPath(), $product->publicPath().'.md'] as $path) {
        $this->get($path)->assertNotFound();
    }
    $user = User::factory()->create(['credit' => '100.00']);
    expect(fn () => app(CreateComboInvoiceAction::class)->execute($user, $product, BillingCycle::ONETIME, [], true))->toThrow(ValidationException::class);
    expect($user->fresh()->credit)->toBe('100.00');
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
});

it('updates product presentation with an audit while preserving billing and instance bindings', function (): void {
    $product = app(CreateLocalCardProductAction::class)->execute('Old name', '10.00', '0.00', 'CNY', 'Setup');
    $file = storage_path('product.txt');
    file_put_contents($file, '<script>not executable</script>Product details');
    $before = $product->only(['driver_type', 'local_prices', 'upstream_api_id']);
    $this->artisan('products:describe', ['product' => $product->id, 'file' => $file, '--name' => 'New name', '--reason' => 'Editorial update'])->assertSuccessful();
    expect($product->fresh()->only(array_keys($before)))->toBe($before);
    $this->get($product->publicPath())->assertOk()->assertSee('New name')->assertDontSee('<script>not executable</script>', false);
    $this->assertDatabaseHas('audit_logs', ['action' => 'product.presentation_update', 'reason' => 'Editorial update']);
});
