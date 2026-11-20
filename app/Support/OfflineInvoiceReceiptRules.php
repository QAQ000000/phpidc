<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\TransactionType;
use App\Models\ExternalCollectionReceipt;
use App\Models\FinancialTransaction;
use App\Models\FulfillmentTask;
use App\Models\Invoice;
use App\Models\OfflineInvoiceReceipt;
use App\Services\AffiliateReceivableBalance;
use DomainException;
use Illuminate\Support\Arr;

/** Local, nonsecret source checks shared by the workbench and locked financial Actions. */
class OfflineInvoiceReceiptRules
{
    public function reason(Invoice $invoice): ?string
    {
        if ($invoice->status !== InvoiceStatus::UNPAID || $invoice->paid_at !== null
            || ($invoice->expires_at !== null && $invoice->expires_at->lte(now()))
            || ! in_array($invoice->type, [InvoiceType::NEW_ORDER, InvoiceType::RENEW, InvoiceType::UPGRADE], true)
            || $invoice->currency !== 'CNY' || Money::isZero($invoice->gateway_amount)) {
            return '仅支持当前未过期、存在外部应付金额的 CNY 未付新购、续费或升级账单。';
        }
        if (($invoice->line_items['automatic_renewal']['selection_pending'] ?? false) === true) {
            return '请先由客户确认自动续费账单的付款分配。';
        }
        if ($invoice->attempts()->exists() || $invoice->callbacks()->exists()) {
            return '账单已有支付尝试或回调，请使用原收款核对流程。';
        }
        if (OfflineInvoiceReceipt::where('invoice_id', $invoice->id)->exists()) {
            return '此账单已有线下收款记录。';
        }
        if (($invoice->type === InvoiceType::NEW_ORDER && ($invoice->instance_id !== null
                || ! is_string($invoice->line_items['purchase']['encrypted_params'] ?? null)))
            || ($invoice->type !== InvoiceType::NEW_ORDER && ($invoice->instance_id === null
                || ! is_string($invoice->line_items['encrypted_operation'] ?? null)))) {
            return '原账单履约快照不完整，请先核对。';
        }
        if (FulfillmentTask::where('invoice_id', $invoice->id)->exists()
            || ($invoice->instance_id !== null && FulfillmentTask::where('instance_id', $invoice->instance_id)
                ->whereIn('status', ['pending', 'running', 'failed'])->exists())) {
            return '账单或服务已有待核对履约任务。';
        }
        if (! $this->originalAllocation($invoice)
            || FinancialTransaction::whereIn('reference_key', ['settle:'.$invoice->id, 'payment:'.$invoice->id,
                'unfreeze:'.$invoice->id, 'refund:'.$invoice->id])->exists()) {
            return '原钱包冻结或账单资金证据不完整，请先核对。';
        }

        return null;
    }

    public function allocation(Invoice $invoice): array
    {
        return ['version' => 1, 'invoice_id' => $invoice->id, 'user_id' => $invoice->user_id,
            'product_id' => $invoice->product_id, 'instance_id' => $invoice->instance_id,
            'type' => $invoice->type->value, 'currency' => $invoice->currency,
            'total_amount' => $invoice->total_amount, 'credit_applied' => $invoice->credit_applied,
            'gateway_amount' => $invoice->gateway_amount, 'discount_amount' => $invoice->discount_amount,
            'gateway' => $invoice->gateway];
    }

    /** Caller holds the invoice and its original user lock; financial facts are append-only. */
    public function refundSource(Invoice $invoice): array
    {
        $receipt = OfflineInvoiceReceipt::where('invoice_id', $invoice->id)->first();
        $payment = FinancialTransaction::where('reference_key', 'payment:'.$invoice->id)->first();
        if ($receipt === null) {
            if (($payment?->meta['source'] ?? null) === 'offline_invoice_receipt') {
                throw new DomainException('Offline payment source is missing; reconcile the original receipt before refund.');
            }

            return [];
        }
        $settlement = FinancialTransaction::find($receipt->settlement_transaction_id);
        $task = FulfillmentTask::find($receipt->fulfillment_task_id);
        $collection = ExternalCollectionReceipt::where('offline_invoice_receipt_id', $receipt->id)->first();
        $action = $invoice->type === InvoiceType::NEW_ORDER ? 'provision' : $invoice->type->value;
        if ($receipt->user_id !== $invoice->user_id || $receipt->currency !== $invoice->currency
            || ! in_array($receipt->external_channel, ['bank_transfer', 'cash'], true)
            || ! Money::eq($receipt->amount, $invoice->gateway_amount)
            || Arr::sortRecursive($receipt->allocation_snapshot) !== Arr::sortRecursive($this->allocation($invoice)) || ! $this->originalAllocation($invoice)
            || $payment === null || $payment->id !== $receipt->payment_transaction_id
            || $payment->user_id !== $invoice->user_id || $payment->invoice_id !== $invoice->id
            || $payment->type !== TransactionType::PAYMENT || ! Money::eq($payment->amount, $receipt->amount)
            || ($payment->meta['source'] ?? null) !== 'offline_invoice_receipt'
            || ($payment->meta['request_id'] ?? null) !== $receipt->request_id
            || ($payment->meta['currency'] ?? null) !== $receipt->currency
            || $settlement === null || $settlement->user_id !== $invoice->user_id || $settlement->invoice_id !== $invoice->id
            || $settlement->type !== TransactionType::CREDIT_SETTLE || ! Money::eq($settlement->amount, $invoice->credit_applied)
            || $settlement->reference_key !== 'settle:'.$invoice->id
            || ($settlement->meta['source'] ?? null) !== 'offline_invoice_receipt'
            || ($settlement->meta['request_id'] ?? null) !== $receipt->request_id
            || ($settlement->meta['currency'] ?? null) !== $receipt->currency
            || $collection === null || $collection->receivable_settlement_id !== null
            || $collection->user_id !== $receipt->user_id || $collection->registered_by !== $receipt->processed_by
            || $collection->currency !== $receipt->currency || ! Money::eq($collection->amount, $receipt->amount)
            || $collection->external_channel !== $receipt->external_channel || $collection->external_reference !== $receipt->external_reference
            || $collection->active_receipt_key !== AffiliateReceivableBalance::receiptKey($receipt->external_channel, $receipt->external_reference)
            || $collection->released_at !== null
            || FinancialTransaction::where('reference_key', 'unfreeze:'.$invoice->id)->exists()
            || $task === null || $task->invoice_id !== $invoice->id || $task->user_id !== $invoice->user_id
            || $task->instance_id !== $invoice->instance_id || $task->action !== $action
            || $task->idempotency_key !== $invoice->id.':'.$action.':'.$invoice->instance_id) {
            throw new DomainException('Offline payment allocation or source evidence changed; reconcile before refund.');
        }

        return ['offline_receipt_id' => $receipt->id, 'source_payment_transaction_id' => $payment->id,
            'source_settlement_transaction_id' => $settlement->id];
    }

    private function originalAllocation(Invoice $invoice): bool
    {
        if (! Money::eq(Money::add($invoice->credit_applied, $invoice->gateway_amount), $invoice->total_amount)) {
            return false;
        }
        $freeze = FinancialTransaction::where('reference_key', 'freeze:'.$invoice->id)->first();

        return Money::isZero($invoice->credit_applied) ? $freeze === null : $freeze !== null
            && $freeze->user_id === $invoice->user_id && $freeze->invoice_id === $invoice->id
            && $freeze->type === TransactionType::CREDIT_FREEZE && Money::eq($freeze->amount, $invoice->credit_applied);
    }
}
