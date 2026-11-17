<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\AssignUpstreamRoleAction;
use App\Models\User;
use Illuminate\Console\Command;

class UpstreamRoleCommand extends Command
{
    protected $signature = 'upstream:role {user} {role} {--revoke} {--reason=}';

    protected $description = 'Assign an explicit upstream viewer, operator, lifecycle or migrator role';

    public function handle(AssignUpstreamRoleAction $action): int
    {
        $action->execute(User::findOrFail($this->argument('user')), $this->argument('role'), $this->option('reason') ?? '', (bool) $this->option('revoke'));

        return self::SUCCESS;
    }
}
