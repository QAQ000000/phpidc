<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\PaymentPostingResult;
use App\Enums\PaymentPostingKind;
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
    /** Called by the financial posting hook, before any notification records are acquired. */
    public function posted(ReconciliationCase $hint, User $actor, Invoice $invoice, PaymentPostingResult $result, array $data): ReconciliationCase
    {
        $case = $this->operationCase($hint, $data);
        $proof = app(PaymentAttentionCases::class);
        $sourceId = $result->kind === PaymentPostingKind::WALLET_RECEIPT ? $result->principalCreditTransactionId : $result->excessCreditTransactionId;
        $case->forceFill(['source_credit_transaction_id' => $sourceId, 'status' => $result->closedOwner ? 'awaiting_clearance' : 'open',
            'resolved_at' => null, 'revision' => $case->revision + 1])->save();
        $entry = $this->manualEntry($case, $actor, $data, 'payment_posted', 'payment',
            ['posting' => $proof->postingSnapshot($result, $invoice)]);
        if (! $proof->postingEvidenceMatches($case, $entry)) {
            throw new DomainException('The posted payment result does not match its original financial evidence.');
        }
        if (! $result->closedOwner) {
            $this->resolve($case, $actor, $data, ['resolution' => 'payment_posted', 'posting_entry_id' => $entry->id]);
        } elseif ($sourceId !== null && Money::isZero($proof->assertSource($case)->amount)) {
            $this->resolve($case, $actor, $data, ['resolution' => 'wallet_cleared']);
        }

        return $case;
    }

    public function confirmExisting(ReconciliationCase $hint, User $actor, array $data): ReconciliationCase
    {
        $case = $this->operationCase($hint, $data);
        $evidence = app(PaymentAttentionCases::class)->existingResolutionEvidence($case);
        if ($evidence === null) {
            throw new DomainException('The original posting or full external wallet clearance is not verified.');
        }
        $case->increment('revision');
        $this->manualEntry($case, $actor, $data, 'result_verified', 'payment', $evidence);
        $this->resolve($case, $actor, $data, $evidence);

        return $case;
    }

    public function reopen(ReconciliationCase $hint, User $actor, array $data): ReconciliationCase
    {
        $case = $this->operationCase($hint, $data);
        if ($case->status !== 'resolved' || app(PaymentAttentionCases::class)->isResolved($case)) {
            throw new DomainException('Only a resolved case whose actual evidence no longer verifies may reopen.');
        }
        $case->forceFill(['status' => 'open', 'resolved_at' => null, 'revision' => $case->revision + 1])->save();
        $this->manualEntry($case, $actor, $data, 'reopened', 'reopen');

        return $case;
    }

    /** Called after financial writes, while the original owner is locked. */
    public function execute(PaymentCallback $callback, string $reason): ReconciliationCase
    {
        $invoice = Invoice::findOrFail($callback->invoice_id);

        return $this->record($callback, $reason, app(PaymentAttentionCases::class)->callbackSourceSnapshot($callback, $invoice, $reason));
    }

    /** Upgrade-only import: NULL means the original attention proof was incomplete, never inferred. */
    public function importHistorical(PaymentCallback $callback, string $reason, ?array $snapshot): ReconciliationCase
    {
        return $this->record($callback, $reason, $snapshot);
    }

    private function record(PaymentCallback $callback, string $reason, ?array $snapshot): ReconciliationCase
    {
        $this->assertTransaction();
        if (! in_array($reason, ['wallet_capacity', 'closed_account'], true)) {
            throw new DomainException('Unsupported payment attention source.');
        }
        $invoice = Invoice::findOrFail($callback->invoice_id);
        $proof = app(PaymentAttentionCases::class);
        $case = ReconciliationCase::where('source_type', 'payment_callback')->where('source_id', (string) $callback->id)->lockForUpdate()->first();
        if ($case !== null) {
            if ($case->kind !== 'payment_attention' || $case->payment_callback_id !== $callback->id || $case->initial_reason !== $reason
                || $case->user_id !== $invoice->user_id || ! hash_equals($case->source_fingerprint, $proof->callbackFingerprint($callback, $invoice))) {
                throw new DomainException('Payment attention source changed; manual reconciliation is required.');
            }
            if ($case->source_snapshot !== null) {
                $proof->assertCallbackSource($case);
            } elseif ($snapshot !== null) {
                // A source backfill is not a new disposition: retain timestamps, revision and the final resolved entry.
                DB::table('reconciliation_cases')->where('id', $case->id)->update(['source_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
                $case->setAttribute('source_snapshot', $snapshot);
                $proof->assertCallbackSource($case);
            }

            return $case;
        }
        $credit = FinancialTransaction::whereIn('reference_key', ['credit_in:callback:'.$callback->id, 'overpaid:'.$callback->id])->first();
        $case = ReconciliationCase::create(['user_id' => $invoice->user_id, 'payment_callback_id' => $callback->id,
            'kind' => 'payment_attention', 'source_type' => 'payment_callback', 'source_id' => (string) $callback->id,
            'source_fingerprint' => $proof->callbackFingerprint($callback, $invoice), 'initial_reason' => $reason,
            'status' => $credit === null || $snapshot === null ? 'open' : 'awaiting_clearance',
            'source_credit_transaction_id' => $credit?->id, 'source_snapshot' => $snapshot]);
        if ($credit !== null && $snapshot !== null) {
            $proof->assertSource($case);
        }
        $this->append($case, 'created', 'created', null, $reason);
        if ($credit !== null && $snapshot !== null && Money::isZero($credit->amount)) {
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

    /** An expired reservation is its own original wallet source, not a new deposit. */
    public function releasedCredit(User $owner, FinancialTransaction $release): ?ReconciliationCase
    {
        $this->assertTransaction();
        if ((! $owner->trashed() && $owner->anonymized_at === null) || Money::isZero($release->amount)) {
            return null;
        }
        $existing = ReconciliationCase::where('source_credit_transaction_id', $release->id)->lockForUpdate()->first();
        if ($existing !== null) {
            app(PaymentAttentionCases::class)->assertSource($existing);

            return $existing;
        }
        $case = ReconciliationCase::create(['user_id' => $owner->id, 'kind' => 'payment_attention', 'source_type' => 'wallet_release',
            'source_id' => (string) $release->id, 'source_fingerprint' => app(PaymentAttentionCases::class)->creditFingerprint($release),
            'initial_reason' => 'closed_account', 'status' => 'awaiting_clearance', 'source_credit_transaction_id' => $release->id]);
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

    private function operationCase(ReconciliationCase $hint, array $data): ReconciliationCase
    {
        $this->assertTransaction();
        $case = ReconciliationCase::whereKey($hint->id)->lockForUpdate()->firstOrFail();
        if ($case->kind !== 'payment_attention' || $case->user_id !== $hint->user_id || $case->source_type !== $hint->source_type
            || $case->source_id !== $hint->source_id || $case->source_fingerprint !== $hint->source_fingerprint
            || ! in_array($case->initial_reason, ['wallet_capacity', 'closed_account'], true)) {
            throw new DomainException('The original payment case identity changed.');
        }
        app(PaymentAttentionCases::class)->assertRevision($case, $data);

        return $case;
    }

    private function manualEntry(ReconciliationCase $case, User $actor, array $data, string $action, string $operation, array $evidence = []): ReconciliationEntry
    {
        return ReconciliationEntry::create(['case_id' => $case->id, 'sequence' => $case->revision,
            'request_key' => 'manual:'.$data['request_id'], 'action' => $action, 'actor_id' => $actor->id, 'reason' => $data['reason'],
            'source_credit_transaction_id' => $case->source_credit_transaction_id,
            'evidence' => ['version' => 1, 'source_fingerprint' => $case->source_fingerprint,
                'intent' => app(PaymentAttentionCases::class)->operationIntent($actor, $data, $operation), ...$evidence]]);
    }

    private function resolve(ReconciliationCase $case, User $actor, array $data, array $evidence): void
    {
        $case->forceFill(['status' => 'resolved', 'resolved_at' => now(), 'revision' => $case->revision + 1])->save();
        ReconciliationEntry::create(['case_id' => $case->id, 'sequence' => $case->revision,
            'request_key' => 'manual:'.$data['request_id'].':resolved', 'action' => 'resolved', 'actor_id' => $actor->id,
            'reason' => $data['reason'], 'source_credit_transaction_id' => $case->source_credit_transaction_id,
            'evidence' => ['version' => 1, 'source_fingerprint' => $case->source_fingerprint, ...$evidence]]);
        if (! app(PaymentAttentionCases::class)->isResolved($case)) {
            throw new DomainException('Actual payment evidence cannot authorize this conclusion.');
        }
    }
}
