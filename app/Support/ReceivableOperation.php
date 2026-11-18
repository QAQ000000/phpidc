<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AffiliateReceivableSettlement;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReceivableOperation
{
    /** Call only inside a transaction; IDs from hints are rechecked under users → invoice → debt locks.
     * @return array{User, FinancialTransaction}
     */
    public function lock(FinancialTransaction $hint, User $actor, ?AffiliateReceivableSettlement $correctionHint = null): array
    {
        $users = User::withTrashed()->whereKey(array_filter([$hint->user_id, $actor->id, $correctionHint?->user_id]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $operator = $users->get($actor->id);
        abort_unless($operator !== null && ! $operator->trashed() && $operator->anonymized_at === null, 403);
        Gate::forUser($operator)->authorize('finance.view');
        Gate::forUser($operator)->authorize('finance.receivable');
        $owner = $users->get($hint->user_id);
        if ($owner === null || $hint->invoice_id === null) {
            throw ValidationException::withMessages(['operation' => '应收原用户或账单证据缺失。']);
        }
        Invoice::whereKey(array_filter([$hint->invoice_id, $correctionHint?->invoice_id]))->orderBy('id')->lockForUpdate()->get();
        $root = FinancialTransaction::whereKey($hint->id)->lockForUpdate()->firstOrFail();
        if ($root->user_id !== $owner->id || $root->invoice_id !== $hint->invoice_id) {
            throw ValidationException::withMessages(['operation' => '应收参与方已改变，请刷新核对。']);
        }

        return [$owner, $root];
    }

    public function version(array $projection, string $version): void
    {
        if (! hash_equals($projection['version'], $version)) {
            throw ValidationException::withMessages(['version' => '应收记录已变化，请刷新后确认当前剩余金额。']);
        }
    }

    public function conflict(): never
    {
        throw ValidationException::withMessages(['operation' => '请求、收款凭证或冲正记录已使用，请刷新核对原记录。']);
    }
}
