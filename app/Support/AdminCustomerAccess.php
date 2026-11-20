<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class AdminCustomerAccess
{
    private const PERMISSIONS = ['customers.manage', 'customers.password-reset', 'customers.status.manage'];

    public function isManageableTarget(User $actor, User $target): bool
    {
        if (! $actor->isAccessEnabled() || ! Gate::forUser($actor)->allows('customers.view')
            || ! Gate::forUser($actor)->any(self::PERMISSIONS) || $actor->id === $target->id
            || $target->trashed() || $target->anonymized_at !== null) {
            return false;
        }

        return ! $target->roles()->exists() && ! $target->permissions()->exists();
    }

    public function canManage(User $actor, User $target, string $permission): bool
    {
        return in_array($permission, self::PERMISSIONS, true) && Gate::forUser($actor)->allows($permission)
            && $this->isManageableTarget($actor, $target);
    }

    /** @return array{actor: User, target: User} */
    public function lock(User $target, User $actor, string $permission): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Customer management requires its account transaction.');
        }
        abort_unless(in_array($permission, self::PERMISSIONS, true), 403);
        $users = User::withTrashed()->select(['id', 'name', 'email', 'phone', 'email_verified_at',
            'deleted_at', 'anonymized_at', 'disabled_at', 'auth_revision'])
            ->whereKey([$target->id, $actor->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $operator = $users->get($actor->id);
        $locked = $users->get($target->id);
        abort_unless($operator !== null && $operator->isAccessEnabled(), 403);
        Gate::forUser($operator)->authorize('customers.view');
        Gate::forUser($operator)->authorize($permission);
        abort_unless($locked !== null && $this->isManageableTarget($operator, $locked), 403);

        return ['actor' => $operator, 'target' => $locked];
    }

    /** Caller holds the original user lock; the saved revision fences all session stores. */
    public function revokeSessions(User $locked): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Session revocation requires the locked account transaction.');
        }
        $revision = (int) $locked->auth_revision;
        if ($revision >= 4294967295) {
            throw ValidationException::withMessages(['operation' => '账户认证版本已达到上限，请联系运维核对。']);
        }
        $locked->forceFill(['auth_revision' => $revision + 1, 'remember_token' => Str::random(60)])->save();
        DB::table('sessions')->where('user_id', $locked->id)->delete();
    }
}
