<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\AccrueAffiliateCommissionAction;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Models\AffiliateCommission;
use App\Models\AffiliateWithdrawal;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\InstanceUpstreamBinding;
use App\Models\Invoice;
use App\Models\KycVerification;
use App\Models\PaymentAttempt;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WalletClearance;
use App\Support\MachineOperation;
use App\Support\Money;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountClosureEligibility
{
    /** A preview is advisory; CloseCustomerAccountAction repeats this under the active user lock.
     * @return array{eligible: bool, reasons: array<string, string>}
     */
    public function forUser(User $user): array
    {
        $reasons = [];
        if ($user->trashed() || $user->anonymized_at !== null) {
            return ['eligible' => false, 'reasons' => ['identity' => '账户已停用，不能再次发起注销。']];
        }
        foreach (['credit' => '钱包余额', 'frozen_credit' => '冻结钱包', 'wallet_clearance_frozen' => '结清冻结钱包', 'affiliate_balance' => '可用佣金', 'affiliate_frozen_balance' => '冻结佣金'] as $field => $label) {
            if (! Money::isZero($user->{$field})) {
                $reasons[$field] = $label.'尚未清理，请先完成资金核对；注销不会扣除或没收余额。';
            }
        }
        if ($user->getRoleNames()->isNotEmpty() || $user->getAllPermissions()->isNotEmpty()) {
            $reasons['permissions'] = '账户仍持有后台角色或权限，请先完成交接并由权限管理员撤销。';
        }
        if (Invoice::where('user_id', $user->id)->where('status', InvoiceStatus::UNPAID)->exists()) {
            $reasons['invoices'] = '仍有未结账单，请先完成付款、取消或到期处理。';
        }
        if (PaymentAttempt::whereIn('invoice_id', Invoice::where('user_id', $user->id)->select('id'))->whereIn('status', ['unknown', 'reconciling'])->exists()
            || app(PaymentAttentionCases::class)->hasUnresolvedForUser($user->id)) {
            $reasons['payments'] = '仍有待核对的收款或支付结果，请先由财务核对原笔。';
        }
        $instances = Instance::where('user_id', $user->id);
        foreach ((clone $instances)->whereNotIn('status', [InstanceStatus::CANCELLED, InstanceStatus::DELETED])->lazyById(100) as $instance) {
            if (! app(OneTimeEntitlementEvidence::class)->validArchive($instance)) {
                $reasons['instances'] = '仍有未确认终止或归档的服务，请先完成终止与业务核对。';
                break;
            }
        }
        if (InstanceUpstreamBinding::whereIn('instance_id', (clone $instances)->select('id'))->where('upstream_disposition', 'kept_orphan')->exists()) {
            $reasons['orphans'] = '存在保留的旧上游资源，请先完成旧资源的终止或移交核对。';
        }
        if (FulfillmentTask::where('user_id', $user->id)->where(function ($query): void {
            $query->whereIn('status', ['pending', 'running'])->orWhere(function ($unresolved): void {
                $unresolved->where('status', '!=', 'success')->where(function ($state): void {
                    $state->whereIn('payload->operation->state', ['create_requested', 'create_uncertain', 'invoice_created', 'payment_requested', 'paid', 'control_requested', 'control_uncertain', 'control_accepted'])
                        ->orWhereIn('payload->remote_delivery->state', ['requested', 'uncertain', 'received']);
                });
            });
        })->exists()) {
            $reasons['tasks'] = '仍有未完成任务或未核实的远端执行结果，请先核对原任务。';
        }
        foreach ((clone $instances)->with('upstreamApi')->whereIn('active_config->cloud_operation->status', ['requested', 'unknown'])->lazyById(100) as $instance) {
            try {
                $blocked = app(MachineOperation::class)->blocked($instance);
            } catch (DecryptException|\JsonException) {
                $blocked = true;
            }
            if ($blocked) {
                $reasons['machine_operations'] = '存在尚未核实的主机操作，请先核对原操作结果。';
                break;
            }
        }
        if (AffiliateWithdrawal::where('user_id', $user->id)->where(function ($query): void {
            $query->whereIn('status', ['pending', 'reserved', 'processing', 'unknown'])
                ->orWhere(fn ($approved) => $approved->where('destination', 'external')->where('status', 'approved'));
        })->exists()) {
            $reasons['withdrawals'] = '仍有待审核、冻结或待核对的提现，请先处理原申请。';
        }
        if (WalletClearance::where('user_id', $user->id)->whereIn('status', ['pending', 'reserved', 'processing', 'unknown'])->exists()) {
            $reasons['wallet_clearances'] = '仍有未结束的钱包结清申请，请先处理原申请。';
        }
        if (AffiliateCommission::where('referrer_id', $user->id)->where('status', 'pending')->exists()
            || Invoice::where('line_items->affiliate->referrer_id', $user->id)->whereIn('status', [InvoiceStatus::UNPAID, InvoiceStatus::PAID])
                ->whereNotIn('id', AffiliateCommission::select('invoice_id'))
                ->whereNotIn('id', DB::table('audit_logs')->where('action', AccrueAffiliateCommissionAction::ZERO_ACCRUAL_ACTION)->where('subject_type', Invoice::class)->whereNotNull('subject_id')->select('subject_id'))->exists()) {
            $reasons['commissions'] = '仍有待成熟佣金或尚未结转的推广订单，请先完成结转核对。';
        }
        if (app(AffiliateReceivableBalance::class)->hasOutstanding($user->id)) {
            $reasons['receivables'] = '仍有未清偿或证据待核对的退款应收，请先完成清偿。';
        }
        if (KycVerification::where('user_id', $user->id)->where('status', 'pending')->exists()) {
            $reasons['kyc'] = '仍有未完成的实名核验，请先核对外部申请结果。';
        }
        if (Ticket::where('user_id', $user->id)->where('status', '!=', 'closed')->exists()) {
            $reasons['tickets'] = '仍有未关闭工单，请先解决并关闭工单。';
        }

        return ['eligible' => $reasons === [], 'reasons' => $reasons];
    }

    public function assertMayClose(User $locked): void
    {
        $eligibility = $this->forUser($locked);
        if (! $eligibility['eligible']) {
            throw ValidationException::withMessages(['operation' => array_values($eligibility['reasons'])]);
        }
    }
}
