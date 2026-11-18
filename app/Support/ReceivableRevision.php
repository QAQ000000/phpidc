<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

class ReceivableRevision
{
    /** Caller already holds this user's row; all evidence writers use the same fence. */
    public function bump(User $user): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Receivable revisions belong to the original user transaction.');
        }
        $revision = (string) $user->receivable_revision;
        if (preg_match('/^\d+$/D', $revision) !== 1
            || DB::table('users')->where('id', $user->id)->where('receivable_revision', $revision)->increment('receivable_revision') !== 1) {
            throw new LogicException('The locked receivable revision changed or is unavailable.');
        }
        $user->setAttribute('receivable_revision', DB::table('users')->where('id', $user->id)->value('receivable_revision'));
        $user->syncOriginalAttribute('receivable_revision');
    }
}
