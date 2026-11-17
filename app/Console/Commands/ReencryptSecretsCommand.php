<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ReencryptSecretsAction;
use Illuminate\Console\Command;

class ReencryptSecretsCommand extends Command
{
    protected $signature = 'app:reencrypt-secrets {--old-key=} {--new-key-file=} {--reason=} {--workers-stopped}';

    protected $description = 'Offline transactional re-encryption; keep the old APP_KEY until this command succeeds';

    public function handle(ReencryptSecretsAction $rotate): int
    {
        $path = $this->option('new-key-file');
        if (! is_string($path) || ! is_file($path) || ! is_readable($path) || (fileperms($path) & 0077) !== 0) {
            $this->error('Provide a readable private (0600) --new-key-file. No key is printed.');

            return self::FAILURE;
        }
        $rotate->execute($this->option('old-key') ?: config('app.key'), trim((string) file_get_contents($path)),
            $this->option('reason') ?? '', (bool) $this->option('workers-stopped'));
        $this->info('Database and installation seal rotated. NOW install the new APP_KEY, rebuild config cache, restart PHP/workers, verify deployment, then leave maintenance.');

        return self::SUCCESS;
    }
}
