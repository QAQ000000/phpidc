<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\DriverType;
use App\Models\Product;
use App\Models\UpstreamApi;
use Filament\Pages\Page;
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
        return ['accounts' => UpstreamApi::where('driver', DriverType::ZJMF_API)->get(['id', 'name', 'username', 'base_url', 'is_active', 'auth_header_mode', 'config'])
            ->map(fn (UpstreamApi $account): object => (object) ($account->only(['id', 'name', 'username', 'base_url', 'is_active', 'auth_header_mode'])
                + ['allow_credit_limit' => (bool) ($account->config['allow_credit_limit'] ?? false)])),
            'sub2Accounts' => UpstreamApi::where('driver', DriverType::SUB2API)->select(['id', 'name', 'base_url', 'is_active'])
                ->selectRaw("CASE WHEN encrypted_secret IS NOT NULL AND encrypted_secret <> '' THEN 1 ELSE 0 END AS has_secret")->get()
                ->map(fn (UpstreamApi $account): object => (object) ($account->only(['id', 'name', 'base_url', 'is_active'])
                    + ['has_secret' => (bool) $account->getAttribute('has_secret')])),
            'products' => Product::where('driver_type', DriverType::ZJMF_API)->with(['optionGroups.items'])->paginate(20)];
    }
}
