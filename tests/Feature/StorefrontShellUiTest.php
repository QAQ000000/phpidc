<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\CmsArticle;
use App\Models\CmsCategory;
use App\Models\Product;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Http::preventStrayRequests();
    Queue::fake();
});

afterEach(function (): void {
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

function storefrontShellXpath(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

    return new DOMXPath($document);
}

function storefrontShellArticle(array $attributes = []): CmsArticle
{
    $category = CmsCategory::firstOrCreate(['type' => 'help', 'slug' => 'shell-guides'], ['name' => '选购指南']);

    return CmsArticle::create($attributes + ['cms_category_id' => $category->id, 'title' => '商城选购指南',
        'slug' => 'shell-article', 'body' => "## 选购前确认\n\n这里是可公开阅读的商品指南。", 'content_format' => 'markdown',
        'is_published' => true, 'published_at' => now()]);
}

it('shares configured branding and guest navigation across the public pages without scripts', function (string $path): void {
    $settings = ['name' => '星云 <主机>', 'logo_url' => '/images/storefront-logo.svg', 'support_url' => '/help/support',
        'copyright' => '版权所有 <商店>', 'icp_number' => '测试ICP备123号', 'announcement' => '平台公告 <维护安排>'];
    foreach ($settings as $key => $value) {
        DB::table('system_settings')->insert(['group' => 'site', 'key' => $key, 'value' => $value,
            'is_encrypted' => false, 'created_at' => now(), 'updated_at' => now()]);
    }

    $response = $this->get($path)->assertOk()->assertSee('星云 &lt;主机&gt;', false)
        ->assertSee('版权所有 &lt;商店&gt;', false)->assertSee('平台公告 &lt;维护安排&gt;', false);
    $xpath = storefrontShellXpath($response->getContent());
    expect($xpath->query('//header//a[@href="/"]//img[@src="/images/storefront-logo.svg"]'))->toHaveCount(1)
        ->and($xpath->query('//nav[@aria-label="主导航"]'))->toHaveCount(1)
        ->and($xpath->query('//nav[@aria-label="主导航"]//a[@href="/login"]'))->toHaveCount(1)
        ->and($xpath->query('//nav[@aria-label="主导航"]//a[@href="/console"]'))->toHaveCount(0)
        ->and($xpath->query('//nav[@aria-label="主导航"]//a[@href="/help/support"]'))->toHaveCount(1)
        ->and($xpath->query('//main'))->toHaveCount(1)
        ->and($xpath->query('//aside[@role="status"]'))->toHaveCount(1)
        ->and($xpath->query('//footer//a[@href="https://beian.miit.gov.cn/"]'))->toHaveCount(1)
        ->and($xpath->query('//footer//a[@href="/sitemap.xml"]'))->toHaveCount(1)
        ->and($xpath->query('//footer//a[@href="/llms.txt"]'))->toHaveCount(1)
        ->and($xpath->query('//link[@rel="stylesheet"][starts-with(@href,"/css/storefront.css")]'))->toHaveCount(1)
        ->and($xpath->query('//link[@rel="stylesheet"][starts-with(@href,"http") or starts-with(@href,"//")]'))->toHaveCount(0)
        ->and($xpath->query('//script'))->toHaveCount(0);
})->with(['/', '/store', '/news', '/help']);

it('offers the signed in customer center through the same public navigation', function (): void {
    $response = $this->actingAs(User::factory()->create())->get('/store')->assertOk();
    $xpath = storefrontShellXpath($response->getContent());
    expect($xpath->query('//nav[@aria-label="主导航"]'))->toHaveCount(1)
        ->and($xpath->query('//nav[@aria-label="主导航"]//a[@href="/console"]'))->toHaveCount(1)
        ->and($xpath->query('//nav[@aria-label="主导航"]//a[@href="/login"]'))->toHaveCount(0)
        ->and($xpath->query('//aside[@id="console-sidebar"]'))->toHaveCount(0)
        ->and($xpath->query('//form[@action="/logout"]'))->toHaveCount(0);
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
});

it('preserves escaped SEO and Markdown alternate metadata on a script free public article', function (): void {
    $article = storefrontShellArticle(['seo_title' => '云服务 "指南" <发布>', 'seo_description' => '说明 "逐字" <标签>',
        'seo_keywords' => '云产品, "指南", <关键字>']);
    $canonical = rtrim(config('app.url'), '/').$article->publicPath();
    $response = $this->get($article->publicPath().'?campaign=ui-check')->assertOk()
        ->assertHeader('Vary', 'Accept')->assertHeader('X-Content-Type-Options', 'nosniff');
    $xpath = storefrontShellXpath($response->getContent());
    expect($xpath->query('//head/title')->item(0)->textContent)->toBe($article->seo_title.' · '.config('app.name'))
        ->and($xpath->query('//meta[@name="description"]')->item(0)->getAttribute('content'))->toBe($article->seo_description)
        ->and($xpath->query('//meta[@name="keywords"]')->item(0)->getAttribute('content'))->toBe($article->seo_keywords)
        ->and($xpath->query('//link[@rel="canonical"]')->item(0)->getAttribute('href'))->toBe($canonical)
        ->and($xpath->query('//meta[@property="og:title"]')->item(0)->getAttribute('content'))->toBe($article->seo_title)
        ->and($xpath->query('//meta[@property="og:description"]')->item(0)->getAttribute('content'))->toBe($article->seo_description)
        ->and($xpath->query('//meta[@property="og:url"]')->item(0)->getAttribute('content'))->toBe($canonical)
        ->and($xpath->query('//meta[@property="og:type"]')->item(0)->getAttribute('content'))->toBe('article')
        ->and($xpath->query('//link[@rel="alternate"][@type="text/markdown"]')->item(0)->getAttribute('href'))->toBe($canonical.'.md')
        ->and($xpath->query('//meta[@name="robots"]'))->toHaveCount(0)
        ->and($xpath->query('//script'))->toHaveCount(0);
    $response->assertSee('&lt;发布&gt;', false)->assertSee('&lt;标签&gt;', false)->assertDontSee('<标签>', false);
});

it('keeps search pages noindex with a query free canonical while plain indexes stay indexable', function (): void {
    storefrontShellArticle();
    $response = $this->get('/help/shell-guides?q=商城&campaign=ui-check')->assertOk();
    $xpath = storefrontShellXpath($response->getContent());
    expect($xpath->query('//meta[@name="robots"]')->item(0)->getAttribute('content'))->toBe('noindex,follow')
        ->and($xpath->query('//link[@rel="canonical"]')->item(0)->getAttribute('href'))->toBe(rtrim(config('app.url'), '/').'/help/shell-guides');
    $plain = storefrontShellXpath($this->get('/help/shell-guides')->assertOk()->getContent());
    expect($plain->query('//meta[@name="robots"]'))->toHaveCount(0);
});

it('keeps negotiated Markdown independent from the HTML storefront shell', function (string $resource): void {
    $record = $resource === 'article' ? storefrontShellArticle() : Product::factory()->create([
        'name' => '可公开的本地卡密', 'local_prices' => ['onetime' => ['price' => '12.34', 'setup_fee' => '0.20', 'enabled' => true]],
    ]);
    $path = $record->publicPath();
    $canonical = rtrim(config('app.url'), '/').$path;
    $suffix = $this->get($path.'.md')->assertOk()->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')
        ->assertHeader('Vary', 'Accept')->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Link', '<'.$canonical.'>; rel="canonical"');
    $negotiated = $this->get($path, ['Accept' => 'text/markdown'])->assertOk()
        ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')->assertHeader('Vary', 'Accept')
        ->assertHeader('Link', '<'.$canonical.'>; rel="canonical"');
    expect($suffix->getContent())->toBe($negotiated->getContent())->toStartWith('# ');
    foreach (['<!doctype', '<html', '<head', '<nav', '<script', 'storefront.css', 'customer-console.css'] as $shellMarkup) {
        $suffix->assertDontSee($shellMarkup, false);
    }
})->with(['article', 'product']);

it('continues to exclude a hidden product or category from every public representation', function (string $state): void {
    $product = Product::factory()->create(['name' => 'PRIVATE-SHELL-PRODUCT', 'slug' => 'private-shell-product']);
    $path = $product->publicPath();
    if ($state === 'product') {
        $product->update(['is_hidden' => true]);
    } else {
        $product->category->update(['is_hidden' => true]);
        $this->get('/store/'.$product->category->slug)->assertNotFound();
    }
    $this->get($path)->assertNotFound();
    $this->get($path.'.md')->assertNotFound();
    $this->get($path, ['Accept' => 'text/markdown'])->assertNotFound();
    foreach (['/', '/store', '/sitemap.xml', '/llms.txt'] as $index) {
        $this->get($index)->assertOk()->assertDontSee('PRIVATE-SHELL-PRODUCT')->assertDontSee('private-shell-product');
    }
})->with(['product', 'category']);

it('renders useful previous and next links for the selected catalog without losing query parameters', function (): void {
    $category = Category::factory()->create(['slug' => 'shell-cards']);
    Product::factory()->count(25)->for($category)->create();
    $response = $this->get('/store/shell-cards?campaign=ui-check&page=2')->assertOk();
    $xpath = storefrontShellXpath($response->getContent());
    $nav = '//nav[@aria-label="分页"]';
    expect($xpath->query($nav))->toHaveCount(1)
        ->and($xpath->query($nav.'//a[@rel="prev"]'))->toHaveCount(1)
        ->and($xpath->query($nav.'//a[@rel="next"]'))->toHaveCount(1)
        ->and($xpath->query($nav.'//svg'))->toHaveCount(0)
        ->and($xpath->query($nav)->item(0)->textContent)->toContain('第 2 / 3 页');
    foreach (['prev' => '1', 'next' => '3'] as $relation => $page) {
        $href = $xpath->query($nav.'//a[@rel="'.$relation.'"]')->item(0)->getAttribute('href');
        parse_str(parse_url($href, PHP_URL_QUERY), $query);
        expect(parse_url($href, PHP_URL_PATH))->toBe('/store/shell-cards')
            ->and($query)->toBe(['campaign' => 'ui-check', 'page' => $page]);
    }
});

it('preserves CMS search and current category in the shared lightweight pagination', function (): void {
    for ($index = 0; $index < 13; $index++) {
        storefrontShellArticle(['slug' => 'shell-guide-'.$index, 'title' => '商城指南 '.$index]);
    }
    $response = $this->get('/help/shell-guides?q=商城&campaign=ui-check')->assertOk();
    $xpath = storefrontShellXpath($response->getContent());
    $nav = '//nav[@aria-label="分页"]';
    expect($xpath->query($nav))->toHaveCount(1)
        ->and($xpath->query($nav.'//*[@aria-disabled="true"]'))->toHaveCount(1)
        ->and($xpath->query($nav.'//a[@rel="prev"]'))->toHaveCount(0)
        ->and($xpath->query($nav.'//a[@rel="next"]'))->toHaveCount(1);
    $href = $xpath->query($nav.'//a[@rel="next"]')->item(0)->getAttribute('href');
    parse_str(parse_url($href, PHP_URL_QUERY), $query);
    expect(parse_url($href, PHP_URL_PATH))->toBe('/help/shell-guides')
        ->and($query)->toBe(['q' => '商城', 'campaign' => 'ui-check', 'page' => '2']);
});

it('respects an independent paginator name and disables only the unavailable boundary link', function (int $page): void {
    $paginator = new LengthAwarePaginator(collect(range(1, 4)), 10, 4, $page,
        ['path' => '/store/cards', 'pageName' => 'catalog_page', 'query' => ['campaign' => 'ui-check', 'news_page' => 3]]);
    $xpath = storefrontShellXpath(view('public.pagination', ['paginator' => $paginator])->render());
    $nav = '//nav[@aria-label="分页"]';
    expect($xpath->query($nav))->toHaveCount(1)
        ->and($xpath->query($nav.'//*[@aria-disabled="true"]'))->toHaveCount(1)
        ->and($xpath->query($nav.'//a'))->toHaveCount(1);
    $relation = $page === 1 ? 'next' : 'prev';
    $href = $xpath->query($nav.'//a[@rel="'.$relation.'"]')->item(0)->getAttribute('href');
    parse_str(parse_url($href, PHP_URL_QUERY), $query);
    expect($query)->toBe(['campaign' => 'ui-check', 'news_page' => '3', 'catalog_page' => '2']);
})->with([1, 3]);
