<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Product;
use App\Models\RedemptionCode;
use Illuminate\Database\Eloquent\Builder;

class InventoryCards extends InventoryPage
{
    protected static ?string $slug = 'inventory/cards';

    protected static ?string $title = '卡密库存与补货';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.inventory-cards';

    protected function getViewData(): array
    {
        // Hidden attributes alone do not prevent plaintext in a Livewire snapshot: select metadata only.
        $cards = RedemptionCode::query()->select(['id', 'product_id', 'batch_no', 'status', 'instance_id', 'reserved_until', 'sold_at', 'created_at'])
            ->whereHas('product', fn (Builder $q) => $q->localCardPool()->select('products.id'))
            ->with('product:id,name')->when(request('product_id'), fn (Builder $q, $id) => $q->where('product_id', (int) $id))
            ->when(request('batch'), fn (Builder $q, $batch) => $q->where('batch_no', mb_substr((string) $batch, 0, 50)))
            ->when(in_array(request('status'), ['available', 'reserved', 'sold'], true), fn (Builder $q) => $q->where('status', request('status')));

        return ['cards' => $cards->orderByDesc('id')->paginate(20)->withQueryString(),
            'products' => Product::query()->localCardPool()->select(['id', 'name'])->orderBy('name')->get()];
    }
}
