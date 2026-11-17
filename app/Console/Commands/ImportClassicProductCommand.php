<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ImportClassicProductAction;
use App\Enums\BillingCycle;
use Illuminate\Console\Command;

class ImportClassicProductCommand extends Command
{
    protected $signature = 'upstream:classic-import {account} {pid} {cycle=monthly} {--currency=CNY} {--markup=20.00} {--reason=}';

    protected $description = 'Map a classic upstream product and cycle without altering existing instances';

    public function handle(ImportClassicProductAction $import): int
    {
        $product = $import->execute((int) $this->argument('account'), $this->argument('pid'), BillingCycle::from($this->argument('cycle')),
            $this->option('currency'), $this->option('markup'), $this->option('reason') ?? '');
        $this->info("Mapped product {$product->id}: {$product->name}");

        return self::SUCCESS;
    }
}
