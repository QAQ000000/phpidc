<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ReconciliationCase;
use App\Models\ReconciliationEntry;
use App\Models\User;
use App\Support\OperationsAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ReadReconciliationEvidenceAction
{
    public function execute(ReconciliationCase $case, User $actor, string $reason): array
    {
        validator(['reason' => $reason], ['reason' => ['required', 'string', 'max:255']])->validate();

        return DB::transaction(function () use ($case, $actor, $reason): array {
            $users = User::withTrashed()->whereKey([$case->user_id, $actor->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $operator = $users->get($actor->id);
            abort_unless($operator instanceof User && ! $operator->trashed() && $operator->anonymized_at === null, 403);
            $locked = ReconciliationCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($operator)->authorize($locked->kind === 'payment_attention' ? 'finance.view' : 'upstream.view');
            Gate::forUser($operator)->authorize($locked->kind === 'payment_attention' ? 'finance.reconcile' : 'upstream.orphan-reconcile');
            OperationsAudit::record($operator, 'reconciliation.evidence_view', ReconciliationCase::class, $locked->id, $reason);

            return ['case' => $locked, 'entries' => ReconciliationEntry::where('case_id', $locked->id)->orderByDesc('sequence')->paginate(20)];
        });
    }
}
