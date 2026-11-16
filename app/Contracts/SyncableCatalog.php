<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\SyncCatalogResult;
use App\Models\Product;

interface SyncableCatalog
{
    /** @return array<int, array<string, mixed>> keyed by upstream pid */
    public function probeVersions(array $upstreamPids): array;

    public function syncProductConfig(Product $product): SyncCatalogResult;
}
