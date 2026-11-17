<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ConfigureClassicUpstreamAction;
use Illuminate\Console\Command;

class ConfigureClassicUpstreamCommand extends Command
{
    protected $signature = 'upstream:classic-configure {name} {url} {username} {--reason=} {--raw-jwt} {--credit-limit}';

    protected $description = 'Configure a classic ZJMF account using an encrypted interactive API secret';

    public function handle(ConfigureClassicUpstreamAction $configure): int
    {
        $account = $configure->execute($this->argument('name'), $this->argument('url'), $this->argument('username'),
            $this->secret('Classic client-center API secret') ?? '', $this->option('reason') ?? '', $this->option('raw-jwt'), $this->option('credit-limit'));
        $this->info("Configured classic account {$account->id}. Restart long-running workers.");

        return self::SUCCESS;
    }
}
