<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\PrepareInstallationAction;
use Illuminate\Console\Command;

class PrepareInstallationCommand extends Command
{
    protected $signature = 'app:install-token';

    protected $description = 'Generate a one-hour first-install token on the trusted server';

    public function handle(PrepareInstallationAction $prepare): int
    {
        $this->line($prepare->execute());
        $this->info('Use this token at /install within one hour. It is not stored in plaintext.');

        return self::SUCCESS;
    }
}
