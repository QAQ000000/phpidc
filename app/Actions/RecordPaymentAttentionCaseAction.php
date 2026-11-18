<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\PaymentCallback;
use App\Models\ReconciliationCase;
use App\Models\ReconciliationEntry;
use App\Models\User;
use App\Models\WalletClearance;
use App\Services\PaymentAttentionCases;
use App\Services\WalletClearanceEligibility;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Facades\DB;

class RecordPaymentAttentionCaseAction
{
    /** Called after financial writes, while the original owner is locked. */
    public function execute(PaymentCallback $callback, string $reason): ReconciliationCase
    {
        $this->assertTransaction();
        if (! in_array($reason, ['wallet_capacity', 'closed_account'], true)) {
            throw new DomainException('Unsupported payment attention source.');
        }
        $invoice = Invoice::findOrFail($callback->invoice_id);
        $proof = app(PaymentAttentionCases::class);
        $case = ReconciliationCase::where('source_type', 'payment_callback')->where('source_id', (string) $callback->id)->lockForUpdate()->first();
        if ($case !== null) {
            if ($case->user_id !== $invoice->user_id || ! hash_equals($case->source_fingerprint, $proof->callbackFingerprint($callback, $invoice))) {
                throw new DomainException('Payment attention source changed; manual reconciliation is required.');
            }

            return $case;
        }
        $credit = FinancialTransaction::whereIn('reference_key', ['credit_in:callback:'.$callback->id, 'overpaid:'.$callback->id])->first();
        $case = ReconciliationCase::create(['user_id' => $invoice->user_id, 'payment_callback_id' => $callback->id,
            'kind' => 'payment_attention', 'source_type' => 'payment_callback', 'source_id' => (string) $callback->id,
            'source_fingerprint' => $proof->callbackFingerprint($callback, $invoice), 'initial_reason' => $reason,
            'status' => $credit === null ? 'open' : 'awaiting_clearance', 'source_credit_transaction_id' => $credit?->id]);
        if ($credit !== null) {
            $proof->assertSource($case);
        }
        $this->append($case, 'created', 'created', null, $reason);
        if ($credit !== null && Money::isZero($credit->amount)) {
            $case->update(['status' => 'resolved', 'resolved_at' => now(), 'revision' => 2]);
            $this->append($case, 'resolved:zero', 'resolved', null, 'Verified original receipt has no wallet principal to clear.');
        }

        return $case;
    }

    /** A closed customer's legal wallet refund remains attached to their original financial identity. */
    public function refundCredit(User $owner, FinancialTransaction $credit): ?ReconciliationCase
    {
        $this->assertTransaction();
        if ((! $owner->trashed() && $owner->anonymized_at === null) || Money::isZero($credit->amount)) {
            return null;
        }
        $existing = ReconciliationCase::where('source_credit_transaction_id', $credit->id)->lockForUpdate()->first();
        if ($existing !== null) {
            app(PaymentAttentionCases::class)->assertSource($existing);

            return $existing;
        }
        $case = ReconciliationCase::create(['user_id' => $owner->id, 'kind' => 'payment_attention', 'source_type' => 'wallet_credit',
            'source_id' => (string) $credit->id, 'source_fingerprint' => app(PaymentAttentionCases::class)->creditFingerprint($credit),
            'initial_reason' => 'closed_account', 'status' => 'awaiting_clearance', 'source_credit_transaction_id' => $credit->id]);
        app(PaymentAttentionCases::class)->assertSource($case);
        $this->append($case, 'created', 'created', null, 'closed_account');

        return $case;
    }

    public function markPaid(WalletClearance $clearance, User $actor, FinancialTransaction $paidEntry): void
    {
        $this->assertTransaction();
        if ($clearance->reconciliation_case_id === null) {
            return;
        }
        $case = ReconciliationCase::whereKey($clearance->reconciliation_case_id)->lockForUpdate()->firstOrFail();
        if ($case->user_id !== $clearance->user_id || $case->source_credit_transaction_id !== $clearance->source_credit_transaction_id
            || $clearance->status !== 'paid' || $paidEntry->reference_key !== 'wallet_clearance:'.$clearance->id.':paid'
            || ! app(PaymentAttentionCases::class)->paidEvidenceMatches($clearance)) {
            throw new DomainException('Wallet clearance result does not match its original source.');
        }
        $key = 'clearance:'.$clearance->id.':paid';
        if (ReconciliationEntry::where('case_id', $case->id)->where('request_key', $key)->exists()) {
            return;
        }
        $totals = app(WalletClearanceEligibility::class)->sourceTotals($case);
        $case->increment('revision');
        $this->append($case, $key, 'clearance_paid', $actor, 'Confirmed external settlement of original wallet credit.', $clearance);
        if (Money::eq($totals['paid'], $totals['principal']) && Money::isZero($totals['held'])) {
            $case->forceFill(['status' => 'resolved', 'resolved_at' => now(), 'revision' => $case->revision + 1])->save();
            $this->append($case, 'resolved:'.$clearance->id, 'resolved', $actor, 'Original confirmed wallet credit fully settled.', $clearance);
        } else {
            $case->update(['status' => 'awaiting_clearance', 'resolved_at' => null]);
        }
    }

    private function append(ReconciliationCase $case, string $key, string $action, ?User $actor, string $reason, ?WalletClearance $clearance = null): void
    {
        ReconciliationEntry::create(['case_id' => $case->id, 'sequence' => $case->revision, 'request_key' => $key,
            'action' => $action, 'actor_id' => $actor?->id, 'reason' => $reason,
            'source_credit_transaction_id' => $case->source_credit_transaction_id, 'wallet_clearance_id' => $clearance?->id,
            'evidence' => ['version' => 1, 'source_fingerprint' => $case->source_fingerprint]]);
    }

    private function assertTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new DomainException('Payment attention evidence must be recorded in the financial transaction.');
        }
    }
}
