<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\InventoryQueries;

class FulfillmentQueue extends InventoryPage
{
    protected static ?string $slug = 'fulfillment';

    protected static ?string $title = '已付待交付';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.fulfillment-queue';

    protected function getViewData(): array
    {
        return ['tasks' => app(InventoryQueries::class)->tasks()->orderByDesc('t.id')->paginate(20)->withQueryString()];
    }
}
