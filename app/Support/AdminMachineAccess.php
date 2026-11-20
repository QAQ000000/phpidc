<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Instance;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

/** Explicit administrator identity, separate from the retained service owner. */
class AdminMachineAccess
{
    /** @return array{actor: User, owner: User, instance: Instance} */
    public function authorize(User $actor, Instance $instance): array
    {
        return DB::transaction(fn (): array => $this->lock($actor, $instance));
    }

    /** @return array{actor: User, owner: User, instance: Instance} */
    public function lock(User $actor, Instance $instance): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Administrator machine authorization requires its local transaction.');
        }
        $users = User::withTrashed()->whereKey([$actor->id, $instance->user_id])
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $operator = $users->get($actor->id);
        $owner = $users->get($instance->user_id);
        abort_unless($operator !== null && $operator->isAccessEnabled(), 403);
        Gate::forUser($operator)->authorize('upstream.view');
        Gate::forUser($operator)->authorize('upstream.machine-control');
        // Revoking customer access does not revoke the administrator's service maintenance authority.
        abort_unless($owner !== null && ! $owner->trashed() && $owner->anonymized_at === null, 403);
        $current = Instance::whereKey($instance->id)->lockForUpdate()->firstOrFail();
        abort_unless($current->user_id === $owner->id, 409);
        $current->load('upstreamApi');

        return ['actor' => $operator, 'owner' => $owner, 'instance' => $current];
    }

    /** A digest may be signed in a short-lived URL; it contains no readable credentials. */
    public function fingerprint(Instance $instance): string
    {
        $operations = app(MachineOperation::class);

        return hash('sha256', json_encode(['binding' => $operations->binding($instance),
            'status' => $instance->status->value, 'account_active' => $instance->upstreamApi?->is_active,
            'account_fingerprint' => $operations->accountFingerprint($instance)], JSON_THROW_ON_ERROR));
    }
}
