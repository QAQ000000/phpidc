<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\DescribeStoreProductAction;
use App\Enums\CmsArticleType;
use App\Models\Category;
use App\Models\CmsArticle;
use App\Models\CmsCategory;
use App\Models\Product;
use App\Services\SettingService;
use App\Support\CmsRenderer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PublicContentController extends Controller
{
    public function __construct(private readonly SettingService $settings, private readonly CmsRenderer $renderer) {}

    public function home(): Response
    {
        return $this->page('home', ['products' => Product::publicCatalog()->with('category')->orderBy('sort_order')->orderBy('id')->limit(6)->get(),
            'articles' => CmsArticle::published()->with('category')->latest('published_at')->limit(6)->get()], '/', '云产品与使用指南', '发现云服务、数字产品与实用的产品使用指南。');
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
            $body = '# '.$item->name."\n\n".$item->description."\n\n".($details['live'] ? '价格在创建账单时实时确认。' : '一次性卡密产品，库存以实际交付为准。')."\n\n";
            foreach ($details['cycles'] as $cycle) {
                $body .= '- '.$cycle['label'].': '.($cycle['price'] ?? '待报价').' '.$item->currency
                    .($cycle['setup_fee'] === null ? '' : '，开通费 '.$cycle['setup_fee'].' '.$item->currency)."\n";
            }

            return $this->markdown($body."\n[查看产品与购买](".$this->canonical($item->publicPath()).")\n", $item->publicPath());
        }

        return $this->page('product', ['product' => $item] + $details, $item->publicPath(), $item->name, $this->renderer->summary($item->description ?? ''));
    }

    public function articles(Request $request, string $type, ?string $category = null): Response
    {
        $kind = CmsArticleType::from($type);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $search = trim($data['q'] ?? '');
        $categories = CmsCategory::visible()->where('type', $kind)->orderBy('sort_order')->orderBy('id');
        $selected = $category === null ? null : (clone $categories)->where('slug', $category)->firstOrFail();
        $articles = CmsArticle::published()->with('category')->whereHas('category', fn (Builder $query) => $query->where('type', $kind))
            ->when($selected !== null, fn (Builder $query) => $query->where('cms_category_id', $selected->id))
            ->when($search !== '', fn (Builder $query) => $query->where('title', 'like', '%'.$search.'%'));

        return $this->page('articles', ['articles' => $articles->orderBy('sort_order')->latest('published_at')->orderBy('id')->paginate(12)->withQueryString(),
            'categories' => $categories->get(), 'selected' => $selected, 'kind' => $kind, 'search' => $search, 'noindex' => $search !== ''],
            $selected?->publicPath() ?? $kind->routePrefix(), $selected->seo_title ?? $selected->name ?? ($kind === CmsArticleType::NEWS ? '新闻动态' : '帮助中心'),
            $selected->seo_description ?? '产品动态与使用指南。', $selected?->seo_keywords);
    }

    public function article(Request $request, string $type, string $category, string $article): Response
    {
        $item = CmsArticle::published()->with('category')->where('slug', $article)
            ->whereHas('category', fn (Builder $query) => $query->where('type', CmsArticleType::from($type))->where('slug', $category))->firstOrFail();
        if ($request->attributes->get('markdown')) {
            return $this->markdown($this->renderer->markdown($item), $item->publicPath());
        }

        return $this->page('article', ['article' => $item, 'body' => $this->renderer->html($item)], $item->publicPath(),
            $item->seo_title ?? $item->title, $item->seo_description ?? $this->renderer->excerpt($item), $item->seo_keywords);
    }

    public function sitemap(): Response
    {
        $entries = array_map(fn (string $path) => ['url' => $this->canonical($path), 'modified' => null], ['/', '/store', '/news', '/help']);
        foreach (Category::where('is_hidden', false)->whereHas('products', fn (Builder $query) => $query->publicCatalog())->get() as $category) {
            $entries[] = ['url' => $this->canonical('/store/'.rawurlencode($category->slug)), 'modified' => $category->updated_at?->toAtomString()];
        }
        foreach ([Product::publicCatalog()->with('category')->get(), CmsCategory::visible()->get(), CmsArticle::published()->with('category')->get()] as $items) {
            foreach ($items as $item) {
                $entries[] = ['url' => $this->canonical($item->publicPath()), 'modified' => $item->updated_at?->toAtomString()];
            }
        }

        return response()->view('public.sitemap', ['entries' => $entries])->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function llms(): Response
    {
        $body = '# '.$this->settings->get('site.name', config('app.name'))."\n\n公开产品、新闻与使用指南。价格以创建账单时的服务端报价为准。\n\n## 导航\n\n";
        foreach (['/store' => '产品', '/news' => '新闻', '/help' => '帮助'] as $path => $label) {
            $body .= '- ['.$label.']('.$this->canonical($path).")\n";
        }
        foreach (['产品' => Product::publicCatalog()->with('category')->get(), '文章' => CmsArticle::published()->with('category')->get()] as $section => $items) {
            $body .= "\n## ".$section."\n\n";
            foreach ($items as $item) {
                $body .= '- '.str_replace(["\r", "\n"], ' ', $item instanceof Product ? $item->name : $item->title).': '.$this->canonical($item->publicPath()).".md\n";
            }
        }

        return response($body)->header('Content-Type', 'text/plain; charset=UTF-8')->header('X-Content-Type-Options', 'nosniff');
    }

    private function page(string $view, array $data, string $path, string $title, string $description, ?string $keywords = null): Response
    {
        return response()->view('public.'.$view, $data + ['siteName' => $this->settings->get('site.name', config('app.name')), 'title' => $title,
            'description' => $description, 'keywords' => $keywords, 'canonical' => $this->canonical($path)]);
    }

    private function canonical(string $path): string
    {
        return rtrim(config('app.url'), '/').$path;
    }

    private function markdown(string $body, string $path): Response
    {
        return response($body)->header('Content-Type', 'text/markdown; charset=UTF-8')->header('Link', '<'.$this->canonical($path).'>; rel="canonical"');
    }
}
