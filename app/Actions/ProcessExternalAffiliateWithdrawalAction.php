<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\TransactionType;
use App\Models\AffiliateWithdrawal;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Services\NotificationManager;
use App\Services\ReceivableAdmission;
use App\Support\Money;
use App\Support\OperationsAudit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProcessExternalAffiliateWithdrawalAction
{
    public function execute(AffiliateWithdrawal $withdrawal, User $actor, array $input): AffiliateWithdrawal
    {
        $data = validator($input, [
            'request_id' => ['required', 'ulid'], 'operation' => ['required', 'in:approve,reject,start,cancel,unknown,paid,confirm_not_paid'],
            'reason' => ['required', 'string', 'max:255'], 'confirm' => ['required', 'accepted'],
            'intent_key' => ['nullable', 'string', 'max:64'], 'evidence' => ['nullable', 'string', 'max:1000'],
            'external_reference' => ['nullable', 'string', 'max:128'], 'confirmed_amount' => ['nullable', 'string', 'regex:/^\d{1,10}\.\d{2}$/D'],
            'currency' => ['nullable', 'in:CNY'],
        ])->validate();
        $data['request_id'] = Str::upper($data['request_id']);
        if (isset($data['external_reference'])) {
            $data['external_reference'] = Str::upper(trim($data['external_reference']));
        }
        $data['reason'] = trim($data['reason']);
        if ($data['reason'] === '') {
            throw ValidationException::withMessages(['reason' => '必须填写操作原因。']);
        }
        $hint = AffiliateWithdrawal::findOrFail($withdrawal->id);
        try {
            $operation = fn (?array $permit) => DB::transaction(function () use ($hint, $actor, $data, $permit): AffiliateWithdrawal {
                $users = User::withTrashed()->whereKey([$hint->user_id, $actor->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $operator = $users->get($actor->id);
                $owner = $users->get($hint->user_id);
                abort_unless($operator !== null && ! $operator->trashed() && $owner !== null, 403);
                Gate::forUser($operator)->authorize('finance.view');
                $permission = match ($data['operation']) {
                    'approve', 'reject' => 'approve', 'start', 'cancel' => 'execute', 'paid' => 'confirm', default => 'reconcile',
                };
                Gate::forUser($operator)->authorize('finance.external-payout.'.$permission);
                $locked = AffiliateWithdrawal::whereKey($hint->id)->lockForUpdate()->firstOrFail();
                abort_unless($locked->destination === 'external' && $locked->user_id === $owner->id, 422);
                $intent = ['actor_id' => $operator->id, 'operation' => $data['operation'], 'reason' => $data['reason'],
                    'intent_key' => $data['intent_key'] ?? '', 'evidence' => $data['evidence'] ?? '',
                    'external_reference' => $data['external_reference'] ?? '', 'confirmed_amount' => $data['confirmed_amount'] ?? '', 'currency' => $data['currency'] ?? ''];
                $requests = $locked->operation_requests ?? [];
                if (isset($requests[$data['request_id']])) {
                    if (Arr::sortRecursive($requests[$data['request_id']]['intent']) !== Arr::sortRecursive($intent)) {
                        throw ValidationException::withMessages(['request_id' => '同一操作标识不能修改参数。']);
                    }

                    return $locked;
                }
                if (in_array($data['operation'], ['approve', 'start'], true)) {
                    app(ReceivableAdmission::class)->assertLocked($owner, $permit ?? []);
                }
                $before = ['status' => $locked->status, 'affiliate_balance' => $owner->affiliate_balance, 'affiliate_frozen_balance' => $owner->affiliate_frozen_balance];
                $this->transition($locked, $owner, $operator, $data);
                $requests[$data['request_id']] = ['intent' => $intent, 'status' => $locked->status];
                $locked->operation_requests = $requests;
                $locked->save();
                OperationsAudit::record($operator, 'affiliate.external_'.$data['operation'], AffiliateWithdrawal::class, $locked->id, $data['reason'], $before,
                    ['status' => $locked->status, 'affiliate_balance' => $owner->affiliate_balance, 'affiliate_frozen_balance' => $owner->affiliate_frozen_balance]);
                app(NotificationManager::class)->queue($owner->id, 'affiliate.withdrawal', ['amount' => $locked->amount, 'status' => $locked->status],
                    eventKey: 'withdrawal:'.$locked->id.':'.$locked->status);

                return $locked;
            });
            if (! in_array($data['operation'], ['approve', 'start'], true) || isset(($hint->operation_requests ?? [])[$data['request_id']])) {
                return $operation(null);
            }

            return app(ReceivableAdmission::class)->run(User::withTrashed()->findOrFail($hint->user_id),
                'affiliate-external-process', $data['request_id'], ['record_id' => $hint->id, 'actor_id' => $actor->id, ...$data], $operation);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['external_reference' => '该外部交易凭证已用于另一笔付款，请核对原笔。']);
        }
    }

    private function transition(AffiliateWithdrawal $withdrawal, User $owner, User $actor, array $data): void
    {
        $operation = $data['operation'];
        if (in_array($operation, ['approve', 'reject'], true)) {
            $this->requireState($withdrawal, ['pending']);
            if ($owner->trashed()) {
                throw ValidationException::withMessages(['operation' => '账户已停用，须人工核对。']);
            }
            if ($operation === 'approve') {
                if (Money::gt($withdrawal->amount, $owner->affiliate_balance)
                    || Money::gt(Money::add($owner->affiliate_frozen_balance, $withdrawal->amount), '9999999999.99')) {
                    throw ValidationException::withMessages(['operation' => '佣金不足、存在未清应收或冻结余额超限。']);
                }
                $owner->forceFill(['affiliate_balance' => Money::sub($owner->affiliate_balance, $withdrawal->amount),
                    'affiliate_frozen_balance' => Money::add($owner->affiliate_frozen_balance, $withdrawal->amount)])->save();
                $this->ledger($withdrawal, $owner, TransactionType::AFFILIATE_FREEZE, 'external_payout_hold:'.$withdrawal->id);
            }
            $withdrawal->fill(['status' => $operation === 'approve' ? 'reserved' : 'rejected', 'processed_by' => $actor->id, 'processed_at' => now()]);

            return;
        }
        if ($operation === 'start') {
            $this->requireState($withdrawal, ['reserved']);
            $this->verifyHold($withdrawal, $owner);
            if ($owner->trashed()) {
                throw ValidationException::withMessages(['operation' => '存在未清应收或账户已停用，不能开始转账。']);
            }
            $withdrawal->fill(['status' => 'processing', 'payout_intent_key' => 'EP'.Str::ulid(), 'started_by' => $actor->id, 'started_at' => now()]);

            return;
        }
        if ($operation === 'cancel') {
            $this->requireState($withdrawal, ['reserved']);
            if ($withdrawal->payout_intent_key !== null) {
                throw ValidationException::withMessages(['operation' => '已有出款意图，须核对原笔。']);
            }
        } else {
            $this->requireState($withdrawal, ['processing', 'unknown']);
            if (! is_string($data['intent_key'] ?? null) || $data['intent_key'] !== $withdrawal->payout_intent_key || trim($data['evidence'] ?? '') === '') {
                throw ValidationException::withMessages(['evidence' => '必须核对原执行标识并提供付款或未付款证据。']);
            }
            if ($operation === 'unknown') {
                $this->requireState($withdrawal, ['processing']);
                $withdrawal->fill(['status' => 'unknown', 'encrypted_payout_evidence' => ['classification' => 'unknown', 'evidence' => $data['evidence']]]);

                return;
            }
        }
        $this->verifyHold($withdrawal, $owner);
        if ($operation === 'paid') {
            if (($data['currency'] ?? null) !== 'CNY' || ! isset($data['confirmed_amount']) || ! Money::eq($data['confirmed_amount'], $withdrawal->amount)
                || trim($data['external_reference'] ?? '') === '') {
                throw ValidationException::withMessages(['external_reference' => '付款金额、CNY币种及外部唯一交易号必须准确。']);
            }
            app(ClaimExternalPayoutReceiptAction::class)->execute($withdrawal->payout_method, $data['external_reference'],
                $withdrawal->amount, 'CNY', $actor, affiliateWithdrawalId: $withdrawal->id);
            $owner->forceFill(['affiliate_frozen_balance' => Money::sub($owner->affiliate_frozen_balance, $withdrawal->amount)])->save();
            $withdrawal->fill(['status' => 'paid', 'external_reference' => trim($data['external_reference']), 'confirmed_by' => $actor->id,
                'completed_at' => now(), 'encrypted_payout_evidence' => ['classification' => 'paid', 'evidence' => $data['evidence']]]);
            $this->ledger($withdrawal, $owner, TransactionType::AFFILIATE_PAYOUT, 'payout:'.$withdrawal->id);

            return;
        }
        if (Money::gt(Money::add($owner->affiliate_balance, $withdrawal->amount), '9999999999.99')) {
            throw ValidationException::withMessages(['operation' => '佣金余额超限，须人工核对。']);
        }
        $owner->forceFill(['affiliate_balance' => Money::add($owner->affiliate_balance, $withdrawal->amount),
            'affiliate_frozen_balance' => Money::sub($owner->affiliate_frozen_balance, $withdrawal->amount)])->save();
        $withdrawal->fill(['status' => $operation === 'cancel' ? 'cancelled' : 'failed', 'confirmed_by' => $actor->id, 'completed_at' => now(),
            'encrypted_payout_evidence' => ['classification' => 'not_paid', 'evidence' => $data['evidence'] ?? 'Cancelled before execution intent']]);
        $this->ledger($withdrawal, $owner, TransactionType::AFFILIATE_UNFREEZE, 'external_payout_release:'.$withdrawal->id);
    }

    private function requireState(AffiliateWithdrawal $withdrawal, array $states): void
    {
        if (! in_array($withdrawal->status, $states, true)) {
            throw ValidationException::withMessages(['operation' => '当前状态不允许该操作；已结束或不确定的款项不能重新出款。']);
        }
    }

    private function verifyHold(AffiliateWithdrawal $withdrawal, User $owner): void
    {
        $entry = FinancialTransaction::where('reference_key', 'external_payout_hold:'.$withdrawal->id)->first();
        if ($entry === null || $entry->type !== TransactionType::AFFILIATE_FREEZE || $entry->user_id !== $owner->id
            || ! Money::eq($entry->amount, $withdrawal->amount) || Money::gt($withdrawal->amount, $owner->affiliate_frozen_balance)
            || FinancialTransaction::whereIn('reference_key', ['payout:'.$withdrawal->id, 'external_payout_release:'.$withdrawal->id])->exists()) {
            throw ValidationException::withMessages(['operation' => '冻结金额或唯一流水不一致，须人工核对。']);
        }
    }

    private function ledger(AffiliateWithdrawal $withdrawal, User $owner, TransactionType $type, string $key): void
    {
        FinancialTransaction::create(['user_id' => $owner->id, 'type' => $type, 'amount' => $withdrawal->amount, 'reference_key' => $key,
            'balance_after' => $owner->credit, 'frozen_after' => $owner->frozen_credit,
            'meta' => ['currency' => 'CNY', 'destination' => 'external', 'withdrawal_id' => $withdrawal->id,
                'affiliate_balance_after' => $owner->affiliate_balance, 'affiliate_frozen_balance_after' => $owner->affiliate_frozen_balance]]);
    }
}
