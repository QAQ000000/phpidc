<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CallUpstreamAction;
use App\Services\DriverManager;
use Illuminate\Console\Command;

class InspectClassicUpstreamCommand extends Command
{
    protected $signature = 'upstream:classic-inspect {account} {--catalog}';

    protected $description = 'Read classic account health/balance or catalog with redacted operation logging';

    public function handle(CallUpstreamAction $call, DriverManager $drivers): int
    {
        $driver = $drivers->classicAccount((int) $this->argument('account'));
        $data = $call->execute(fn () => $this->option('catalog') ? $driver->catalog() : $driver->checkBalance());
        $this->line(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
