<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\UpdateProductPresentationAction;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;

class DescribeProductCommand extends Command
{
    protected $signature = 'products:describe {product} {file} {--name=} {--visibility=} {--reason=}';

    protected $description = 'Update product text and optional visibility without changing its driver or prices';

    public function handle(UpdateProductPresentationAction $save): int
    {
        validator(['visibility' => $this->option('visibility')], ['visibility' => ['nullable', Rule::in(['visible', 'hidden'])]])->validate();
        $data = ['description' => File::get($this->argument('file'))];
        if ($this->option('name') !== null) {
            $data['name'] = $this->option('name');
        }
        if ($this->option('visibility') !== null) {
            $data['is_hidden'] = $this->option('visibility') === 'hidden';
        }
        $product = $save->execute(Product::findOrFail($this->argument('product')), $data, $this->option('reason') ?? '');
        $this->info("Updated product {$product->id}.");

        return self::SUCCESS;
    }
}
