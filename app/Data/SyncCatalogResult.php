<?php

declare(strict_types=1);

namespace App\Data;

readonly class SyncCatalogResult
{
    public function __construct(
        public int $productsSynced,
        public int $optionsUpserted,
        public int $optionsHidden,
        public ?int $latestVersion = null,
    ) {}
}
