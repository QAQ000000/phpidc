<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Support\AdminAuditContext;
use App\Support\AdminRoles;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AssignAdminRoleAction
{
    /** Null actor is reserved for the trusted server command, never inferred from an HTTP request. */
    public function execute(User $target, string $name, string $reason, bool $revoke = false, ?User $actor = null): void
    {
        $permissions = AdminRoles::permissions()[$name] ?? null;
        if ($permissions === null || trim($reason) === '' || mb_strlen($reason) > 255) {
            throw new DomainException('Choose a fixed role and supply a reason of 1–255 characters.');
        }
        if ($actor !== null && (! in_array($name, AdminRoles::webAssignable(), true) || $actor->id === $target->id)) {
            throw new DomainException('Web operators cannot change their own roles or grant access-manager.');
        }
        DB::transaction(function () use ($target, $name, $permissions, $reason, $revoke, $actor): void {
            $users = User::whereKey(array_filter([$target->id, $actor?->id]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $locked = $users->get($target->id);
            if ($locked === null) {
                throw new DomainException('The target account is no longer active.');
            }
            if ($actor !== null) {
                $operator = $users->get($actor->id);
                if ($operator === null) {
                    throw new DomainException('The operator account is no longer active.');
                }
                Gate::forUser($operator)->authorize('access.view');
                Gate::forUser($operator)->authorize('access.manage');
            }
            $before = $locked->getRoleNames()->sort()->values()->all();
            $role = Role::findOrCreate($name, 'web');
            $role->syncPermissions(array_map(fn (string $permission) => Permission::findOrCreate($permission, 'web'), $permissions));
            $revoke ? $locked->removeRole($role) : $locked->assignRole($role);
            DB::table('audit_logs')->insert([
                'actor_type' => $actor === null ? 'system' : 'user', 'actor_id' => $actor?->id,
                'action' => 'admin.role.'.($revoke ? 'revoke' : 'grant'), 'subject_type' => User::class, 'subject_id' => $locked->id,
                'before' => json_encode(['roles' => $before], JSON_THROW_ON_ERROR),
                'after' => json_encode(['roles' => $locked->getRoleNames()->sort()->values()->all()], JSON_THROW_ON_ERROR),
                'reason' => $reason, ...AdminAuditContext::fields($actor !== null), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::afterCommit(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions());
        });
        $target->unsetRelation('roles')->unsetRelation('permissions');
    }
}
