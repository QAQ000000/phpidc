<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ConfigureEpayAction;
use Illuminate\Console\Command;

class ConfigureEpayCommand extends Command
{
    protected $signature = 'payments:configure-epay {url} {pid} {--reason=}';

    protected $description = 'Store epay configuration with an encrypted interactive key and system audit';

    public function handle(ConfigureEpayAction $configure): int
    {
        $key = $this->secret('Epay key');
        $configure->execute($this->argument('url'), $this->argument('pid'), $key ?? '', $this->option('reason') ?? '');
        $this->info('Epay configured. Restart long-running workers to load the new settings.');

        return self::SUCCESS;
    }
}
