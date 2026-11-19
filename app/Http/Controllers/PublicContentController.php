<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\DescribeStoreProductAction;
use App\Enums\CmsArticleType;
use App\Models\Category;
use App\Models\CmsArticle;
use App\Models\CmsCategory;
use App\Models\Product;
use App\Support\CmsRenderer;
use App\Support\CmsSlugAliases;
use App\Support\SitePresentation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\LazyCollection;

class PublicContentController extends Controller
{
    public function __construct(private readonly CmsRenderer $renderer) {}

    public function home(): Response
    {
        $site = app(SitePresentation::class)->values();

        return $this->page('home', ['products' => Product::publicCatalog()->with('category')->orderBy('sort_order')->orderBy('id')->limit(6)->get(),
            'articles' => CmsArticle::published()->with('category')->latest('published_at')->limit(6)->get()], '/', $site['site.seo_title'], $site['site.seo_description']);
    }

    public function store(?string $category = null): Response
    {
        $selected = $category === null ? null : Category::where('slug', $category)->where('is_hidden', false)->firstOrFail();
        $products = Product::publicCatalog()->with('category')->when($selected !== null, fn (Builder $query) => $query->where('category_id', $selected->id));

        return $this->page('store', ['products' => $products->orderBy('sort_order')->orderBy('id')->paginate(12)->withQueryString(), 'selected' => $selected,
            'categories' => Category::where('is_hidden', false)->whereHas('products', fn (Builder $query) => $query->publicCatalog())->orderBy('sort_order')->orderBy('id')->get()],
            '/store'.($selected === null ? '' : '/'.rawurlencode($selected->slug)), $selected->name ?? '全部产品', $selected->description ?? '浏览云服务、充值卡与数字产品。');
    }

    public function product(Request $request, string $category, string $product, DescribeStoreProductAction $describe): Response
    {
        $item = Product::publicCatalog()->with('category')->where('slug', $product)->whereHas('category', fn (Builder $query) => $query->where('slug', $category))->firstOrFail();
        $details = $describe->execute($item);
        if ($request->attributes->get('markdown')) {
            $body = '# '.$item->name."\n\n".$item->description."\n\n".($details['live']
                ? '以下为最近同步的商品基础价，配置费用以完整报价为准；价格在创建账单时实时确认。'
                : '一次性卡密产品，库存以实际交付为准。')."\n\n";
            foreach ($details['cycles'] as $cycle) {
                $body .= '- '.$cycle['label'].': '.($cycle['price'] ?? '待报价').' '.$item->currency
                    .($cycle['setup_fee'] === null ? '' : '，开通费 '.$cycle['setup_fee'].' '.$item->currency)."\n";
            }

            if ($details['stock'] !== null) {
                $body .= "\n".($details['live'] ? '最近同步库存：'.$details['stock'] : '当前可用卡密：'.$details['stock'].' 张')."\n";
            }
            $unavailable = $details['purchase_unavailable'];
            if ($unavailable === null && $details['cycles'] === []) {
                $unavailable = '暂无可购买的计费周期。';
            }
            if ($unavailable === null && $details['stock'] === 0) {
                $unavailable = $details['live'] ? '最近同步显示售罄，请稍后再来。' : '暂时售罄，请稍后再来。';
            }
            if ($unavailable !== null) {
                $body .= "\n".$unavailable."\n";
            }
            $linkLabel = $unavailable === null ? '查看产品与购买' : '查看产品详情';

            return $this->markdown($body."\n[".$linkLabel.']('.$this->canonical($item->publicPath()).")\n", $item->publicPath());
        }

        return $this->page('product', ['product' => $item] + $details, $item->publicPath(), $item->name, $this->renderer->summary($item->description ?? ''))
            ->header('Cache-Control', 'private, no-store');
    }

    public function articles(Request $request, string $type, ?string $category = null): Response|RedirectResponse
    {
        $kind = CmsArticleType::from($type);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $search = trim($data['q'] ?? '');
        $categories = CmsCategory::visible()->where('type', $kind)->orderBy('sort_order')->orderBy('id');
        $selected = $category === null ? null : app(CmsSlugAliases::class)->category($kind, $category);
        if ($selected !== null && $selected->slug !== $category) {
            return $this->redirectCms($request, $selected->publicPath());
        }
        $articles = CmsArticle::published()->with('category')->whereHas('category', fn (Builder $query) => $query->where('type', $kind))
            ->when($selected !== null, fn (Builder $query) => $query->where('cms_category_id', $selected->id))
            ->when($search !== '', fn (Builder $query) => $query->where('title', 'like', '%'.$search.'%'));

        return $this->page('articles', ['articles' => $articles->orderBy('sort_order')->latest('published_at')->orderBy('id')->paginate(12)->withQueryString(),
            'categories' => $categories->get(), 'selected' => $selected, 'kind' => $kind, 'search' => $search, 'noindex' => $search !== ''],
            $selected?->publicPath() ?? $kind->routePrefix(), $selected->seo_title ?? $selected->name ?? ($kind === CmsArticleType::NEWS ? '新闻动态' : '帮助中心'),
            $selected->seo_description ?? '产品动态与使用指南。', $selected?->seo_keywords);
    }

    public function article(Request $request, string $type, string $category, string $article): Response|RedirectResponse
    {
        $aliases = app(CmsSlugAliases::class);
        $selected = $aliases->category(CmsArticleType::from($type), $category);
        $item = $aliases->article($selected, $article);
        if ($item->category->slug !== $category || $item->slug !== $article) {
            $path = $item->publicPath().(str_ends_with($request->path(), '.md') ? '.md' : '');

            return $this->redirectCms($request, $path);
        }
        if ($request->attributes->get('markdown')) {
            return $this->markdown($this->renderer->markdown($item), $item->publicPath());
        }

        $html = $this->renderer->html($item);

        return $this->page('article', ['article' => $item, 'body' => $html], $item->publicPath(),
            $item->seo_title ?? $item->title, $item->seo_description ?? $this->renderer->excerpt($item, $html), $item->seo_keywords);
    }

    public function sitemap(): Response
    {
        return response()->view('public.sitemap', ['entries' => $this->sitemapEntries()])->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    private function sitemapEntries(): \Generator
    {
        foreach (['/', '/store', '/news', '/help'] as $path) {
            yield ['url' => $this->canonical($path), 'modified' => null];
        }
        foreach (Category::where('is_hidden', false)->whereHas('products', fn (Builder $query) => $query->publicCatalog())
            ->select(['id', 'slug', 'updated_at'])->lazyById(100) as $category) {
            yield ['url' => $this->canonical('/store/'.rawurlencode($category->slug)), 'modified' => $category->updated_at?->toAtomString()];
        }
        foreach ([$this->indexProducts(), CmsCategory::visible()->select(['id', 'type', 'slug', 'updated_at'])->lazyById(100), $this->indexArticles()] as $items) {
            foreach ($items as $item) {
                yield ['url' => $this->canonical($item->publicPath()), 'modified' => $item->updated_at?->toAtomString()];
            }
        }
    }

    private function indexProducts(): LazyCollection
    {
        return Product::publicCatalog()->select(['id', 'category_id', 'slug', 'name', 'updated_at'])
            ->with('category:id,slug')->lazyById(100);
    }

    private function indexArticles(): LazyCollection
    {
        return CmsArticle::published()->select(['id', 'cms_category_id', 'slug', 'title', 'updated_at'])
            ->with('category:id,type,slug')->lazyById(100);
    }

    public function llms(): Response
    {
        $site = app(SitePresentation::class)->values();
        $body = '# '.$site['site.name']."\n\n".$site['site.llms_description']."\n\n## 导航\n\n";
        foreach (['/store' => '产品', '/news' => '新闻', '/help' => '帮助'] as $path => $label) {
            $body .= '- ['.$label.']('.$this->canonical($path).")\n";
        }
        foreach (['产品' => $this->indexProducts(), '文章' => $this->indexArticles()] as $section => $items) {
            $body .= "\n## ".$section."\n\n";
            foreach ($items as $item) {
                $body .= '- '.str_replace(["\r", "\n"], ' ', $item instanceof Product ? $item->name : $item->title).': '.$this->canonical($item->publicPath()).".md\n";
            }
        }

        return response($body)->header('Content-Type', 'text/plain; charset=UTF-8')->header('X-Content-Type-Options', 'nosniff');
    }

    private function page(string $view, array $data, string $path, string $title, string $description, ?string $keywords = null): Response
    {
        $site = app(SitePresentation::class)->values();

        return response()->view('public.'.$view, $data + ['siteName' => $site['site.name'],
            'title' => trim($title) !== '' ? $title : $site['site.seo_title'],
            'description' => trim($description) !== '' ? $description : $site['site.seo_description'],
            'keywords' => $keywords, 'canonical' => $this->canonical($path)]);
    }

    private function canonical(string $path): string
    {
        return rtrim(config('app.url'), '/').$path;
    }

    private function redirectCms(Request $request, string $path): RedirectResponse
    {
        $query = $request->getQueryString();

        // An object may reclaim an old slug; caching the previous redirect could create a client-side loop.
        return redirect($this->canonical($path).($query === null ? '' : '?'.$query), 301)->header('Cache-Control', 'no-store');
    }

    private function markdown(string $body, string $path): Response
    {
        $site = app(SitePresentation::class)->values();
        $body .= "\n\n---\n\n站点：".$site['site.name']."\n";
        if ($site['site.announcement'] !== '') {
            $body .= "\n站点公告：".$site['site.announcement']."\n";
        }

        return response($body)->header('Content-Type', 'text/markdown; charset=UTF-8')->header('Link', '<'.$this->canonical($path).'>; rel="canonical"');
    }
}
