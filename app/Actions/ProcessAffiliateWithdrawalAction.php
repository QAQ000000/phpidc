<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\TransactionType;
use App\Models\AffiliateWithdrawal;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Services\AffiliateFunds;
use App\Services\NotificationManager;
use App\Support\Money;
use App\Support\OperationsAudit;
use App\Support\WalletCapacity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProcessAffiliateWithdrawalAction
{
    public function execute(AffiliateWithdrawal $withdrawal, User $actor, bool $approve, string $reason): void
    {
        validator(['reason' => $reason], ['reason' => ['required', 'string', 'max:255']])->validate();
        $hint = AffiliateWithdrawal::findOrFail($withdrawal->id);
        DB::transaction(function () use ($hint, $actor, $approve, $reason): void {
            $users = User::whereKey([$hint->user_id, $actor->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $operator = $users->get($actor->id);
            abort_unless($operator !== null, 403);
            Gate::forUser($operator)->authorize('finance.payout');
            $user = $users->get($hint->user_id);
            abort_unless($user !== null, 422);
            $locked = AffiliateWithdrawal::whereKey($hint->id)->lockForUpdate()->firstOrFail();
            $target = $approve ? 'approved' : 'rejected';
            if ($locked->status === $target) {
                if ($approve) {
                    $entry = FinancialTransaction::where('reference_key', 'payout:'.$locked->id)->first();
                    if ($locked->destination !== 'wallet' || $entry === null || $entry->type !== TransactionType::AFFILIATE_PAYOUT || $entry->user_id !== $user->id || ! Money::eq($entry->amount, $locked->amount)) {
                        throw ValidationException::withMessages(['operation' => '已批准申请与唯一流水不一致，需人工核对。']);
                    }
                }

                return;
            }
            if ($locked->status !== 'pending' || $locked->destination !== 'wallet' || $locked->user_id !== $user->id) {
                throw ValidationException::withMessages(['operation' => '申请已处理或外部提现尚未接入。']);
            }
            $before = ['status' => $locked->status, 'credit' => $user->credit, 'affiliate_balance' => $user->affiliate_balance];
            if ($approve) {
                if (app(AffiliateFunds::class)->hasReceivable($user->id) || Money::gt($locked->amount, $user->affiliate_balance)
                    || ! WalletCapacity::canCredit($user, $locked->amount)) {
                    throw ValidationException::withMessages(['operation' => '有未清偿应收、余额不足或钱包超限，请人工核对。']);
                }
                $user->forceFill(['affiliate_balance' => Money::sub($user->affiliate_balance, $locked->amount),
                    'credit' => Money::add($user->credit, $locked->amount)])->save();
                FinancialTransaction::create(['user_id' => $user->id, 'type' => TransactionType::AFFILIATE_PAYOUT, 'amount' => $locked->amount,
                    'reference_key' => 'payout:'.$locked->id, 'balance_after' => $user->credit, 'frozen_after' => $user->frozen_credit,
                    'meta' => ['destination' => 'wallet', 'currency' => 'CNY', 'affiliate_balance_before' => $before['affiliate_balance'], 'affiliate_balance_after' => $user->affiliate_balance]]);
            }
            $locked->update(['status' => $target, 'processed_by' => $operator->id, 'processed_at' => now()]);
            OperationsAudit::record($operator, 'affiliate.withdrawal_'.$target, AffiliateWithdrawal::class, $locked->id, $reason, $before,
                ['status' => $target, 'credit' => $user->credit, 'affiliate_balance' => $user->affiliate_balance]);
            app(NotificationManager::class)->queue($user->id, 'affiliate.withdrawal', ['status' => $target, 'amount' => $locked->amount], eventKey: 'withdrawal:'.$locked->id.':'.$target);
        });
    }
}
