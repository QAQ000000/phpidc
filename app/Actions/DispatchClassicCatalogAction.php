<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\DriverType;
use App\Jobs\ProbeClassicProductJob;
use App\Models\Product;
use App\Services\SettingService;
use Illuminate\Support\Facades\Cache;
use LogicException;

class DispatchClassicCatalogAction
{
    public function execute(): int
    {
        if (config('queue.connections.'.config('queue.default').'.driver') === 'sync') {
            throw new LogicException('Catalog dispatch requires an asynchronous queue.');
        }

        return Cache::lock('catalog-dispatch', 50)->get(function (): int {
            $limit = min(200, max(1, app(SettingService::class)->getInt('lifecycle.catalog_batch', 100)));
            $base = Product::where('driver_type', DriverType::ZJMF_API)->whereHas('upstreamApi', fn ($query) => $query->where('is_active', true));
            $cursor = (int) Cache::get('catalog-cursor', 0);
            $products = (clone $base)->where('id', '>', $cursor)->orderBy('id')->limit($limit)->get(['id']);
            if ($products->isEmpty() && $cursor > 0) {
                $products = (clone $base)->orderBy('id')->limit($limit)->get(['id']);
            }
            foreach ($products as $product) {
                ProbeClassicProductJob::dispatch($product->id)->onQueue('catalog');
            }
            Cache::forever('catalog-cursor', $products->last()->id ?? 0);

            return $products->count();
        }) ?? 0;
    }
}
