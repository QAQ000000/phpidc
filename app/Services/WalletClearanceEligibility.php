<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\AffiliateWithdrawal;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\ReconciliationCase;
use App\Models\User;
use App\Models\WalletClearance;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WalletClearanceEligibility
{
    public const OPEN = ['pending', 'reserved', 'processing', 'unknown'];

    public function request(array $input, bool $closed = false): array
    {
        $data = validator($input, [
            'request_id' => ['required', 'ulid'], 'amount' => ['required', 'string', 'regex:/^\d{1,10}\.\d{2}$/D'],
            'payout_method' => ['required', 'in:bank_transfer,alipay'], 'payee_name' => ['required', 'string', 'max:100'],
            'payee_account' => ['required', 'string', 'max:128'], 'bank_name' => ['required_if:payout_method,bank_transfer', 'nullable', 'string', 'max:100'],
            'confirm' => ['required', 'accepted'], 'current_password' => [$closed ? 'nullable' : 'required', 'string'],
            'reconciliation_case_id' => [$closed ? 'required' : 'nullable', 'integer', 'min:1'],
            'reason' => [$closed ? 'required' : 'nullable', 'string', 'max:255'],
            'evidence' => [$closed ? 'required' : 'nullable', 'string', 'max:1000'],
        ])->validate();
        $data['request_id'] = Str::upper($data['request_id']);
        $data['payout_details'] = ['name' => trim($data['payee_name']), 'account' => trim($data['payee_account']),
            'bank' => $data['payout_method'] === 'bank_transfer' ? trim($data['bank_name'] ?? '') : ''];
        if (Money::gt('0.01', $data['amount']) || Money::gt($data['amount'], '10000.00')
            || $data['payout_details']['name'] === '' || $data['payout_details']['account'] === ''
            || ($data['payout_method'] === 'bank_transfer' && $data['payout_details']['bank'] === '')
            || ($closed && (trim($data['reason']) === '' || trim($data['evidence']) === ''))) {
            $this->reject('请填写完整收款资料与核验依据，金额须为0.01–10000.00 CNY。');
        }

        return $data;
    }

    public function assertFinanciallyReady(User $owner, ?int $exceptId = null): void
    {
        if (app(AffiliateReceivableBalance::class)->hasOutstanding($owner->id)
            || PaymentAttempt::whereIn('invoice_id', Invoice::where('user_id', $owner->id)->select('id'))->whereIn('status', ['unknown', 'reconciling'])->exists()
            || AffiliateWithdrawal::where('user_id', $owner->id)->where('destination', 'external')->where('status', 'unknown')->exists()
            || WalletClearance::where('user_id', $owner->id)->where('status', 'unknown')->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))->exists()) {
            $this->reject('存在未清应收、未知收款或出款，请先核对原笔。');
        }
        // wallet_capacity / closed_account source cases deliberately do not block their own clearance.
    }

    public function assertNoOtherRequest(User $owner, ?int $exceptId = null): void
    {
        if (WalletClearance::where('user_id', $owner->id)->whereIn('status', self::OPEN)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))->lockForUpdate()->exists()) {
            $this->reject('请先完成或取消原钱包结清申请。');
        }
    }

    public function replay(WalletClearance $existing, array $data, string $scenario, int $requestedBy): WalletClearance
    {
        $evidence = $existing->payout_evidence ?? [];
        if ($existing->scenario !== $scenario || $existing->requested_by !== $requestedBy || ! Money::eq($existing->amount, $data['amount'])
            || $existing->payout_method !== $data['payout_method'] || $existing->payout_details !== $data['payout_details']
            || ($scenario === 'closed_account_receipt' && ($existing->reconciliation_case_id !== (int) $data['reconciliation_case_id']
                || ($evidence['request_reason'] ?? null) !== trim($data['reason']) || ($evidence['rights_evidence'] ?? null) !== trim($data['evidence'])))) {
            $this->reject('同一申请标识不能修改金额、收款人、来源或核验依据。');
        }

        return $existing;
    }

    /** Source invoice locks precede financial-record locks; this method takes no case lock. */
    public function lockSourceInvoice(?int $creditId): void
    {
        if ($creditId === null) {
            return;
        }
        $invoiceId = FinancialTransaction::whereKey($creditId)->value('invoice_id');
        if ($invoiceId !== null) {
            Invoice::whereKey($invoiceId)->lockForUpdate()->firstOrFail();
        }
    }

    public function sourceFor(User $owner, WalletClearance $clearance, bool $lock = true): ReconciliationCase
    {
        $query = ReconciliationCase::whereKey($clearance->reconciliation_case_id);
        $case = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($case === null || $case->user_id !== $owner->id || $case->kind !== 'payment_attention'
            || $case->status !== 'awaiting_clearance' || $case->source_credit_transaction_id !== $clearance->source_credit_transaction_id) {
            $this->reject('原入账来源尚未核实、已变化或不属于此财务身份。');
        }
        $credit = $this->sourceCredit($case);
        if ($credit->user_id !== $owner->id || $credit->id !== $clearance->source_credit_transaction_id) {
            $this->reject('原钱包贷项与结清来源不一致。');
        }

        return $case;
    }

    /**
     * Caller holds the original user's lock. No additional locks are taken here, including when called after a case lock.
     *
     * @return array{principal: string, paid: string, held: string, remaining: string}
     */
    public function sourceTotals(ReconciliationCase $case): array
    {
        $credit = $this->sourceCredit($case);
        $paid = $held = Money::zero();
        foreach (WalletClearance::where('reconciliation_case_id', $case->id)->orderBy('id')->get() as $clearance) {
            if ($clearance->user_id !== $case->user_id || $clearance->source_credit_transaction_id !== $credit->id
                || $clearance->scenario !== 'closed_account_receipt' || $clearance->currency !== 'CNY' || Money::isZero($clearance->amount)) {
                $this->reject('来源清退记录不一致，请核对原流水。');
            }
            $hold = $this->entry($clearance, 'hold', TransactionType::WALLET_CLEARANCE_HOLD);
            $paidEntry = $this->entry($clearance, 'paid', TransactionType::WALLET_CLEARANCE_PAID);
            $release = $this->entry($clearance, 'release', TransactionType::WALLET_CLEARANCE_RELEASE);
            if (in_array($clearance->status, ['pending', 'rejected'], true)) {
                if ($hold !== null || $paidEntry !== null || $release !== null) {
                    $this->reject('未冻结申请存在资金流水，请人工核对。');
                }
            } elseif (in_array($clearance->status, ['reserved', 'processing', 'unknown'], true)) {
                if ($hold === null || $paidEntry !== null || $release !== null) {
                    $this->reject('有效冻结与资金流水不一致。');
                }
                $held = Money::add($held, $clearance->amount);
            } elseif ($clearance->status === 'paid') {
                if ($hold === null || $paidEntry === null || $release !== null || ! $this->hasReceipt($clearance)) {
                    $this->reject('已付清退缺少原冻结、唯一流水或外部凭证。');
                }
                $paid = Money::add($paid, $clearance->amount);
            } elseif (in_array($clearance->status, ['cancelled', 'failed'], true)) {
                if ($hold === null || $release === null || $paidEntry !== null) {
                    $this->reject('已释放清退与原流水不一致。');
                }
            } else {
                $this->reject('清退状态无法识别，请人工核对。');
            }
        }
        $committed = Money::add($paid, $held);
        if (Money::gt($committed, $credit->amount)) {
            $this->reject('已付与冻结合计超过原贷项，不能继续清退。');
        }

        return ['principal' => $credit->amount, 'paid' => $paid, 'held' => $held, 'remaining' => Money::sub($credit->amount, $committed)];
    }

    public function verifyHold(WalletClearance $clearance, User $owner): void
    {
        if ($this->entry($clearance, 'hold', TransactionType::WALLET_CLEARANCE_HOLD) === null
            || $this->entry($clearance, 'paid', TransactionType::WALLET_CLEARANCE_PAID) !== null
            || $this->entry($clearance, 'release', TransactionType::WALLET_CLEARANCE_RELEASE) !== null
            || Money::gt($clearance->amount, $owner->wallet_clearance_frozen)) {
            $this->reject('结清冻结金额或唯一流水不一致，请核对原笔。');
        }
    }

    public function sourceCredit(ReconciliationCase $case): FinancialTransaction
    {
        try {
            return app(PaymentAttentionCases::class)->assertSource($case);
        } catch (DomainException) {
            $this->reject('原钱包贷项证据不完整或已变化，请核对原笔。');
        }
    }

    private function entry(WalletClearance $clearance, string $direction, TransactionType $type): ?FinancialTransaction
    {
        $entry = FinancialTransaction::where('reference_key', 'wallet_clearance:'.$clearance->id.':'.$direction)->first();
        if ($entry !== null && ($entry->type !== $type || $entry->user_id !== $clearance->user_id || ! Money::eq($entry->amount, $clearance->amount)
            || ($entry->meta['currency'] ?? null) !== 'CNY' || ($entry->meta['wallet_clearance_id'] ?? null) !== $clearance->id
            || ($entry->meta['source_credit_transaction_id'] ?? null) !== $clearance->source_credit_transaction_id)) {
            $this->reject('结清流水与申请不一致，请人工核对。');
        }

        return $entry;
    }

    private function hasReceipt(WalletClearance $clearance): bool
    {
        $receipt = DB::table('external_payout_receipts')->where('wallet_clearance_id', $clearance->id)->first();

        return $receipt !== null && $receipt->affiliate_withdrawal_id === null && $receipt->currency === 'CNY'
            && $receipt->payout_method === $clearance->payout_method && $receipt->external_reference === $clearance->external_reference
            && Money::eq(Money::of((string) $receipt->amount), $clearance->amount);
    }

    public function reject(string $message): never
    {
        throw ValidationException::withMessages(['operation' => $message]);
    }
}
