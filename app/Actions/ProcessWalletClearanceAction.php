<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Models\WalletClearance;
use App\Services\NotificationManager;
use App\Services\ReceivableAdmission;
use App\Services\WalletClearanceEligibility;
use App\Support\Money;
use App\Support\OperationsAudit;
use App\Support\WalletCapacity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ProcessWalletClearanceAction
{
    public function execute(WalletClearance $clearance, User $actor, array $input): WalletClearance
    {
        $data = validator($input, [
            'request_id' => ['required', 'ulid'], 'operation' => ['required', 'in:approve,reject,start,cancel,unknown,paid,confirm_not_paid'],
            'reason' => ['required', 'string', 'max:255'], 'confirm' => ['required', 'accepted'],
            'intent_key' => ['nullable', 'string', 'max:64'], 'evidence' => ['nullable', 'string', 'max:1000'],
            'external_reference' => ['nullable', 'string', 'max:128'], 'confirmed_amount' => ['nullable', 'string', 'regex:/^\d{1,10}\.\d{2}$/D'],
            'currency' => ['nullable', 'in:CNY'],
        ])->validate();
        $data['request_id'] = Str::upper($data['request_id']);
        $data['reason'] = trim($data['reason']);
        if (isset($data['external_reference'])) {
            $data['external_reference'] = Str::upper(trim($data['external_reference']));
        }
        $guard = app(WalletClearanceEligibility::class);
        if ($data['reason'] === '') {
            $guard->reject('必须填写操作原因。');
        }
        $hint = WalletClearance::findOrFail($clearance->id);

        try {
            $operation = fn (?array $permit) => DB::transaction(function () use ($hint, $actor, $data, $guard, $permit): WalletClearance {
                $users = User::withTrashed()->whereKey([$hint->user_id, $actor->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $operator = $users->get($actor->id);
                $owner = $users->get($hint->user_id);
                abort_unless($operator !== null && ! $operator->trashed() && $operator->anonymized_at === null && $owner !== null, 403);
                Gate::forUser($operator)->authorize('finance.view');
                $permission = match ($data['operation']) {
                    'approve', 'reject' => 'approve', 'start', 'cancel' => 'execute', 'paid' => 'confirm', default => 'reconcile',
                };
                Gate::forUser($operator)->authorize('finance.wallet-clearance.'.$permission);
                $guard->lockSourceInvoice($hint->source_credit_transaction_id);
                $locked = WalletClearance::whereKey($hint->id)->lockForUpdate()->firstOrFail();
                if ($locked->user_id !== $owner->id || $locked->currency !== 'CNY'
                    || ! in_array($locked->scenario, ['closure_balance', 'closed_account_receipt'], true)
                    || Money::gt('0.01', $locked->amount) || Money::gt($locked->amount, '10000.00')
                    || ($locked->scenario === 'closure_balance' && ($locked->reconciliation_case_id !== null || $locked->source_credit_transaction_id !== null))
                    || ($locked->scenario === 'closed_account_receipt' && ($locked->reconciliation_case_id === null || $locked->source_credit_transaction_id === null))) {
                    $guard->reject('结清身份、币种、金额或来源记录已变化，请人工核对。');
                }
                $intent = ['actor_id' => $operator->id, 'operation' => $data['operation'], 'reason' => $data['reason'],
                    'intent_key' => $data['intent_key'] ?? '', 'evidence' => $data['evidence'] ?? '',
                    'external_reference' => $data['external_reference'] ?? '', 'confirmed_amount' => $data['confirmed_amount'] ?? '', 'currency' => $data['currency'] ?? ''];
                $requests = $locked->operation_requests ?? [];
                if (isset($requests[$data['request_id']])) {
                    if (Arr::sortRecursive($requests[$data['request_id']]['intent']) !== Arr::sortRecursive($intent)) {
                        $guard->reject('同一操作标识不能修改参数。');
                    }

                    return $locked;
                }
                if (in_array($data['operation'], ['approve', 'start'], true)) {
                    app(ReceivableAdmission::class)->assertLocked($owner, $permit ?? []);
                }
                $before = ['status' => $locked->status, ...WalletCapacity::snapshot($owner)];
                $this->transition($locked, $owner, $operator, $data, $guard, $permit);
                $requests[$data['request_id']] = ['intent' => $intent, 'status' => $locked->status];
                $locked->operation_requests = $requests;
                $locked->save();
                OperationsAudit::record($operator, 'wallet.clearance.'.$data['operation'], WalletClearance::class, $locked->id,
                    $data['reason'], $before, ['status' => $locked->status, ...WalletCapacity::snapshot($owner)]);
                $variables = ['amount' => $locked->amount, 'status' => $locked->status];
                if ($locked->scenario === 'closed_account_receipt') {
                    app(NotificationManager::class)->queueOperations('wallet.clearance', $variables, 'wallet-clearance:'.$locked->id.':'.$locked->status);
                } else {
                    app(NotificationManager::class)->queue($owner->id, 'wallet.clearance', $variables, eventKey: 'wallet-clearance:'.$locked->id.':'.$locked->status);
                }

                return $locked;
            });
            if (! in_array($data['operation'], ['approve', 'start'], true) || isset(($hint->operation_requests ?? [])[$data['request_id']])) {
                return $operation(null);
            }

            return app(ReceivableAdmission::class)->run(User::withTrashed()->findOrFail($hint->user_id),
                'wallet-clearance-process', $data['request_id'], ['record_id' => $hint->id, 'actor_id' => $actor->id, ...$data], $operation);
        } catch (UniqueConstraintViolationException) {
            $guard->reject('出款凭证或唯一资金流水已被使用，请核对原笔。');
        }
    }

    private function transition(WalletClearance $clearance, User $owner, User $actor, array $data, WalletClearanceEligibility $guard, ?array $permit): void
    {
        $operation = $data['operation'];
        if (in_array($operation, ['approve', 'reject'], true)) {
            $this->requireState($clearance, ['pending'], $guard);
            if ($operation === 'reject') {
                $clearance->fill(['status' => 'rejected', 'confirmed_by' => $actor->id, 'completed_at' => now()]);

                return;
            }
            $guard->assertNoOtherRequest($owner, $clearance->id);
            $guard->assertFinanciallyReady($owner, $clearance->id, $permit ?? []);
            $this->assertOwnerScenario($clearance, $owner, $guard);
            if (Money::gt($clearance->amount, $owner->credit)) {
                $guard->reject('当前可用钱包余额不足。');
            }
            if ($clearance->scenario === 'closed_account_receipt') {
                $case = $guard->sourceFor($owner, $clearance);
                if (Money::gt($clearance->amount, $guard->sourceTotals($case)['remaining'])) {
                    $guard->reject('批准金额超过原贷项未清退且未冻结的本金。');
                }
            }
            $before = WalletCapacity::snapshot($owner);
            $after = [...$before, 'credit' => Money::sub($before['credit'], $clearance->amount),
                'wallet_clearance_frozen' => Money::add($before['wallet_clearance_frozen'], $clearance->amount)];
            $this->writeWallet($owner, $before, $after, $guard);
            $this->ledger($clearance, $owner, TransactionType::WALLET_CLEARANCE_HOLD, 'hold');
            $clearance->fill(['status' => 'reserved', 'approved_by' => $actor->id, 'approved_at' => now()]);

            return;
        }
        if ($operation === 'start') {
            $this->requireState($clearance, ['reserved'], $guard);
            $guard->verifyHold($clearance, $owner);
            $guard->assertFinanciallyReady($owner, $clearance->id, $permit ?? []);
            $this->assertOwnerScenario($clearance, $owner, $guard);
            if ($clearance->scenario === 'closed_account_receipt') {
                $guard->sourceTotals($guard->sourceFor($owner, $clearance));
            }
            $clearance->fill(['status' => 'processing', 'payout_intent_key' => 'WC'.Str::ulid(), 'started_by' => $actor->id, 'started_at' => now()]);

            return;
        }
        if ($operation === 'cancel') {
            $this->requireState($clearance, ['reserved'], $guard);
            if ($clearance->payout_intent_key !== null) {
                $guard->reject('已有出款执行意图，不能普通取消。');
            }
        } else {
            $this->requireState($clearance, ['processing', 'unknown'], $guard);
            if (! is_string($data['intent_key'] ?? null) || $data['intent_key'] !== $clearance->payout_intent_key || trim($data['evidence'] ?? '') === '') {
                $guard->reject('必须核对原执行标识并提供真实付款结果证据。');
            }
            if ($operation === 'unknown') {
                $this->requireState($clearance, ['processing'], $guard);
                $guard->verifyHold($clearance, $owner);
                $clearance->fill(['status' => 'unknown', 'payout_evidence' => [...($clearance->payout_evidence ?? []),
                    'classification' => 'unknown', 'evidence' => $data['evidence']]]);

                return;
            }
        }
        $guard->verifyHold($clearance, $owner);
        $before = WalletCapacity::snapshot($owner);
        if ($operation === 'paid') {
            if (($data['currency'] ?? null) !== 'CNY' || ! isset($data['confirmed_amount']) || ! Money::eq($data['confirmed_amount'], $clearance->amount)
                || trim($data['external_reference'] ?? '') === '') {
                $guard->reject('已付款金额、CNY币种与唯一外部交易号必须准确。');
            }
            if ($clearance->scenario === 'closed_account_receipt') {
                $guard->sourceTotals($guard->sourceFor($owner, $clearance, false));
            }
            app(ClaimExternalPayoutReceiptAction::class)->execute($clearance->payout_method, $data['external_reference'],
                $clearance->amount, 'CNY', $actor, walletClearanceId: $clearance->id);
            $after = [...$before, 'wallet_clearance_frozen' => Money::sub($before['wallet_clearance_frozen'], $clearance->amount)];
            $this->writeWallet($owner, $before, $after, $guard);
            $clearance->fill(['status' => 'paid', 'external_reference' => $data['external_reference'], 'confirmed_by' => $actor->id,
                'completed_at' => now(), 'payout_evidence' => [...($clearance->payout_evidence ?? []), 'classification' => 'paid', 'evidence' => $data['evidence']]])->save();
            $entry = $this->ledger($clearance, $owner, TransactionType::WALLET_CLEARANCE_PAID, 'paid');
            if ($clearance->scenario === 'closed_account_receipt') {
                app(RecordPaymentAttentionCaseAction::class)->markPaid($clearance, $actor, $entry);
            }

            return;
        }
        $after = [...$before, 'credit' => Money::add($before['credit'], $clearance->amount),
            'wallet_clearance_frozen' => Money::sub($before['wallet_clearance_frozen'], $clearance->amount)];
        $this->writeWallet($owner, $before, $after, $guard);
        $this->ledger($clearance, $owner, TransactionType::WALLET_CLEARANCE_RELEASE, 'release');
        $clearance->fill(['status' => $operation === 'cancel' ? 'cancelled' : 'failed', 'confirmed_by' => $actor->id, 'completed_at' => now(),
            'payout_evidence' => [...($clearance->payout_evidence ?? []), 'classification' => 'not_paid',
                'evidence' => $data['evidence'] ?? 'Cancelled before execution intent']]);
    }

    private function requireState(WalletClearance $clearance, array $states, WalletClearanceEligibility $guard): void
    {
        if (! in_array($clearance->status, $states, true)) {
            $guard->reject('当前状态不允许此操作；已结束或未知的款项不能重新出款。');
        }
    }

    private function assertOwnerScenario(WalletClearance $clearance, User $owner, WalletClearanceEligibility $guard): void
    {
        $closed = $owner->trashed() || $owner->anonymized_at !== null;
        if (($clearance->scenario === 'closure_balance' && $closed) || ($clearance->scenario === 'closed_account_receipt' && ! $closed)) {
            $guard->reject('账户状态与结清场景不一致，请核对原身份。');
        }
    }

    private function writeWallet(User $owner, array $before, array $after, WalletClearanceEligibility $guard): void
    {
        if (! WalletCapacity::allows($before, $after)) {
            $guard->reject('本次冻结或释放超出钱包列范围，请先完成资金核对。');
        }
        $owner->forceFill($after)->save();
    }

    private function ledger(WalletClearance $clearance, User $owner, TransactionType $type, string $direction): FinancialTransaction
    {
        return FinancialTransaction::create(['user_id' => $owner->id, 'type' => $type, 'amount' => $clearance->amount,
            'balance_after' => $owner->credit, 'frozen_after' => $owner->frozen_credit,
            'reference_key' => 'wallet_clearance:'.$clearance->id.':'.$direction,
            'meta' => ['currency' => 'CNY', 'wallet_clearance_id' => $clearance->id,
                'wallet_clearance_frozen_after' => $owner->wallet_clearance_frozen,
                'reconciliation_case_id' => $clearance->reconciliation_case_id, 'source_credit_transaction_id' => $clearance->source_credit_transaction_id]]);
    }
}
