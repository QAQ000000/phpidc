<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\PaymentCallback;
use App\Models\ReconciliationCase;
use App\Models\ReconciliationEntry;
use App\Models\WalletClearance;
use App\Support\Money;
use DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

class PaymentAttentionCases
{
    public function callbackFingerprint(PaymentCallback $callback, Invoice $invoice): string
    {
        return $this->fingerprint(['version' => 1, 'source' => 'payment_callback', 'id' => $callback->id,
            'invoice_id' => $invoice->id, 'user_id' => $invoice->user_id, 'gateway' => $callback->gateway,
            'trade' => $callback->gateway_trade_no, 'invoice_no' => $callback->invoice_no, 'amount' => $callback->amount]);
    }

    public function creditFingerprint(FinancialTransaction $credit): string
    {
        return $this->fingerprint(['version' => 1, 'source' => 'wallet_credit', 'id' => $credit->id,
            'user_id' => $credit->user_id, 'invoice_id' => $credit->invoice_id, 'type' => $credit->type->value,
            'amount' => $credit->amount, 'reference_key' => $credit->reference_key, 'meta' => $credit->meta]);
    }

    /** No administrator may select an arbitrary ledger row as a clearance source. */
    public function assertSource(ReconciliationCase $case): FinancialTransaction
    {
        $credit = $case->source_credit_transaction_id === null ? null : FinancialTransaction::find($case->source_credit_transaction_id);
        if ($case->kind !== 'payment_attention' || ! in_array($case->initial_reason, ['wallet_capacity', 'closed_account'], true)
            || $credit === null || $credit->type !== TransactionType::CREDIT_IN || $credit->user_id !== $case->user_id) {
            throw new DomainException('The original wallet credit requires reconciliation.');
        }
        $invoice = Invoice::find($credit->invoice_id);
        if ($invoice === null || $invoice->user_id !== $case->user_id || $invoice->currency !== 'CNY') {
            throw new DomainException('The original credit invoice does not match the financial identity.');
        }
        if ($case->source_type === 'wallet_credit') {
            if ($case->payment_callback_id !== null || $case->source_id !== (string) $credit->id
                || $credit->reference_key !== 'refund:'.$invoice->id || ($credit->meta['mode'] ?? null) !== 'refund_wallet'
                || ($credit->meta['currency'] ?? null) !== 'CNY' || ! Money::eq($credit->amount, $invoice->total_amount)
                || ! hash_equals($case->source_fingerprint, $this->creditFingerprint($credit))) {
                throw new DomainException('The original refund credit evidence does not match.');
            }
        } elseif ($case->source_type === 'payment_callback') {
            $callback = PaymentCallback::find($case->payment_callback_id);
            if ($callback === null || $case->source_id !== (string) $callback->id || $callback->invoice_id !== $invoice->id
                || $callback->gateway !== $invoice->gateway || ! hash_equals($case->source_fingerprint, $this->callbackFingerprint($callback, $invoice))) {
                throw new DomainException('The original callback evidence does not match.');
            }
            $fullReceipt = $credit->reference_key === 'credit_in:callback:'.$callback->id && Money::eq($credit->amount, $callback->amount);
            $excess = $credit->reference_key === 'overpaid:'.$callback->id && Money::gt($callback->amount, $invoice->gateway_amount)
                && Money::eq($credit->amount, Money::sub($callback->amount, $invoice->gateway_amount));
            if (! $fullReceipt && ! $excess) {
                throw new DomainException('The callback has no matching confirmed wallet credit.');
            }
        } else {
            throw new DomainException('Unsupported clearance source.');
        }

        return $credit;
    }

    /** Original attention history stays immutable; only evidence-backed conclusions release its blocker. */
    public function hasUnresolvedForUser(int $userId): bool
    {
        foreach (ReconciliationCase::where('user_id', $userId)->where('kind', 'payment_attention')->lazyById(100) as $case) {
            if (! $this->isResolved($case)) {
                return true;
            }
        }

        return DB::table('audit_logs')->where('action', 'payment.callback.attention')->where('subject_type', PaymentCallback::class)
            ->whereIn('subject_id', DB::table('payment_callbacks')->whereIn('invoice_id', Invoice::where('user_id', $userId)->select('id'))->select('id'))
            ->whereIn('after->reason', ['wallet_capacity', 'closed_account'])
            ->whereNotIn('subject_id', ReconciliationCase::where('user_id', $userId)->where('source_type', 'payment_callback')->whereNotNull('payment_callback_id')->select('payment_callback_id'))->exists();
    }

    public function isResolved(ReconciliationCase $case): bool
    {
        if ($case->status !== 'resolved' || $case->resolved_at === null) {
            return false;
        }
        try {
            $source = $this->assertSource($case);
            $entry = ReconciliationEntry::where('case_id', $case->id)->orderByDesc('sequence')->first();
            if ($entry === null || $entry->action !== 'resolved' || $entry->sequence !== $case->revision
                || $entry->source_credit_transaction_id !== $source->id) {
                return false;
            }
            $totals = app(WalletClearanceEligibility::class)->sourceTotals($case);

            return Money::eq($totals['paid'], $source->amount) && Money::isZero($totals['held']);
        } catch (DomainException|ValidationException|DecryptException|JsonException) {
            return false;
        }
    }

    public function paidEvidenceMatches(WalletClearance $clearance): bool
    {
        $ledger = FinancialTransaction::where('reference_key', 'wallet_clearance:'.$clearance->id.':paid')->first();
        $receipt = DB::table('external_payout_receipts')->where('wallet_clearance_id', $clearance->id)->first();

        return $ledger !== null && $ledger->type === TransactionType::WALLET_CLEARANCE_PAID && $ledger->user_id === $clearance->user_id
            && Money::eq($ledger->amount, $clearance->amount) && $receipt !== null && $receipt->affiliate_withdrawal_id === null
            && $receipt->payout_method === $clearance->payout_method && $receipt->external_reference === $clearance->external_reference
            && Money::eq((string) $receipt->amount, $clearance->amount) && $receipt->currency === 'CNY';
    }

    private function fingerprint(array $snapshot): string
    {
        return hash('sha256', json_encode(Arr::sortRecursive($snapshot), JSON_THROW_ON_ERROR));
    }
}
