<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\AffiliateWithdrawal;
use App\Models\User;
use App\Services\AffiliateFunds;
use App\Services\ReceivableAdmission;
use App\Support\Money;
use App\Support\OperationsAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequestAffiliateWithdrawalAction
{
    public function execute(User $user, string $amount, string $requestId, string $destination = 'wallet'): AffiliateWithdrawal
    {
        validator(['amount' => $amount, 'request_id' => $requestId, 'destination' => $destination], [
            'amount' => ['required', 'regex:/^\d{1,10}\.\d{2}$/D'], 'request_id' => ['required', 'ulid'], 'destination' => ['in:wallet'],
        ])->validate();

        $operation = fn (?array $permit) => DB::transaction(function () use ($user, $amount, $requestId, $permit): AffiliateWithdrawal {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $key = $locked->id.':'.$requestId;
            $existing = AffiliateWithdrawal::where('request_key', $key)->first();
            if ($existing !== null) {
                if (! Money::eq($amount, $existing->amount) || $existing->destination !== 'wallet') {
                    throw ValidationException::withMessages(['amount' => '同一申请标识不能修改金额或目标。']);
                }

                return $existing;
            }
            app(ReceivableAdmission::class)->assertLocked($locked, $permit ?? []);
            $funds = app(AffiliateFunds::class);
            if (Money::isZero($amount) || Money::gt(Money::add($funds->pending($locked->id), $amount), $locked->affiliate_balance)) {
                throw ValidationException::withMessages(['amount' => '可申请推广余额不足，或存在未清偿退款应收。']);
            }
            $withdrawal = AffiliateWithdrawal::create(['user_id' => $locked->id, 'destination' => 'wallet', 'amount' => $amount,
                'request_key' => $key, 'status' => 'pending']);
            OperationsAudit::record($locked, 'affiliate.withdrawal_request', AffiliateWithdrawal::class, $withdrawal->id, '客户申请佣金转钱包', [], ['amount' => $amount, 'status' => 'pending']);

            return $withdrawal;
        });

        return AffiliateWithdrawal::where('request_key', $user->id.':'.$requestId)->exists() ? $operation(null) : app(ReceivableAdmission::class)->run($user, 'affiliate-wallet-request', $requestId, ['amount' => $amount, 'destination' => $destination], $operation);
    }
}
