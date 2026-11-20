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
        $formError = session()->has('errors') && in_array(old('_admin_form'), ['cms-article', 'cms-category', 'cms-product'], true)
            ? old('_admin_form') : null;
        $recordQueries = ['cms-article' => 'article', 'cms-category' => 'category', 'cms-product' => 'product'];
        $explicitRecords = array_filter($recordQueries, static fn (string $query): bool => request($query) !== null);
        $submittedId = filter_var(old('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        if ($formError !== null && $explicitRecords !== [] && (count($explicitRecords) !== 1
            || ! isset($explicitRecords[$formError]) || request()->integer($explicitRecords[$formError]) !== $submittedId)) {
            $formError = null;
        }
        $selectedId = static function (string $form, string $query) use ($formError): ?int {
            if (request($query)) {
                return request()->integer($query);
            }
            if ($formError === $form) {
                return filter_var(old('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
            }

            return null;
        };
        $articleId = $selectedId('cms-article', 'article');
        $categoryId = $selectedId('cms-category', 'category');
        $productId = $selectedId('cms-product', 'product');

        return ['categories' => CmsCategory::orderBy('type')->orderBy('sort_order')->get(),
            'articles' => CmsArticle::with('category')->latest('id')->paginate(20)->withQueryString(),
            'products' => Product::with('category')->orderBy('id')->paginate(20, ['*'], 'products_page')->withQueryString(),
            'editArticle' => $articleId !== null ? CmsArticle::findOrFail($articleId) : null,
            'editCategory' => $categoryId !== null ? CmsCategory::findOrFail($categoryId) : null,
            'editProduct' => $productId !== null ? Product::findOrFail($productId) : null,
            'formError' => $formError];
    }
}
