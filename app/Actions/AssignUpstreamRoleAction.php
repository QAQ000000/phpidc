<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AssignUpstreamRoleAction
{
    public function execute(User $user, string $name, string $reason, bool $revoke = false): void
    {
        $permissions = match ($name) {
            'upstream-viewer' => ['upstream.view'],
            'upstream-operator' => ['upstream.view', 'upstream.configure', 'upstream.import'],
            'upstream-lifecycle' => ['upstream.view', 'upstream.lifecycle'],
            'upstream-migrator' => ['upstream.view', 'upstream.hotswap'],
            default => throw new DomainException('Unknown upstream role.'),
        };
        if (trim($reason) === '') {
            throw new DomainException('A role assignment reason is required.');
        }
        DB::transaction(function () use ($user, $name, $reason, $revoke, $permissions): void {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $role = Role::findOrCreate($name, 'web');
            $role->syncPermissions(array_map(fn ($permission) => Permission::findOrCreate($permission, 'web'), $permissions));
            $revoke ? $locked->removeRole($role) : $locked->assignRole($role);
            DB::table('audit_logs')->insert(['actor_type' => 'system', 'action' => 'upstream.role.'.($revoke ? 'revoke' : 'grant'),
                'subject_type' => User::class, 'subject_id' => $user->id, 'reason' => $reason,
                'after' => json_encode(['role' => $name]), 'created_at' => now(), 'updated_at' => now()]);
            DB::afterCommit(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions());
        });
        $user->unsetRelation('roles')->unsetRelation('permissions');
    }
}
