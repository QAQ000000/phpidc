<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\OfflineInvoiceReceipt;
use App\Models\User;
use App\Support\AdminFinancialAccess;
use App\Support\ExternalCollectionReceipts;
use App\Support\Money;
use App\Support\OfflineInvoiceReceiptRules;
use App\Support\OperationsAudit;
use App\Support\RenewalRecovery;
use App\Support\WalletCapacity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class RecordOfflineInvoiceReceiptAction
{
    public function execute(Invoice $invoice, User $actor, array $input): OfflineInvoiceReceipt
    {
        foreach (['request_id', 'external_reference'] as $key) {
            if (is_string($input[$key] ?? null)) {
                $input[$key] = Str::upper(trim($input[$key]));
            }
        }
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim($input['reason']);
        }
        $input['currency'] ??= 'CNY';
        $data = validator($input, ['request_id' => ['required', 'ulid'],
            'amount' => ['required', 'string', 'regex:/^\d{1,10}\.\d{2}$/D'], 'currency' => ['required', 'in:CNY'],
            'external_channel' => ['required', 'in:bank_transfer,cash'],
            'external_reference' => ['required', 'regex:/^[A-Z0-9][A-Z0-9._:\/-]{0,127}$/D'],
            'received_at' => ['required', 'date', 'before_or_equal:now'],
            'reason' => ['nullable', 'string', 'max:255'], 'evidence' => ['nullable', 'string', 'max:2000'],
            'confirm' => ['required', 'accepted']])->validate();
        $data['amount'] = Money::of($data['amount']);
        $data['reason'] = trim($data['reason'] ?? '') ?: '登记已实际收到的线下账单款项';
        $data['received_at'] = Carbon::parse($data['received_at'])->setTimezone(config('app.timezone'))->startOfSecond();
        $evidence = $data['evidence'] ?? null;
        $evidence = $evidence === '' ? null : $evidence;
        $hint = Invoice::findOrFail($invoice->id);
        $intent = ['version' => 1, 'invoice_id' => $hint->id, 'user_id' => $hint->user_id, 'actor_id' => $actor->id,
            ...Arr::only($data, ['amount', 'currency', 'external_channel', 'external_reference', 'reason']),
            'received_at' => $data['received_at']->toIso8601String()];
        try {
            return DB::transaction(function () use ($hint, $actor, $data, $intent, $evidence): OfflineInvoiceReceipt {
                $access = app(AdminFinancialAccess::class);
                ['actor' => $operator, 'target' => $owner] = $access->lock(User::withTrashed()->findOrFail($hint->user_id), $actor, 'finance.offline-receipt');
                $family = $hint->type === InvoiceType::RENEW ? app(RenewalRecovery::class)->rows($hint, true) : null;
                $locked = $family === null ? Invoice::whereKey($hint->id)->lockForUpdate()->firstOrFail() : $family->firstWhere('id', $hint->id);
                if ($locked === null || $locked->user_id !== $owner->id || $locked->user_id !== $hint->user_id) {
                    $this->reject('账单原资金归属已变化，请重新核对。');
                }
                $existing = OfflineInvoiceReceipt::where('request_id', $data['request_id'])->first();
                if ($existing !== null) {
                    if (Arr::sortRecursive($existing->intent) !== Arr::sortRecursive($intent)
                        || $existing->encrypted_evidence !== $evidence) {
                        $this->reject('此线下收款请求已用于不同意图，请核对原记录。');
                    }

                    return $existing;
                }
                abort_unless($access->isManageableTarget($operator, $owner), 403);
                // No attempt, callback or prior task may be hidden by a concurrent settlement.
                $attempts = $locked->attempts()->orderBy('id')->lockForUpdate()->get();
                $instance = $locked->instance_id === null ? null : Instance::whereKey($locked->instance_id)->lockForUpdate()->firstOrFail();
                $tasks = FulfillmentTask::where(fn ($query) => $query->where('invoice_id', $locked->id)
                    ->when($instance !== null, fn ($query) => $query->orWhere('instance_id', $instance->id)))->orderBy('id')->lockForUpdate()->get();
                $rules = app(OfflineInvoiceReceiptRules::class);
                $reason = $rules->reason($locked);
                if ($reason !== null || $attempts->isNotEmpty() || ! Money::eq($data['amount'], $locked->gateway_amount)) {
                    $this->reject($reason ?? '实际收款金额必须准确等于原账单外部应付金额。');
                }
                if ($family !== null) {
                    app(RenewalRecovery::class)->assertPayable($locked, $family);
                }
                if ($instance !== null && ($instance->user_id !== $owner->id || $instance->product_id !== $locked->product_id
                    || ! $instance->status->isBillable() || $instance->currency !== $locked->currency
                    || $tasks->contains(fn (FulfillmentTask $task): bool => in_array($task->status->value, ['pending', 'running', 'failed'], true)))) {
                    $this->reject('服务归属、状态或未决履约已变化，请核对原账单。');
                }
                if (! Money::gte($owner->frozen_credit, $locked->credit_applied)) {
                    $this->reject('原账单冻结余额不足，请先核对。');
                }
                $before = WalletCapacity::snapshot($owner);
                $after = [...$before, 'frozen_credit' => Money::sub($owner->frozen_credit, $locked->credit_applied)];
                if (! WalletCapacity::allows($before, $after)) {
                    $this->reject('钱包资金状态超出存储范围，请先核对。');
                }
                $owner->forceFill(['frozen_credit' => $after['frozen_credit']])->save();
                $settle = $this->ledger($owner, $locked, TransactionType::CREDIT_SETTLE, $locked->credit_applied, 'settle:'.$locked->id, $data['request_id']);
                $payment = $this->ledger($owner, $locked, TransactionType::PAYMENT, $locked->gateway_amount, 'payment:'.$locked->id, $data['request_id']);
                $locked->update(['status' => InvoiceStatus::PAID, 'paid_at' => now()]);
                $receipt = null;
                $record = function (FulfillmentTask $task) use ($operator, $owner, $locked, $rules, $data, $intent, $evidence, $payment, $settle, &$receipt): void {
                    $receipt = OfflineInvoiceReceipt::create(['request_id' => $data['request_id'], 'invoice_id' => $locked->id,
                        'user_id' => $owner->id, 'processed_by' => $operator->id, 'amount' => $data['amount'], 'currency' => 'CNY',
                        'external_channel' => $data['external_channel'], 'external_reference' => $data['external_reference'],
                        'received_at' => $data['received_at'], 'reason' => $data['reason'], 'encrypted_evidence' => $evidence,
                        'intent' => $intent, 'allocation_snapshot' => $rules->allocation($locked),
                        'payment_transaction_id' => $payment->id, 'settlement_transaction_id' => $settle->id, 'fulfillment_task_id' => $task->id]);
                    app(ExternalCollectionReceipts::class)->claimOffline($receipt);
                    OperationsAudit::record($operator, 'invoice.offline_receipt_recorded', Invoice::class, $locked->id, $data['reason'],
                        ['status' => InvoiceStatus::UNPAID->value], ['status' => InvoiceStatus::PAID->value,
                            'amount' => $receipt->amount, 'offline_receipt_id' => $receipt->id,
                            'payment_transaction_id' => $payment->id, 'settlement_transaction_id' => $settle->id,
                            'fulfillment_task_id' => $task->id]);
                };
                if ($locked->type === InvoiceType::NEW_ORDER) {
                    app(CreatePurchaseFulfillmentAction::class)->execute($locked, $record);
                } else {
                    app(CreateServiceFulfillmentAction::class)->execute($locked, $record);
                }

                return $receipt ?? throw new LogicException('Offline settlement must retain its complete receipt.');
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            $this->reject('此请求、账单或外部收款凭证已登记，请刷新核对原记录。');
        }
    }

    private function ledger(User $owner, Invoice $invoice, TransactionType $type, string $amount, string $key, string $requestId): FinancialTransaction
    {
        return FinancialTransaction::create(['user_id' => $owner->id, 'invoice_id' => $invoice->id, 'type' => $type,
            'amount' => $amount, 'balance_after' => $owner->credit, 'frozen_after' => $owner->frozen_credit,
            'reference_key' => $key, 'meta' => ['source' => 'offline_invoice_receipt', 'request_id' => $requestId, 'currency' => 'CNY']]);
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['operation' => $message]);
    }
}
