<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ReconciliationCase;
use App\Models\User;
use App\Models\WalletClearance;
use App\Services\NotificationManager;
use App\Services\ReceivableAdmission;
use App\Services\WalletClearanceEligibility;
use App\Support\Money;
use App\Support\OperationsAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RequestClosedAccountWalletClearanceAction
{
    public function execute(User $owner, User $actor, array $input): WalletClearance
    {
        $guard = app(WalletClearanceEligibility::class);
        $data = $guard->request($input, true);

        $operation = fn (?array $permit) => DB::transaction(function () use ($owner, $actor, $data, $guard, $permit): WalletClearance {
            $users = User::withTrashed()->whereKey([$owner->id, $actor->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $operator = $users->get($actor->id);
            $locked = $users->get($owner->id);
            abort_unless($operator !== null && ! $operator->trashed() && $operator->anonymized_at === null && $locked !== null, 403);
            Gate::forUser($operator)->authorize('finance.view');
            Gate::forUser($operator)->authorize('finance.wallet-clearance.reconcile');
            if (! $locked->trashed() && $locked->anonymized_at === null) {
                $guard->reject('此入口仅处理原已注销财务身份，活跃账户须本人申请。');
            }
            $hint = ReconciliationCase::find($data['reconciliation_case_id']);
            if ($hint === null || $hint->user_id !== $locked->id) {
                $guard->reject('来源案件不属于所选原财务身份。');
            }
            $guard->lockSourceInvoice($hint->source_credit_transaction_id);
            $existing = WalletClearance::where('user_id', $locked->id)->where('request_id', $data['request_id'])->lockForUpdate()->first();
            if ($existing !== null) {
                return $guard->replay($existing, $data, 'closed_account_receipt', $operator->id);
            }
            app(ReceivableAdmission::class)->assertLocked($locked, $permit ?? []);
            $guard->assertNoOtherRequest($locked);
            $guard->assertFinanciallyReady($locked, null, $permit ?? []);
            // The original user lock fences source changes. Create the financial row before the final case lock.
            $case = ReconciliationCase::whereKey($hint->id)->firstOrFail();
            if ($case->user_id !== $locked->id || $case->status !== 'awaiting_clearance' || $case->source_credit_transaction_id === null) {
                $guard->reject('原笔尚未入钱包或已不再允许清退。');
            }
            $credit = $guard->sourceCredit($case);
            $totals = $guard->sourceTotals($case);
            if (Money::gt($data['amount'], Money::min($locked->credit, $totals['remaining']))) {
                $guard->reject('申请金额超过原贷项剩余可清退额或真实旧钱包余额。');
            }
            $clearance = WalletClearance::create(['user_id' => $locked->id, 'request_id' => $data['request_id'],
                'scenario' => 'closed_account_receipt', 'amount' => $data['amount'], 'currency' => 'CNY', 'status' => 'pending',
                'reconciliation_case_id' => $case->id, 'source_credit_transaction_id' => $credit->id,
                'payout_method' => $data['payout_method'], 'payout_details' => $data['payout_details'], 'requested_by' => $operator->id,
                'payout_evidence' => ['request_reason' => trim($data['reason']), 'rights_evidence' => trim($data['evidence'])]]);
            ReconciliationCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            OperationsAudit::record($operator, 'wallet.clearance.closed_request', WalletClearance::class, $clearance->id, trim($data['reason']), [],
                ['amount' => $clearance->amount, 'currency' => 'CNY', 'case_id' => $case->id, 'status' => 'pending']);
            app(NotificationManager::class)->queueOperations('wallet.clearance', ['amount' => $clearance->amount, 'status' => 'pending'],
                eventKey: 'wallet-clearance:'.$clearance->id.':pending');

            return $clearance;
        });

        return WalletClearance::where('user_id', $owner->id)->where('request_id', $data['request_id'])->exists() ? $operation(null) : app(ReceivableAdmission::class)->run($owner, 'closed-wallet-clearance-request', $data['request_id'], ['actor_id' => $actor->id, ...$data], $operation);
    }
}
