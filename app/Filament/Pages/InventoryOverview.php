<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\InventoryQueries;

class InventoryOverview extends InventoryPage
{
    protected static ?string $slug = 'inventory';

    protected static ?string $title = '库存概览';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.inventory-overview';

    protected function getViewData(): array
    {
        $queries = app(InventoryQueries::class);
        $pending = $queries->tasks()->reorder()->select('p.id')->selectRaw('count(*) as waiting_count')->groupBy('p.id')->pluck('waiting_count', 'p.id');

        return ['products' => $queries->products()->orderBy('id')->paginate(20)->withQueryString(), 'pending' => $pending];
    }
}
