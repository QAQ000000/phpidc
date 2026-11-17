<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\AssignCmsRoleAction;
use App\Models\User;
use Illuminate\Console\Command;

class CmsRoleCommand extends Command
{
    protected $signature = 'cms:role {user} {role} {--reason=} {--revoke}';

    protected $description = 'Grant or revoke a CMS role as a trusted server operator';

    public function handle(AssignCmsRoleAction $action): int
    {
        $action->execute(User::findOrFail($this->argument('user')), $this->argument('role'), $this->option('reason') ?? '', (bool) $this->option('revoke'));
        $this->info('CMS role updated.');

        return self::SUCCESS;
    }
}
