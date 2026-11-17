<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\AssignAdminRoleAction;
use App\Models\User;
use Illuminate\Console\Command;

class AdminRoleCommand extends Command
{
    protected $signature = 'admin:role {user : Existing user ID} {role : Fixed business role or access-manager} {--revoke} {--reason=}';

    protected $description = 'Grant or revoke a fixed admin role with a system audit; never creates accounts';

    public function handle(AssignAdminRoleAction $assign): int
    {
        $assign->execute(User::findOrFail($this->argument('user')), $this->argument('role'), $this->option('reason') ?? '', (bool) $this->option('revoke'));
        $this->info('Admin role updated.');

        return self::SUCCESS;
    }
}
