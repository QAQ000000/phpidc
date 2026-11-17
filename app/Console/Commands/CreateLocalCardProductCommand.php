<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CreateLocalCardProductAction;
use App\Enums\DriverType;
use Illuminate\Console\Command;

class CreateLocalCardProductCommand extends Command
{
    protected $signature = 'cards:create-product {name} {price} {--setup-fee=0.00} {--currency=CNY} {--reason=} {--driver=redemption}';

    protected $description = 'Create a one-time local card product as a trusted system operator';

    public function handle(CreateLocalCardProductAction $create): int
    {
        $product = $create->execute($this->argument('name'), $this->argument('price'), $this->option('setup-fee'), $this->option('currency'), $this->option('reason') ?? '', DriverType::from($this->option('driver')));
        $this->info("Created product {$product->id}: {$product->name}");

        return self::SUCCESS;
    }
}
