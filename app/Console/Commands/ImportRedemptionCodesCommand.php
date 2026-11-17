<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ImportRedemptionCodesAction;
use App\Models\Product;
use Illuminate\Console\Command;

class ImportRedemptionCodesCommand extends Command
{
    protected $signature = 'cards:import {product} {file} {--reason=}';

    protected $description = 'Import one plaintext key per line through the audited system-operator entry';

    public function handle(ImportRedemptionCodesAction $import): int
    {
        $path = $this->argument('file');
        if (! is_readable($path) || ! is_file($path)) {
            $this->error('The import file is not readable.');

            return self::FAILURE;
        }
        $codes = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($codes === false) {
            $this->error('Could not read the import file.');

            return self::FAILURE;
        }
        $count = $import->execute(Product::findOrFail($this->argument('product')), $codes, $this->option('reason') ?? '');
        $this->info("Imported {$count} new cards.");

        return self::SUCCESS;
    }
}
