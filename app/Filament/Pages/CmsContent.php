<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\CmsArticle;
use App\Models\CmsCategory;
use App\Models\Product;
use Filament\Pages\Page;

class CmsContent extends Page
{
    protected static ?string $slug = 'content';

    protected static ?string $title = '商城与内容管理';

    protected string $view = 'filament.pages.cms-content';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('cms.view') ?? false;
    }

    protected function getViewData(): array
    {
        return ['categories' => CmsCategory::orderBy('type')->orderBy('sort_order')->get(),
            'articles' => CmsArticle::with('category')->latest('id')->paginate(20),
            'products' => Product::with('category')->orderBy('id')->paginate(20, ['*'], 'products_page'),
            'editArticle' => request('article') ? CmsArticle::findOrFail((int) request('article')) : null,
            'editCategory' => request('category') ? CmsCategory::findOrFail((int) request('category')) : null,
            'editProduct' => request('product') ? Product::findOrFail((int) request('product')) : null];
    }
}
