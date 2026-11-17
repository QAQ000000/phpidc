<?php

declare(strict_types=1);

namespace App\Data;

readonly class CatalogSnapshotResult
{
    public function __construct(
        public string $upstreamPid,
        public int $version,
        public array $product,
        public array $prices,
        public array $optionGroups,
        public array $customFields,
        public array $dependencies,
    ) {}
}
