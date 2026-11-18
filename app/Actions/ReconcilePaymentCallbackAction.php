<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\PaymentPostingResult;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentCallbackOutcome;
use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\PaymentCallback;
use App\Models\ReconciliationCase;
use App\Models\ReconciliationEntry;
use App\Models\User;
use App\Services\NotificationManager;
use App\Services\PaymentAttentionCases;
use App\Support\Money;
use App\Support\OperationsAudit;
use App\Support\RenewalRecovery;
use DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use JsonException;

/** Explicit reconciliation of an existing verified receipt; never a replay of its webhook. */
class ReconcilePaymentCallbackAction
{
    public function execute(ReconciliationCase $case, User $actor, array $input): ReconciliationCase
    {
        $proof = app(PaymentAttentionCases::class);
        $data = $proof->operationInput($input);
        $hint = ReconciliationCase::findOrFail($case->id);

        try {
            return DB::transaction(function () use ($hint, $actor, $data, $proof): ReconciliationCase {
                $users = User::withTrashed()->whereKey([$hint->user_id, $actor->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $owner = $users->get($hint->user_id);
                $operator = $users->get($actor->id);
                abort_unless($owner !== null && $operator !== null && ! $operator->trashed() && $operator->anonymized_at === null, 403);
                Gate::forUser($operator)->authorize('finance.view');
                Gate::forUser($operator)->authorize('finance.reconcile');
                $current = ReconciliationCase::findOrFail($hint->id);
                if ($current->kind !== 'payment_attention' || $current->user_id !== $owner->id
                    || ! in_array($current->initial_reason, ['wallet_capacity', 'closed_account'], true)
                    || ! in_array($current->status, ['open', 'awaiting_clearance', 'resolved'], true)) {
                    throw new DomainException('Unsupported payment reconciliation source or state.');
                }
                if ($proof->operationReplayed($current, $operator, $data, 'payment')) {
                    return $current;
                }
                $proof->assertRevision($current, $data);
                $callback = $current->source_type === 'payment_callback' ? $proof->assertCallbackSource($current) : null;
                $credit = $current->source_credit_transaction_id === null ? null : FinancialTransaction::find($current->source_credit_transaction_id);
                $invoiceId = $callback === null ? $credit?->invoice_id : $callback->invoice_id;
                if ($invoiceId === null) {
                    throw new DomainException('The original financial invoice is not verifiable.');
                }
                $candidate = Invoice::findOrFail($invoiceId);
                $family = $candidate->type === InvoiceType::RENEW ? app(RenewalRecovery::class)->rows($candidate, true) : null;
                $invoice = $family === null ? Invoice::whereKey($candidate->id)->lockForUpdate()->firstOrFail() : $family->firstWhere('id', $candidate->id);
                if ($invoice === null || $invoice->user_id !== $owner->id) {
                    throw new DomainException('The original invoice financial owner changed.');
                }
                $attempts = PaymentAttempt::where('invoice_id', $invoice->id)->orderBy('id')->lockForUpdate()->get();
                $before = ['status' => $current->status, 'revision' => $current->revision];
                $record = app(RecordPaymentAttentionCaseAction::class);
                // An existing original result can only be verified; it can never receive money again.
                if ($current->source_credit_transaction_id !== null
                    || ReconciliationEntry::where('case_id', $current->id)->where('action', 'payment_posted')->exists()) {
                    $updated = $record->confirmExisting($current, $operator, $data);
                    $this->recordOutcome($updated, $operator, $data, $before, $invoice);

                    return $updated;
                }
                if ($callback === null || $callback->outcome !== PaymentCallbackOutcome::REJECTED_INVALID_STATE || $current->status !== 'open') {
                    throw new DomainException('Only the original unposted capacity or closed-account receipt may be posted.');
                }
                if (FinancialTransaction::whereIn('reference_key', ['credit_in:callback:'.$callback->id, 'overpaid:'.$callback->id])->exists()) {
                    throw new DomainException('A receipt credit already exists and requires evidence reconciliation.');
                }
                $outTradeNo = $callback->raw_payload['out_trade_no'] ?? null;
                $attempt = null;
                if ($outTradeNo !== null) {
                    if (! is_string($outTradeNo) || trim($outTradeNo) === '') {
                        throw new DomainException('The original merchant order number is invalid.');
                    }
                    $attempt = $attempts->first(fn (PaymentAttempt $row): bool => $row->gateway === $callback->gateway && $row->out_trade_no === $outTradeNo);
                    if ($attempt === null && $outTradeNo !== $invoice->invoice_no) {
                        throw new DomainException('The exact original payment attempt is not verifiable.');
                    }
                    if ($attempt !== null && ! Money::eq($attempt->amount, $invoice->gateway_amount)) {
                        throw new DomainException('The original payment allocation no longer matches its attempt.');
                    }
                }
                if (! $owner->trashed() && $owner->anonymized_at === null && $invoice->status === InvoiceStatus::UNPAID
                    && $invoice->type !== InvoiceType::WALLET_TOPUP) {
                    $this->assertOriginalAllocation($invoice);
                }
                $updated = null;
                app(PostVerifiedPaymentReceiptAction::class)->execute($owner, $invoice, $attempt, $callback,
                    function (PaymentPostingResult $result) use ($record, $current, $operator, $invoice, $data, $before, &$updated): void {
                        if (! $result->posted()) {
                            throw new DomainException('The current invoice or wallet still cannot accept the complete original receipt.');
                        }
                        $updated = $record->posted($current, $operator, $invoice, $result, $data);
                        $this->recordOutcome($updated, $operator, $data, $before, $invoice);
                    }, $family, allowClosedUnpaidCredit: true);

                return $updated ?? throw new DomainException('No complete original payment result was recorded.');
            });
        } catch (DomainException|DecryptException|JsonException|UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['operation' => '原收款、当前账单、资金容量或结案证据尚未完整核实；未进行部分补记，请重新核对。']);
        }
    }

    private function recordOutcome(ReconciliationCase $case, User $actor, array $data, array $before, Invoice $invoice): void
    {
        OperationsAudit::record($actor, 'payment.reconciled', ReconciliationCase::class, $case->id, $data['reason'], $before,
            ['status' => $case->status, 'revision' => $case->revision, 'payment_callback_id' => $case->payment_callback_id]);
        $receipt = PaymentCallback::find($case->payment_callback_id);
        $source = FinancialTransaction::find($case->source_credit_transaction_id);
        $amount = $receipt === null ? ($source === null ? '0.00' : $source->amount) : $receipt->amount;
        app(NotificationManager::class)->queueOperations('reconciliation.updated',
            ['invoice_no' => $invoice->invoice_no, 'amount' => $amount,
                'currency' => $invoice->currency, 'status' => $case->status], 'reconciliation-case:'.$case->id.':'.$case->revision);
    }

    private function assertOriginalAllocation(Invoice $invoice): void
    {
        $freeze = FinancialTransaction::where('reference_key', 'freeze:'.$invoice->id)->first();
        if (! Money::eq(Money::add($invoice->credit_applied, $invoice->gateway_amount), $invoice->total_amount)
            || (Money::isZero($invoice->credit_applied) ? $freeze !== null : ($freeze === null
                || $freeze->user_id !== $invoice->user_id || $freeze->invoice_id !== $invoice->id
                || $freeze->type !== TransactionType::CREDIT_FREEZE || ! Money::eq($freeze->amount, $invoice->credit_applied)))
            || FinancialTransaction::whereIn('reference_key', ['settle:'.$invoice->id, 'payment:'.$invoice->id, 'unfreeze:'.$invoice->id])->exists()) {
            throw new DomainException('The original invoice reservation or financial identity requires reconciliation.');
        }
    }
}
