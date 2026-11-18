<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Services\ReceivableAdmission;
use App\Support\OperationsAudit;
use App\Support\ReceivableRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReviewReceivableEvidenceAction
{
    public function execute(User $owner, User $actor, array $input): void
    {
        $data = validator($input, ['request_id' => ['required', 'ulid'], 'reason' => ['required', 'string', 'max:255'],
            'confirm' => ['required', 'accepted']])->validate();
        Gate::forUser($actor)->authorize('finance.view');
        Gate::forUser($actor)->authorize('finance.receivable-review');
        if ($this->replay($owner, $actor, $data)) {
            return;
        }
        app(ReceivableAdmission::class)->run($owner, 'receivable-review', $data['request_id'], ['actor_id' => $actor->id, ...$data],
            function (array $permit) use ($owner, $actor, $data): void {
                DB::transaction(function () use ($owner, $actor, $data, $permit): void {
                    $users = User::withTrashed()->whereKey([$owner->id, $actor->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                    $operator = $users->get($actor->id);
                    abort_unless($operator !== null && ! $operator->trashed() && $operator->anonymized_at === null, 403);
                    Gate::forUser($operator)->authorize('finance.view');
                    Gate::forUser($operator)->authorize('finance.receivable-review');
                    $locked = $users->get($owner->id);
                    abort_unless($locked !== null, 404);
                    if ($this->replay($locked, $operator, $data)) {
                        return;
                    }
                    app(ReceivableAdmission::class)->assertLocked($locked, $permit, true);
                    if (! $permit['valid']) {
                        throw ValidationException::withMessages(['operation' => '完整证据核验未通过，不能解除失效标志。']);
                    }
                    $before = ['revision' => (string) $locked->receivable_revision, 'reconciliation_required' => (bool) $locked->receivable_reconciliation_required];
                    $locked->forceFill(['receivable_reconciliation_required' => false])->save();
                    app(ReceivableRevision::class)->bump($locked);
                    OperationsAudit::record($operator, 'affiliate.receivable_reviewed', User::class, $locked->id, $data['reason'], $before,
                        ['revision' => (string) $locked->receivable_revision, 'reconciliation_required' => false,
                            'request_id' => $data['request_id'], 'digest' => $permit['digest'], 'algorithm' => $permit['algorithm']]);
                });
            }, false, true);
    }

    private function replay(User $owner, User $actor, array $data): bool
    {
        $audit = DB::table('audit_logs')->where('action', 'affiliate.receivable_reviewed')->where('subject_type', User::class)
            ->where('subject_id', $owner->id)->where('after->request_id', $data['request_id'])->first();
        if ($audit === null) {
            return false;
        }
        if ((int) $audit->actor_id !== $actor->id || $audit->reason !== $data['reason']) {
            throw ValidationException::withMessages(['request_id' => '同一复核请求不能修改人员或原因。']);
        }

        return true;
    }
}
