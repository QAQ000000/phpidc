<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\DriverType;
use App\Jobs\ProbeClassicProductJob;
use App\Models\Product;
use App\Services\SettingService;
use App\Support\FairMaintenanceBatch;
use App\Support\MaintenanceBudget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use LogicException;

class DispatchClassicCatalogAction
{
    public function execute(): int
    {
        $this->assertAsynchronous();

        return Cache::lock('catalog-dispatch', 50)->get(function (): int {
            $limit = min(200, max(1, app(SettingService::class)->getInt('lifecycle.catalog_batch', 100)));
            $base = $this->eligible();
            $cursor = (int) Cache::get('catalog-cursor', 0);
            $products = (clone $base)->where('id', '>', $cursor)->orderBy('id')->limit($limit)->get(['id']);
            if ($products->isEmpty() && $cursor > 0) {
                $products = (clone $base)->orderBy('id')->limit($limit)->get(['id']);
            }
            foreach ($products as $product) {
                $this->dispatch($product->id);
            }
            Cache::forever('catalog-cursor', $products->last()->id ?? 0);

            return $products->count();
        }) ?: 0;
    }

    public function executeMaintenance(MaintenanceBudget $budget): array
    {
        $this->assertAsynchronous();

        return Cache::lock('catalog-dispatch', 50)->get(function () use ($budget): array {
            $limit = min($budget->remaining(), max(1, (int) config('maintenance.limit', 100)),
                min(100, max(1, app(SettingService::class)->getInt('lifecycle.catalog_batch', 100))));

            return app(FairMaintenanceBatch::class)->execute('catalog', $this->eligible(), 'id',
                fn (int $id) => $this->dispatch($id), budget: $budget,
                priorityLimit: intdiv($limit, 2), rotationLimit: $limit - intdiv($limit, 2));
        }) ?: ['selected' => 0, 'attempted' => 0, 'succeeded' => 0, 'failed' => 0, 'units' => 0, 'deferred' => 0, 'budget_exhausted' => false];
    }

    /** @return Builder<Product> */
    private function eligible(): Builder
    {
        return Product::where('driver_type', DriverType::ZJMF_API)
            ->whereHas('upstreamApi', fn ($query) => $query->where('is_active', true));
    }

    private function dispatch(int $id): void
    {
        ProbeClassicProductJob::dispatch($id)->onQueue('catalog');
    }

    private function assertAsynchronous(): void
    {
        if (config('queue.connections.'.config('queue.default').'.driver') === 'sync') {
            throw new LogicException('Catalog dispatch requires an asynchronous queue.');
        }
    }
}
