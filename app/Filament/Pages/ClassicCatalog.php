<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Http\Controllers\ClassicOperationsController;
use Filament\Pages\Page;
use Filament\Pages\PageConfiguration;
use Filament\Panel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Locked;

class ClassicCatalog extends Page
{
    protected static ?string $slug = 'upstreams/catalog';

    protected static ?string $title = '上游商品目录';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.classic-catalog';

    #[Locked]
    public int $accountId;

    #[Locked]
    public array $groups = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->can('upstream.view') ?? false;
    }

    public static function routes(Panel $panel, ?PageConfiguration $configuration = null): void
    {
        Route::post(static::getRoutePath($panel), [ClassicOperationsController::class, 'catalog'])
            ->middleware(static::getRouteMiddleware($panel))->name(static::getRelativeRouteName($panel));
    }

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'classic-catalog';
    }

    public function mount(int $accountId, array $catalog): void
    {
        $this->accountId = $accountId;
        // Only the fields displayed in this directory enter the Livewire snapshot.
        $this->groups = array_map(fn (array $group): array => [
            'name' => $group['name'] ?? $group['groupname'] ?? '商品组',
            'products' => array_map(fn (array $product): array => [
                'id' => $product['id'], 'name' => $product['name'],
            ], $group['products'] ?? []),
        ], $catalog['products'] ?? []);
    }

    public function getHeading(): string
    {
        return '上游连接 #'.$this->accountId.' 商品目录';
    }

    public function getBreadcrumbs(): array
    {
        return ['/admin/upstreams' => '上游商品与连接', '上游商品目录'];
    }
}
