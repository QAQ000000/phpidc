<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AssignInventoryRoleAction
{
    /** Trusted server operator only; never exposed as a web action. */
    public function execute(User $user, string $roleName, string $reason, bool $revoke = false): void
    {
        if (trim($reason) === '' || ! in_array($roleName, ['inventory-viewer', 'inventory-operator'], true)) {
            throw new DomainException('Select an inventory role and supply an operator reason.');
        }
        DB::transaction(function () use ($user, $roleName, $reason, $revoke): void {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $names = $roleName === 'inventory-operator' ? ['inventory.view', 'inventory.import', 'inventory.retry'] : ['inventory.view'];
            $permissions = array_map(fn (string $name) => Permission::findOrCreate($name, 'web'), $names);
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions($permissions);
            $before = $locked->getRoleNames()->all();
            if ($revoke) {
                $locked->removeRole($role);
            } else {
                $locked->assignRole($role);
            }
            DB::table('audit_logs')->insert(['actor_type' => 'system', 'action' => 'inventory.role.'.($revoke ? 'revoke' : 'grant'),
                'subject_type' => User::class, 'subject_id' => $locked->id, 'reason' => $reason,
                'before' => json_encode(['roles' => $before]), 'after' => json_encode(['roles' => $locked->getRoleNames()->all()]),
                'created_at' => now(), 'updated_at' => now()]);
            DB::afterCommit(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions());
        });
        $user->unsetRelation('roles')->unsetRelation('permissions');
    }
}
