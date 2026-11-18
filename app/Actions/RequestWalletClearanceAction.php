<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Models\WalletClearance;
use App\Services\NotificationManager;
use App\Services\ReceivableAdmission;
use App\Services\WalletClearanceEligibility;
use App\Support\Money;
use App\Support\OperationsAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RequestWalletClearanceAction
{
    public function execute(User $owner, array $input): WalletClearance
    {
        $guard = app(WalletClearanceEligibility::class);
        $data = $guard->request($input);

        $operation = fn (?array $permit) => DB::transaction(function () use ($owner, $data, $guard, $permit): WalletClearance {
            $locked = User::withTrashed()->whereKey($owner->id)->lockForUpdate()->firstOrFail();
            if ($locked->trashed() || $locked->anonymized_at !== null || ! Hash::check($data['current_password'], $locked->getAuthPassword())) {
                $guard->reject('账户已停用或当前密码错误。');
            }
            $existing = WalletClearance::where('user_id', $locked->id)->where('request_id', $data['request_id'])->lockForUpdate()->first();
            if ($existing !== null) {
                return $guard->replay($existing, $data, 'closure_balance', $locked->id);
            }
            app(ReceivableAdmission::class)->assertLocked($locked, $permit ?? []);
            $guard->assertNoOtherRequest($locked);
            $guard->assertFinanciallyReady($locked, null, $permit ?? []);
            if (Money::gt($data['amount'], $locked->credit)) {
                $guard->reject('可用钱包余额不足，冻结余额不能申请结清。');
            }
            $clearance = WalletClearance::create(['user_id' => $locked->id, 'request_id' => $data['request_id'],
                'scenario' => 'closure_balance', 'amount' => $data['amount'], 'currency' => 'CNY', 'status' => 'pending',
                'payout_method' => $data['payout_method'], 'payout_details' => $data['payout_details'], 'requested_by' => $locked->id]);
            OperationsAudit::record($locked, 'wallet.clearance.request', WalletClearance::class, $clearance->id,
                '本人密码确认钱包结清申请', [], ['amount' => $clearance->amount, 'currency' => 'CNY', 'status' => 'pending']);
            app(NotificationManager::class)->queue($locked->id, 'wallet.clearance', ['amount' => $clearance->amount, 'status' => 'pending'],
                eventKey: 'wallet-clearance:'.$clearance->id.':pending');

            return $clearance;
        });

        return WalletClearance::where('user_id', $owner->id)->where('request_id', $data['request_id'])->exists() ? $operation(null) : app(ReceivableAdmission::class)->run($owner, 'wallet-clearance-request', $data['request_id'], $data, $operation);
    }
}
