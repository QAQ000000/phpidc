<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\DriverType;
use App\Models\Product;
use App\Models\UpstreamApi;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class UpstreamAccounts extends Page
{
    protected static ?string $slug = 'upstreams';

    protected static ?string $title = '上游商品与连接';

    protected string $view = 'filament.pages.upstream-accounts';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('upstream.view') ?? false;
    }

    protected function getViewData(): array
    {
        $validator = validator(request()->query(), [
            'q' => ['nullable', 'string', 'max:100'],
            'account_id' => ['nullable', 'integer', 'min:1'],
            'visibility' => ['nullable', 'in:visible,hidden'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        // Livewire treats ValidationException as form state; invalid GET filters need an HTTP response.
        abort_if($validator->fails(), 422, '商品筛选参数无效。');
        $data = $validator->validated();
        $filters = [
            'q' => (string) ($data['q'] ?? ''),
            'account_id' => (string) ($data['account_id'] ?? ''),
            'visibility' => (string) ($data['visibility'] ?? ''),
        ];
        $query = array_filter($filters, fn (string $value): bool => $value !== '');
        $products = Product::where('driver_type', DriverType::ZJMF_API)
            ->select(['id', 'name', 'upstream_api_id', 'upstream_pid', 'upstream_version', 'currency',
                'is_hidden', 'local_prices', 'markup_percent', 'stock_control', 'qty', 'updated_at'])
            ->when($filters['q'] !== '', function (Builder $products) use ($filters): void {
                $products->where(function (Builder $search) use ($filters): void {
                    $search->where('name', 'like', '%'.$filters['q'].'%');
                    if (ctype_digit($filters['q'])) {
                        $search->orWhere('id', $filters['q'])->orWhere('upstream_pid', $filters['q']);
                    }
                });
            })
            ->when($filters['account_id'] !== '', fn (Builder $products) => $products->where('upstream_api_id', $filters['account_id']))
            ->when($filters['visibility'] !== '', fn (Builder $products) => $products->where('is_hidden', $filters['visibility'] === 'hidden'))
            ->withCount(['optionGroups as option_group_count'])->orderByDesc('id')->paginate(20)->appends($query);
        $selectedProduct = null;
        $selectedId = request()->query('product');
        if ($selectedId !== null && $selectedId !== '') {
            $selectedKey = filter_var($selectedId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            abort_if($selectedKey === false, 404);
            $selectedProduct = Product::where('driver_type', DriverType::ZJMF_API)->with(['optionGroups.items'])->findOrFail((int) $selectedKey);
        }
        if (($data['page'] ?? 1) > 1) {
            $query['page'] = (int) $data['page'];
        }
        $previewIds = $products->pluck('id')->all();
        if ($selectedProduct !== null) {
            $previewIds[] = $selectedProduct->id;
        }

        return ['accounts' => UpstreamApi::where('driver', DriverType::ZJMF_API)->get(['id', 'name', 'username', 'base_url', 'is_active', 'auth_header_mode', 'config'])
            ->map(fn (UpstreamApi $account): object => (object) ($account->only(['id', 'name', 'username', 'base_url', 'is_active', 'auth_header_mode'])
                + ['allow_credit_limit' => (bool) ($account->config['allow_credit_limit'] ?? false)])),
            'sub2Accounts' => UpstreamApi::where('driver', DriverType::SUB2API)->select(['id', 'name', 'base_url', 'is_active'])
                ->selectRaw("CASE WHEN encrypted_secret IS NOT NULL AND encrypted_secret <> '' THEN 1 ELSE 0 END AS has_secret")->get()
                ->map(fn (UpstreamApi $account): object => (object) ($account->only(['id', 'name', 'base_url', 'is_active'])
                    + ['has_secret' => (bool) $account->getAttribute('has_secret')])),
            'products' => $products, 'filters' => $filters, 'selectedProduct' => $selectedProduct,
            'previewableProductIds' => Product::publicCatalog()->whereKey($previewIds)->pluck('id')->all(),
            'detailReturnUrl' => '/admin/upstreams'.($query === [] ? '' : '?'.http_build_query($query))];
    }
}
