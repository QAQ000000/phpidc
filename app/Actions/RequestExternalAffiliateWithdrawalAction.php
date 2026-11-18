<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\AffiliateWithdrawal;
use App\Models\User;
use App\Services\AffiliateFunds;
use App\Services\NotificationManager;
use App\Services\ReceivableAdmission;
use App\Support\Money;
use App\Support\OperationsAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RequestExternalAffiliateWithdrawalAction
{
    public function execute(User $user, array $input): AffiliateWithdrawal
    {
        $data = validator($input, [
            'request_id' => ['required', 'ulid'], 'amount' => ['required', 'string', 'regex:/^\d{1,10}\.\d{2}$/D'],
            'payout_method' => ['required', 'in:bank_transfer,alipay'], 'payee_name' => ['required', 'string', 'max:100'],
            'payee_account' => ['required', 'string', 'max:128'], 'bank_name' => ['required_if:payout_method,bank_transfer', 'nullable', 'string', 'max:100'],
        ])->validate();
        $details = ['name' => trim($data['payee_name']), 'account' => trim($data['payee_account']),
            'bank' => $data['payout_method'] === 'bank_transfer' ? trim($data['bank_name'] ?? '') : ''];
        if ($details['name'] === '' || $details['account'] === '' || ($data['payout_method'] === 'bank_transfer' && $details['bank'] === '')) {
            throw ValidationException::withMessages(['payee_account' => '请填写完整收款信息。']);
        }

        $operation = fn (?array $permit) => DB::transaction(function () use ($user, $data, $details, $permit): AffiliateWithdrawal {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $key = $locked->id.':'.Str::upper($data['request_id']);
            $existing = AffiliateWithdrawal::where('request_key', $key)->first();
            if ($existing !== null) {
                if ($existing->destination !== 'external' || ! Money::eq($existing->amount, $data['amount'])
                    || $existing->payout_method !== $data['payout_method'] || $existing->encrypted_payout_details !== $details) {
                    throw ValidationException::withMessages(['request_id' => '同一申请标识不能改变金额、方式或收款人。']);
                }

                return $existing;
            }
            app(ReceivableAdmission::class)->assertLocked($locked, $permit ?? []);
            $funds = app(AffiliateFunds::class);
            if (Money::gt('1.00', $data['amount']) || Money::gt($data['amount'], '10000.00')
                || Money::gt(Money::add($funds->pending($locked->id), $data['amount']), $locked->affiliate_balance)) {
                throw ValidationException::withMessages(['amount' => '金额须为1.00–10000.00 CNY，且可申请佣金足够、无未清退款应收。']);
            }
            $withdrawal = AffiliateWithdrawal::create(['user_id' => $locked->id, 'destination' => 'external', 'amount' => $data['amount'],
                'request_key' => $key, 'status' => 'pending', 'payout_method' => $data['payout_method'], 'encrypted_payout_details' => $details]);
            OperationsAudit::record($locked, 'affiliate.external_request', AffiliateWithdrawal::class, $withdrawal->id, '客户申请外部佣金提现', [],
                ['amount' => $withdrawal->amount, 'currency' => 'CNY', 'method' => $withdrawal->payout_method, 'status' => 'pending']);
            app(NotificationManager::class)->queue($locked->id, 'affiliate.withdrawal', ['amount' => $withdrawal->amount, 'status' => 'pending'],
                eventKey: 'withdrawal:'.$withdrawal->id.':pending');

            return $withdrawal;
        });

        return AffiliateWithdrawal::where('request_key', $user->id.':'.Str::upper($data['request_id']))->exists() ? $operation(null) : app(ReceivableAdmission::class)->run($user, 'affiliate-external-request', $data['request_id'], $data, $operation);
    }
}
