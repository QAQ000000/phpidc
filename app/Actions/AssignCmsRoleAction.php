<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AssignCmsRoleAction
{
    /** Trusted CLI only. */
    public function execute(User $user, string $roleName, string $reason, bool $revoke = false): void
    {
        $permissions = match ($roleName) {
            'cms-viewer' => ['cms.view'],
            'cms-editor' => ['cms.view', 'cms.edit'],
            'cms-publisher' => ['cms.view', 'cms.edit', 'cms.publish'],
            default => throw new DomainException('Unknown CMS role.'),
        };
        if (trim($reason) === '') {
            throw new DomainException('An operator reason is required.');
        }
        DB::transaction(function () use ($user, $roleName, $reason, $revoke, $permissions): void {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions(array_map(fn (string $name) => Permission::findOrCreate($name, 'web'), $permissions));
            if ($revoke) {
                $locked->removeRole($role);
            } else {
                $locked->assignRole($role);
            }
            DB::table('audit_logs')->insert(['actor_type' => 'system', 'action' => 'cms.role.'.($revoke ? 'revoke' : 'grant'),
                'subject_type' => User::class, 'subject_id' => $locked->id, 'reason' => $reason,
                'after' => json_encode(['role' => $roleName]), 'created_at' => now(), 'updated_at' => now()]);
            DB::afterCommit(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions());
        });
        $user->unsetRelation('roles')->unsetRelation('permissions');
    }
}
