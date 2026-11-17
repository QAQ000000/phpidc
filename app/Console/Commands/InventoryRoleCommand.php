<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\AssignInventoryRoleAction;
use App\Models\User;
use Illuminate\Console\Command;

class InventoryRoleCommand extends Command
{
    protected $signature = 'inventory:role {user : Existing user ID} {role : inventory-viewer or inventory-operator} {--revoke} {--reason=}';

    protected $description = 'Grant or revoke a narrowly scoped inventory role with a system audit';

    public function handle(AssignInventoryRoleAction $assign): int
    {
        $assign->execute(User::findOrFail($this->argument('user')), $this->argument('role'), $this->option('reason') ?? '', (bool) $this->option('revoke'));
        $this->info('Inventory role updated.');

        return self::SUCCESS;
    }
}
