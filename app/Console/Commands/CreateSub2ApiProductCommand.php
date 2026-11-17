<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CreateSub2ApiProductAction;
use App\Models\UpstreamApi;
use Illuminate\Console\Command;

class CreateSub2ApiProductCommand extends Command
{
    protected $signature = 'sub2api:create-product {account} {name} {price} {face_value} {--currency=CNY} {--reason=}';

    protected $description = 'Create an audited one-time fixed-face-value remote Sub2API code product';

    public function handle(CreateSub2ApiProductAction $create): int
    {
        $product = $create->execute(UpstreamApi::findOrFail($this->argument('account')), $this->argument('name'), $this->argument('price'),
            $this->argument('face_value'), $this->option('currency'), $this->option('reason') ?? '');
        $this->info('Created remote Sub2API product '.$product->id.'.');

        return self::SUCCESS;
    }
}
