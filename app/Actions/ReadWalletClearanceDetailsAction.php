<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Models\WalletClearance;
use App\Support\OperationsAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ReadWalletClearanceDetailsAction
{
    public function execute(WalletClearance $clearance, User $actor, string $reason): array
    {
        validator(['reason' => $reason], ['reason' => ['required', 'string', 'max:255']])->validate();

        return DB::transaction(function () use ($clearance, $actor, $reason): array {
            $users = User::withTrashed()->whereIn('id', [$actor->id, $clearance->user_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $lockedActor = $users->get($actor->id);
            abort_unless($lockedActor instanceof User && ! $lockedActor->trashed() && $lockedActor->anonymized_at === null, 403);
            Gate::forUser($lockedActor)->authorize('finance.view');
            Gate::forUser($lockedActor)->authorize('finance.wallet-clearance.details');
            $locked = WalletClearance::whereKey($clearance->id)->lockForUpdate()->firstOrFail();
            OperationsAudit::record($lockedActor, 'wallet.clearance_details_view', WalletClearance::class, $locked->id, $reason);

            return ['clearance' => $locked, 'details' => $locked->payout_details, 'evidence' => $locked->payout_evidence];
        });
    }
}
