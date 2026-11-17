<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\CatalogSnapshotResult;
use App\Models\Product;

interface SyncableCatalog
{
    /** @return array<int, array<string, mixed>> keyed by upstream pid */
    public function probeVersions(array $upstreamPids): array;

    /** Read an upstream snapshot; only the calling Action persists it (C12 / F10). */
    public function syncProductConfig(Product $product): CatalogSnapshotResult;
}
