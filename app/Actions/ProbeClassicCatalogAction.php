<?php

declare(strict_types=1);

namespace App\Actions;

use App\Contracts\SyncableCatalog;
use App\Enums\DriverType;
use App\Models\Product;
use App\Services\DriverManager;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProbeClassicCatalogAction
{
    public function execute(): int
    {
        $count = 0;
        foreach (Product::where('driver_type', DriverType::ZJMF_API)->with('upstreamApi')->get()->groupBy('upstream_api_id') as $products) {
            $first = $products->first();
            if (! $first->upstreamApi?->is_active) {
                continue;
            }
            try {
                $driver = app(DriverManager::class)->fulfillment($first->driver_type, $first->upstream_api_id);
                if (! $driver instanceof SyncableCatalog) {
                    continue;
                }
                $versions = app(CallUpstreamAction::class)->execute(fn () => $driver->probeVersions($products->pluck('upstream_pid')->all()));
                foreach ($products as $product) {
                    $row = $versions[$product->upstream_pid] ?? null;
                    if ($row === null) {
                        Log::warning('Upstream product missing from probe.', ['product_id' => $product->id]);

                        continue;
                    }
                    if ((int) ($row['location_version'] ?? 0) > $product->upstream_version || ! isset($product->provisioning_rules['classic_catalog'])) {
                        app(SyncClassicCatalogAction::class)->execute($product, 'Scheduled upstream version change');
                    } else {
                        DB::transaction(function () use ($product, $row): void {
                            $locked = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
                            if ($locked->upstream_api_id === $product->upstream_api_id && $locked->upstream_pid === $product->upstream_pid) {
                                $locked->update(['stock_control' => (bool) ($row['stock_control'] ?? false), 'qty' => (int) ($row['qty'] ?? 0)]);
                            }
                        });
                    }
                    $count++;
                }
            } catch (DomainException $e) {
                Log::warning('Catalog probe failed.', ['product_id' => $first->id, 'error' => $e->getMessage()]);
            }
        }

        return $count;
    }
}
