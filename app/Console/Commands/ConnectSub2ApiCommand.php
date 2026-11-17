<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ConfigureSub2ApiAction;
use Illuminate\Console\Command;

class ConnectSub2ApiCommand extends Command
{
    protected $signature = 'sub2api:connect {name} {url} {--reason=}';

    protected $description = 'Create an encrypted Sub2API connection using a hidden administrator-key prompt';

    public function handle(ConfigureSub2ApiAction $configure): int
    {
        $key = $this->secret('Administrator API key');
        $account = $configure->execute($this->argument('name'), $this->argument('url'), $key ?? '', $this->option('reason') ?? '');
        $this->info('Created Sub2API connection '.$account->id.'.');

        return self::SUCCESS;
    }
}
