<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ProbeClassicCatalogAction;
use App\Actions\SyncClassicCatalogAction;
use App\Models\Product;
use Illuminate\Console\Command;

class ProbeClassicCatalogCommand extends Command
{
    protected $signature = 'upstream:classic-sync {product?} {--reason=}';

    protected $description = 'Probe versions and stock, or explicitly synchronize one mapped product';

    public function handle(ProbeClassicCatalogAction $probe): int
    {
        if ($this->argument('product') !== null) {
            app(SyncClassicCatalogAction::class)->execute(Product::findOrFail($this->argument('product')), $this->option('reason') ?? '');
        } else {
            $probe->execute();
        }

        return self::SUCCESS;
    }
}
