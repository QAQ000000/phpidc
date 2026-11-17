<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ConfigureIdentityAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

class ConfigureIdentityCommand extends Command
{
    protected $signature = 'identity:configure {file : Private JSON file outside the web root} {--reason=}';

    protected $description = 'Configure account mail, SMS and Telegram/social login without printing secrets';

    public function handle(ConfigureIdentityAction $action): int
    {
        $settings = json_decode(File::get($this->argument('file')), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($settings) || array_is_list($settings)) {
            throw ValidationException::withMessages(['settings' => 'Expected a JSON object of setting keys.']);
        }
        $action->execute($settings, $this->option('reason') ?? '');
        $this->info('Identity settings updated. Restart long-lived workers after changes.');

        return self::SUCCESS;
    }
}
