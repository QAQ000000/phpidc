<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Support\ReceivableRevision;
use Illuminate\Support\Facades\DB;

class ReceivableReconciliation
{
    /** A completed bad scan may invalidate only the exact revision it examined. */
    public function flagIfUnchanged(array $scan): void
    {
        if ($scan['valid']) {
            return;
        }
        DB::transaction(function () use ($scan): void {
            $user = User::withTrashed()->whereKey($scan['user_id'])->lockForUpdate()->firstOrFail();
            if ((string) $user->receivable_revision !== $scan['revision'] || $user->receivable_reconciliation_required) {
                return;
            }
            $user->forceFill(['receivable_reconciliation_required' => true])->save();
            app(ReceivableRevision::class)->bump($user);
        });
    }
}
