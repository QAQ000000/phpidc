<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

class AdminFinancialAccess
{
    private const PERMISSIONS = ['finance.wallet-adjust', 'finance.offline-receipt'];

    /** Identity protection for a new financial operation; disabled customers remain correctable. */
    public function isManageableTarget(User $actor, User $target): bool
    {
        return $actor->isAccessEnabled() && $actor->id !== $target->id
            && ! $target->trashed() && $target->anonymized_at === null
            && ! $target->roles()->exists() && ! $target->permissions()->exists();
    }

    /**
     * Authorize the current actor before looking up replay evidence. The caller
     * checks target admission only for a new write, retaining historical replay.
     *
     * @return array{actor: User, target: User}
     */
    public function lock(User $target, User $actor, string $permission): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Administrative financial access requires its financial transaction.');
        }
        abort_unless(in_array($permission, self::PERMISSIONS, true), 403);
        $users = User::withTrashed()->whereKey([$target->id, $actor->id])
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $operator = $users->get($actor->id);
        $locked = $users->get($target->id);
        abort_unless($operator !== null && $operator->isAccessEnabled(), 403);
        Gate::forUser($operator)->authorize('finance.view');
        Gate::forUser($operator)->authorize($permission);
        abort_unless($locked !== null, 403);

        return ['actor' => $operator, 'target' => $locked];
    }
}
