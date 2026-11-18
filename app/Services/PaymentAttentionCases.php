<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\PaymentPostingResult;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentCallbackOutcome;
use App\Enums\PaymentPostingKind;
use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\FulfillmentTask;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\PaymentCallback;
use App\Models\ReconciliationCase;
use App\Models\ReconciliationEntry;
use App\Models\User;
use App\Models\WalletClearance;
use App\Support\Money;
use DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

class PaymentAttentionCases
{
    public function operationInput(array $input): array
    {
        $data = validator($input, ['request_id' => ['required', 'ulid'], 'expected_revision' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:255'], 'evidence' => ['required', 'string', 'max:1000'],
            'confirm' => ['required', 'accepted']])->validate();
        $data['request_id'] = Str::upper($data['request_id']);
        $data['expected_revision'] = (int) $data['expected_revision'];
        $data['reason'] = trim($data['reason']);
        $data['evidence'] = trim($data['evidence']);
        if ($data['reason'] === '' || $data['evidence'] === '') {
            throw ValidationException::withMessages(['operation' => '必须提供明确原因、核对证据及确认。']);
        }

        return $data;
    }

    public function operationIntent(User $actor, array $data, string $operation): array
    {
        return ['actor_id' => $actor->id, 'operation' => $operation, 'expected_revision' => $data['expected_revision'],
            'reason' => $data['reason'], 'evidence' => $data['evidence'], 'confirmed' => true];
    }

    public function operationReplayed(ReconciliationCase $case, User $actor, array $data, string $operation): bool
    {
        $entry = ReconciliationEntry::where('case_id', $case->id)->where('request_key', 'manual:'.$data['request_id'])->first();
        if ($entry === null) {
            return false;
        }
        if (Arr::sortRecursive($entry->evidence['intent'] ?? []) !== Arr::sortRecursive($this->operationIntent($actor, $data, $operation))) {
            throw ValidationException::withMessages(['request_id' => '同一核对请求不能变更操作、版本、原因或证据。']);
        }

        return true;
    }

    public function assertRevision(ReconciliationCase $case, array $data): void
    {
        if ($case->revision !== $data['expected_revision']) {
            throw ValidationException::withMessages(['expected_revision' => '案件版本已变化，请重新读取当前证据后确认。']);
        }
    }

    /** The original accepted protocol facts and original attention must both still agree. */
    public function assertCallbackSource(ReconciliationCase $case): PaymentCallback
    {
        $callback = PaymentCallback::find($case->payment_callback_id);
        $invoice = $callback === null ? null : Invoice::find($callback->invoice_id);
        if ($case->kind !== 'payment_attention' || $case->source_type !== 'payment_callback'
            || ! in_array($case->initial_reason, ['wallet_capacity', 'closed_account'], true)
            || $callback === null || $invoice === null || $case->source_id !== (string) $callback->id
            || $invoice->user_id !== $case->user_id || $callback->gateway !== $invoice->gateway || $invoice->currency !== 'CNY'
            || ! hash_equals($case->source_fingerprint, $this->callbackFingerprint($callback, $invoice))) {
            throw new DomainException('The original verified callback identity requires reconciliation.');
        }
        $matches = false;
        foreach (DB::table('audit_logs')->where('action', 'payment.callback.attention')->where('subject_type', PaymentCallback::class)
            ->where('subject_id', $callback->id)->get(['after']) as $audit) {
            $facts = json_decode($audit->after, true, flags: JSON_THROW_ON_ERROR);
            if (is_array($facts) && ($facts['reason'] ?? null) === $case->initial_reason
                && ($facts['outcome'] ?? null) === $callback->outcome->value && ($facts['invoice_id'] ?? null) === $invoice->id
                && is_string($facts['amount'] ?? null) && preg_match('/^\d+\.\d{2}$/D', $facts['amount'])
                && Money::eq($facts['amount'], $callback->amount)) {
                $matches = true;
            }
        }
        if (! $matches) {
            throw new DomainException('The original payment attention evidence does not match.');
        }

        return $callback;
    }

    /** @return array<string, mixed> */
    public function postingSnapshot(PaymentPostingResult $result, Invoice $invoice): array
    {
        if (! $result->posted()) {
            throw new DomainException('An unsuccessful posting is not settlement evidence.');
        }

        return ['version' => 1, 'kind' => $result->kind?->value, 'outcome' => $result->outcome->value,
            'closed_owner' => $result->closedOwner, 'invoice_status_before' => $result->invoiceStatusBefore?->value,
            'invoice' => ['id' => $invoice->id, 'user_id' => $invoice->user_id, 'type' => $invoice->type->value,
                'currency' => $invoice->currency, 'total_amount' => $invoice->total_amount,
                'credit_applied' => $invoice->credit_applied, 'gateway_amount' => $invoice->gateway_amount],
            'payment_transaction_id' => $result->paymentTransactionId, 'settlement_transaction_id' => $result->settlementTransactionId,
            'principal_credit_transaction_id' => $result->principalCreditTransactionId, 'excess_credit_transaction_id' => $result->excessCreditTransactionId,
            'fulfillment_task_id' => $result->fulfillmentTaskId, 'payment_attempt_id' => $result->paymentAttemptId];
    }

    /** Validate immutable posting facts while allowing ordinary later refund/task completion. */
    public function postingEvidenceMatches(ReconciliationCase $case, ReconciliationEntry $entry): bool
    {
        try {
            return $this->checkPosting($case, $entry);
        } catch (DomainException|ValidationException|DecryptException|JsonException) {
            return false;
        }
    }

    /** Existing real results may be reconfirmed, but never posted a second time. */
    public function existingResolutionEvidence(ReconciliationCase $case): ?array
    {
        $posting = ReconciliationEntry::where('case_id', $case->id)->where('action', 'payment_posted')->orderByDesc('sequence')->first();
        if ($posting !== null) {
            if (! $this->postingEvidenceMatches($case, $posting)) {
                return null;
            }
            if (($posting->evidence['posting']['closed_owner'] ?? null) === false) {
                return ['resolution' => 'payment_posted', 'posting_entry_id' => $posting->id];
            }
        }
        try {
            $source = $this->assertSource($case);
            $totals = app(WalletClearanceEligibility::class)->sourceTotals($case);
            if (Money::eq($totals['paid'], $source->amount) && Money::isZero($totals['held'])) {
                return ['resolution' => 'wallet_cleared'];
            }
        } catch (DomainException|ValidationException|DecryptException|JsonException) {
            return null;
        }

        return null;
    }

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
        $sourceType = $case->source_type === 'wallet_release' ? TransactionType::CREDIT_UNFREEZE : TransactionType::CREDIT_IN;
        if ($case->kind !== 'payment_attention' || ! in_array($case->initial_reason, ['wallet_capacity', 'closed_account'], true)
            || $credit === null || $credit->type !== $sourceType || $credit->user_id !== $case->user_id) {
            throw new DomainException('The original wallet credit requires reconciliation.');
        }
        $invoice = Invoice::find($credit->invoice_id);
        if ($invoice === null || $invoice->user_id !== $case->user_id || $invoice->currency !== 'CNY') {
            throw new DomainException('The original credit invoice does not match the financial identity.');
        }
        if ($case->source_type === 'wallet_release') {
            $freeze = FinancialTransaction::where('reference_key', 'freeze:'.$invoice->id)->first();
            if ($case->payment_callback_id !== null || $case->source_id !== (string) $credit->id
                || $case->initial_reason !== 'closed_account' || $credit->reference_key !== 'unfreeze:'.$invoice->id
                || ! in_array($invoice->status, [InvoiceStatus::EXPIRED, InvoiceStatus::CANCELLED], true) || $invoice->paid_at !== null
                || Money::isZero($credit->amount) || ! Money::eq($credit->amount, $invoice->credit_applied)
                || $freeze === null || $freeze->type !== TransactionType::CREDIT_FREEZE || $freeze->user_id !== $case->user_id
                || $freeze->invoice_id !== $invoice->id || ! Money::eq($freeze->amount, $credit->amount)
                || ! Money::eq(Money::add($invoice->credit_applied, $invoice->gateway_amount), $invoice->total_amount)
                || FinancialTransaction::whereIn('reference_key', ['settle:'.$invoice->id, 'payment:'.$invoice->id, 'refund:'.$invoice->id])->exists()
                || ! hash_equals($case->source_fingerprint, $this->creditFingerprint($credit))) {
                throw new DomainException('The original expired wallet reservation release is not verified.');
            }
        } elseif ($case->source_type === 'wallet_credit') {
            if ($case->payment_callback_id !== null || $case->source_id !== (string) $credit->id
                || $credit->reference_key !== 'refund:'.$invoice->id || ($credit->meta['mode'] ?? null) !== 'refund_wallet'
                || ($credit->meta['currency'] ?? null) !== 'CNY' || ! Money::eq($credit->amount, $invoice->total_amount)
                || FinancialTransaction::where('reference_key', 'unfreeze:'.$invoice->id)->exists()
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
            $posting = ReconciliationEntry::where('case_id', $case->id)->where('action', 'payment_posted')->orderByDesc('sequence')->first();
            if ($posting !== null && ! $this->postingEvidenceMatches($case, $posting)) {
                throw new DomainException('The reconciled receipt posting evidence no longer matches.');
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
            $entry = ReconciliationEntry::where('case_id', $case->id)->orderByDesc('sequence')->first();
            if ($entry === null || $entry->action !== 'resolved' || $entry->sequence !== $case->revision
                || $entry->source_credit_transaction_id !== $case->source_credit_transaction_id) {
                return false;
            }
            if (($entry->evidence['resolution'] ?? null) === 'payment_posted') {
                $posting = ReconciliationEntry::find($entry->evidence['posting_entry_id'] ?? null);

                return $posting !== null && ($posting->evidence['posting']['closed_owner'] ?? null) === false
                    && $this->postingEvidenceMatches($case, $posting);
            }
            $posting = ReconciliationEntry::where('case_id', $case->id)->where('action', 'payment_posted')->orderByDesc('sequence')->first();
            if ($posting !== null && ! $this->postingEvidenceMatches($case, $posting)) {
                return false;
            }
            $source = $this->assertSource($case);
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

    private function checkPosting(ReconciliationCase $case, ReconciliationEntry $entry): bool
    {
        if ($entry->case_id !== $case->id || $entry->action !== 'payment_posted' || $entry->sequence > $case->revision
            || ($entry->evidence['source_fingerprint'] ?? null) !== $case->source_fingerprint) {
            return false;
        }
        $facts = $entry->evidence['posting'] ?? null;
        if (! is_array($facts) || ($facts['version'] ?? null) !== 1 || ! is_string($facts['kind'] ?? null)
            || ! is_bool($facts['closed_owner'] ?? null) || ! is_array($facts['invoice'] ?? null)) {
            return false;
        }
        $callback = $this->assertCallbackSource($case);
        $invoice = Invoice::findOrFail($callback->invoice_id);
        $expectedInvoice = ['id' => $invoice->id, 'user_id' => $invoice->user_id, 'type' => $invoice->type->value,
            'currency' => $invoice->currency, 'total_amount' => $invoice->total_amount,
            'credit_applied' => $invoice->credit_applied, 'gateway_amount' => $invoice->gateway_amount];
        if (Arr::sortRecursive($facts['invoice']) !== Arr::sortRecursive($expectedInvoice)) {
            return false;
        }
        $kind = PaymentPostingKind::tryFrom($facts['kind']);
        $ids = ['payment_transaction_id', 'settlement_transaction_id', 'principal_credit_transaction_id',
            'excess_credit_transaction_id', 'fulfillment_task_id', 'payment_attempt_id'];
        foreach ($ids as $key) {
            if (! array_key_exists($key, $facts) || ($facts[$key] !== null && (! is_int($facts[$key]) || $facts[$key] < 1))) {
                return false;
            }
        }
        if ($kind === PaymentPostingKind::WALLET_RECEIPT) {
            $before = $facts['invoice_status_before'] ?? null;
            if (! in_array($before, [InvoiceStatus::PAID->value, InvoiceStatus::EXPIRED->value, InvoiceStatus::CANCELLED->value], true)
                && ! ($before === InvoiceStatus::UNPAID->value && $facts['closed_owner'] === true)) {
                return false;
            }

            return ($facts['outcome'] ?? null) === ($before === InvoiceStatus::PAID->value ? PaymentCallbackOutcome::PROCESSED_OVERPAYMENT->value : PaymentCallbackOutcome::PROCESSED_LATE_CREDIT->value)
                && $facts['payment_transaction_id'] === null && $facts['settlement_transaction_id'] === null
                && $facts['excess_credit_transaction_id'] === null && $facts['fulfillment_task_id'] === null && $facts['payment_attempt_id'] === null
                && $case->source_credit_transaction_id === $facts['principal_credit_transaction_id']
                && $this->ledgerMatches($facts['principal_credit_transaction_id'], $invoice, TransactionType::CREDIT_IN, $callback->amount, 'credit_in:callback:'.$callback->id);
        }
        if (! in_array($kind, [PaymentPostingKind::INVOICE_SETTLEMENT, PaymentPostingKind::WALLET_TOPUP], true)
            || $facts['closed_owner'] !== false || ($facts['invoice_status_before'] ?? null) !== InvoiceStatus::UNPAID->value
            || ! in_array($invoice->status, [InvoiceStatus::PAID, InvoiceStatus::REFUNDED], true) || $invoice->paid_at === null
            || Money::gt($invoice->gateway_amount, $callback->amount)
            || ! Money::eq(Money::add($invoice->credit_applied, $invoice->gateway_amount), $invoice->total_amount)
            || ! $this->ledgerMatches($facts['payment_transaction_id'], $invoice, TransactionType::PAYMENT, $invoice->gateway_amount, 'payment:'.$invoice->id)) {
            return false;
        }
        $excess = Money::sub($callback->amount, $invoice->gateway_amount);
        if (($facts['outcome'] ?? null) !== (Money::isZero($excess) ? PaymentCallbackOutcome::PROCESSED_PAID->value : PaymentCallbackOutcome::PROCESSED_OVERPAYMENT->value)
            || (Money::isZero($excess) ? $facts['excess_credit_transaction_id'] !== null
                : ! $this->ledgerMatches($facts['excess_credit_transaction_id'], $invoice, TransactionType::CREDIT_IN, $excess, 'overpaid:'.$callback->id))
            || $case->source_credit_transaction_id !== $facts['excess_credit_transaction_id']) {
            return false;
        }
        if ($facts['payment_attempt_id'] !== null) {
            $attempt = PaymentAttempt::find($facts['payment_attempt_id']);
            if ($attempt === null || $attempt->invoice_id !== $invoice->id || $attempt->gateway !== $callback->gateway
                || $attempt->gateway_trade_no !== $callback->gateway_trade_no || $attempt->status !== 'paid' || $attempt->paid_at === null) {
                return false;
            }
        }
        if ($kind === PaymentPostingKind::WALLET_TOPUP) {
            return $invoice->type === InvoiceType::WALLET_TOPUP && $invoice->status === InvoiceStatus::PAID
                && $facts['settlement_transaction_id'] === null && $facts['fulfillment_task_id'] === null
                && $this->ledgerMatches($facts['principal_credit_transaction_id'], $invoice, TransactionType::CREDIT_IN, $invoice->gateway_amount, 'topup:'.$invoice->id);
        }
        $task = FulfillmentTask::find($facts['fulfillment_task_id']);
        $action = $invoice->type === InvoiceType::NEW_ORDER ? 'provision' : $invoice->type->value;

        return in_array($invoice->type, [InvoiceType::NEW_ORDER, InvoiceType::RENEW, InvoiceType::UPGRADE], true)
            && $facts['principal_credit_transaction_id'] === null
            && $this->ledgerMatches($facts['settlement_transaction_id'], $invoice, TransactionType::CREDIT_SETTLE, $invoice->credit_applied, 'settle:'.$invoice->id)
            && $task !== null && $task->invoice_id === $invoice->id && $task->user_id === $invoice->user_id
            && $task->instance_id !== null && $task->instance_id === $invoice->instance_id && $task->action === $action
            && $task->idempotency_key === $invoice->id.':'.$action.':'.$invoice->instance_id;
    }

    private function ledgerMatches(?int $id, Invoice $invoice, TransactionType $type, string $amount, string $reference): bool
    {
        $entry = $id === null ? null : FinancialTransaction::find($id);

        return $entry !== null && $entry->user_id === $invoice->user_id && $entry->invoice_id === $invoice->id
            && $entry->type === $type && $entry->reference_key === $reference && Money::eq($entry->amount, $amount);
    }
}
