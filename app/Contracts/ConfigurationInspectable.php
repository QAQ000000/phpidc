<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Instance;
use App\Models\Product;

/** Authenticated, read-only facts; never prepare or submit an upgrade. */
interface ConfigurationInspectable
{
    public function inspectConfiguration(Instance $instance, Product $product): array;
}
