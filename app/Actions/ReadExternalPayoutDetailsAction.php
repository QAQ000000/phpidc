<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\AffiliateWithdrawal;
use App\Models\User;
use App\Support\OperationsAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ReadExternalPayoutDetailsAction
{
    public function execute(AffiliateWithdrawal $withdrawal, User $actor, string $reason): array
    {
        return DB::transaction(function () use ($withdrawal, $actor, $reason): array {
            $lockedActor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($lockedActor)->authorize('finance.view');
            Gate::forUser($lockedActor)->authorize('finance.external-payout.details');
            $locked = AffiliateWithdrawal::whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->destination === 'external', 404);
            OperationsAudit::record($lockedActor, 'affiliate.external_details_view', AffiliateWithdrawal::class, $locked->id, $reason);

            return ['withdrawal' => $locked, 'details' => $locked->encrypted_payout_details];
        });
    }
}
