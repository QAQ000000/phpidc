<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SaveCmsArticleAction;
use App\Actions\SaveCmsCategoryAction;
use App\Actions\UpdateProductPresentationAction;
use App\Models\CmsArticle;
use App\Models\CmsCategory;
use App\Models\Product;
use App\Models\User;
use App\Support\CmsRenderer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class CmsOperationsController extends Controller
{
    public function saveArticle(Request $request, SaveCmsArticleAction $save): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate(['category_id' => ['required', 'integer'], 'id' => ['nullable', 'integer']]);
        $article = $save->execute(CmsCategory::findOrFail($data['category_id']), $request->all(),
            $this->reason($request, isset($data['id']) ? '修改文章' : '创建文章'), $actor, isset($data['id']) ? (int) $data['id'] : null);

        return redirect('/admin/content?article='.$article->id)->with('inventory_status', '文章已保存。');
    }

    public function saveCategory(Request $request, SaveCmsCategoryAction $save): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate(['id' => ['nullable', 'integer']]);
        $category = $save->execute($request->all(), $this->reason($request, isset($data['id']) ? '修改栏目' : '创建栏目'), $actor, isset($data['id']) ? (int) $data['id'] : null);

        return redirect('/admin/content?category='.$category->id)->with('inventory_status', '栏目已保存。');
    }

    public function saveProduct(Request $request, Product $product, UpdateProductPresentationAction $save): RedirectResponse
    {
        $actor = $this->actor($request);
        $save->execute($product, $request->all(), $this->reason($request, '修改商品展示'), $actor);

        return redirect('/admin/content?product='.$product->id)->with('inventory_status', '商品展示已保存。');
    }

    public function preview(Request $request, CmsArticle $article, CmsRenderer $renderer): Response
    {
        $this->actor($request);

        return response()->view('sales.content-preview', ['article' => $article, 'html' => $renderer->html($article)])
            ->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'private, no-store');
    }

    private function reason(Request $request, string $description): string
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $note = trim($data['reason'] ?? '');

        return $note === '' ? $description : $note;
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize('cms.view');

        return $actor;
    }
}
