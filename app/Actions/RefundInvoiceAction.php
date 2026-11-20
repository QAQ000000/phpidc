<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\TransactionType;
use App\Models\AffiliateCommission;
use App\Models\FinancialTransaction;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\RedemptionCode;
use App\Models\User;
use App\Services\NotificationManager;
use App\Support\AdminAuditContext;
use App\Support\Money;
use App\Support\OfflineInvoiceReceiptRules;
use App\Support\ReceivableRevision;
use App\Support\SettlementCurrency;
use App\Support\WalletCapacity;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class RefundInvoiceAction
{
    /** Full refunds only. external_record acknowledges an already completed external refund. */
    public function execute(Invoice $invoice, string $mode, bool $keepService = false, ?string $reason = null, ?User $actor = null): FinancialTransaction
    {
        $reason ??= '';
        if (! in_array($mode, ['refund_wallet', 'external_record'], true) || $actor === null || ! $actor->exists
            || $actor->trashed() || trim($reason) === '' || mb_strlen($reason) > 255) {
            throw new DomainException('A valid refund mode, explicit actor and non-empty reason (up to 255 characters) are required.');
        }
        // Discover IDs, not balances. Revalidate after locking all users in ascending ID order (INV-W3/L1).
        $candidate = Invoice::findOrFail($invoice->id);
        $commissionHint = AffiliateCommission::where('invoice_id', $candidate->id)->first();
        $userIds = array_unique(array_filter([$candidate->user_id, $commissionHint?->referrer_id, $actor->id]));

        return DB::transaction(function () use ($candidate, $commissionHint, $userIds, $mode, $keepService, $reason, $actor): FinancialTransaction {
            $users = User::withTrashed()->whereKey($userIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $operator = $users->get($actor->id);
            $locked = Invoice::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if ($operator === null || $operator->trashed() || $operator->anonymized_at !== null
                || $locked->user_id !== $candidate->user_id || ! Gate::forUser($operator)->allows('refund-invoice', $locked)) {
                throw new DomainException('Refund is not authorized for this invoice.');
            }
            if ($locked->type === InvoiceType::WALLET_TOPUP) {
                throw new DomainException('Wallet top-ups cannot use product refunds.');
            }
            if (! SettlementCurrency::supports($locked->currency)) {
                Log::warning('Non-CNY refund requires manual reconciliation.', ['invoice_id' => $locked->id, 'currency' => $locked->currency]);
                throw new DomainException('Non-CNY invoices require manual currency reconciliation before refund.');
            }
            $buyer = $users->get($locked->user_id);
            $commission = AffiliateCommission::where('invoice_id', $locked->id)->lockForUpdate()->first();
            $referrer = $commission === null ? null : $users->get($commission->referrer_id);
            if ($buyer === null || $commission?->id !== $commissionHint?->id || $commission?->referrer_id !== $commissionHint?->referrer_id
                || ($commission !== null && ($commission->buyer_id !== $locked->user_id || $referrer === null))) {
                throw new DomainException('Refund participants changed; review the invoice and retry.');
            }
            $type = $mode === 'refund_wallet' ? TransactionType::CREDIT_IN : TransactionType::EXPENSE;
            if ($locked->status === InvoiceStatus::REFUNDED) {
                $existing = FinancialTransaction::where('reference_key', 'refund:'.$locked->id)->first();
                if ($existing === null || $existing->type !== $type || ($existing->meta['mode'] ?? null) !== $mode) {
                    throw new DomainException('Existing refund does not match the requested mode.');
                }

                return $existing;
            }
            if ($locked->status !== InvoiceStatus::PAID) {
                throw new DomainException('Only a Paid invoice can be refunded.');
            }
            $instance = $locked->instance_id === null ? null : Instance::whereKey($locked->instance_id)->lockForUpdate()->firstOrFail();
            if (($instance !== null && $instance->user_id !== $buyer->id) || (! $keepService && ($instance === null || ! $instance->status->isTerminal()))) {
                throw new DomainException('Service termination must be confirmed before refund, or explicitly waived by keepService.');
            }
            $code = $instance === null ? null : RedemptionCode::where('instance_id', $instance->id)->lockForUpdate()->first();
            $deliveredCard = $code !== null && ($code->status === 'sold' || $code->sold_at !== null);
            if ($deliveredCard && ! $instance->status->isTerminal()) {
                $before = $instance->status->value;
                $instance->update(['status' => InstanceStatus::CANCELLED]);
                $this->audit($operator, 'instance.refund_cancel', Instance::class, $instance->id, ['status' => $before], ['status' => $instance->status->value], $reason);
            }
            // A refunded order cannot deliver new resources. Stale tokens also fence workers already running.
            $tasks = FulfillmentTask::whereIn('status', [FulfillmentTaskStatus::PENDING, FulfillmentTaskStatus::RUNNING])
                ->where(function ($query) use ($locked, $instance): void {
                    $query->where('invoice_id', $locked->id);
                    if ($instance !== null && $instance->status->isTerminal()) {
                        $query->orWhere('instance_id', $instance->id);
                    }
                })->orderBy('id')->lockForUpdate()->get();
            foreach ($tasks as $task) {
                $task->update(['status' => FulfillmentTaskStatus::STALE, 'locked_by' => null, 'locked_at' => null, 'lease_expires_at' => null]);
            }
            $offlineSource = app(OfflineInvoiceReceiptRules::class)->refundSource($locked);
            if ($commission !== null) {
                $this->clawback($commission, $referrer, $locked);
            }
            $before = ['status' => $locked->status->value, 'credit' => $buyer->credit, 'frozen_credit' => $buyer->frozen_credit];
            if ($mode === 'refund_wallet') {
                $credit = Money::add($buyer->credit, $locked->total_amount);
                if (! WalletCapacity::canCredit($buyer, $locked->total_amount)) {
                    throw new DomainException('Refund exceeds the wallet capacity.');
                }
                $buyer->forceFill(['credit' => $credit])->save();
            }
            $transaction = FinancialTransaction::create(['user_id' => $buyer->id, 'invoice_id' => $locked->id, 'type' => $type,
                'amount' => $locked->total_amount, 'balance_after' => $buyer->credit, 'frozen_after' => $buyer->frozen_credit,
                'reference_key' => 'refund:'.$locked->id, 'remark' => $reason,
                'meta' => ['mode' => $mode, 'currency' => $locked->currency, 'keep_service' => $keepService, 'actor_id' => $operator->id,
                    'delivered_card_loss' => $deliveredCard, 'external_refund_confirmed' => $mode === 'external_record', ...$offlineSource]]);
            // INV-R3: external expense already records the loss; wallet refunds need a separate noncash loss record.
            if ($deliveredCard && $mode === 'refund_wallet') {
                FinancialTransaction::create(['user_id' => $buyer->id, 'invoice_id' => $locked->id, 'type' => TransactionType::EXPENSE,
                    'amount' => $locked->total_amount, 'reference_key' => 'refund_card_loss:'.$locked->id, 'remark' => $reason,
                    'meta' => ['currency' => $locked->currency, 'noncash' => true, 'refund_transaction_id' => $transaction->id,
                        'basis' => 'refunded_invoice_total', 'card_id' => $code->id]]);
            }
            $locked->update(['status' => InvoiceStatus::REFUNDED]);
            if ($mode === 'refund_wallet') {
                app(RecordPaymentAttentionCaseAction::class)->refundCredit($buyer, $transaction);
            }
            app(NotificationManager::class)->queue($buyer->id, 'invoice.refunded', ['invoice_no' => $locked->invoice_no, 'amount' => $locked->total_amount, 'currency' => $locked->currency],
                eventKey: 'invoice:'.$locked->id.':refunded');
            $this->audit($operator, 'invoice.refund', Invoice::class, $locked->id, $before,
                ['status' => $locked->status->value, 'credit' => $buyer->credit, 'frozen_credit' => $buyer->frozen_credit,
                    'mode' => $mode, 'keep_service' => $keepService, 'transaction_id' => $transaction->id], $reason);

            return $transaction;
        });
    }

    private function clawback(AffiliateCommission $commission, User $referrer, Invoice $invoice): void
    {
        if ($commission->status === 'clawed_back') {
            return;
        }
        if (! in_array($commission->status, ['pending', 'available'], true)) {
            throw new DomainException('Unrecognized commission state requires review before refund.');
        }
        $before = $referrer->affiliate_balance;
        $recovered = $commission->status === 'pending' ? Money::zero() : Money::min($before, $commission->amount);
        $receivable = $commission->status === 'pending' ? Money::zero() : Money::sub($commission->amount, $recovered);
        $after = Money::sub($before, $recovered);
        $previousStatus = $commission->status;
        if (! Money::isZero($recovered)) {
            $referrer->forceFill(['affiliate_balance' => $after])->save();
        }
        FinancialTransaction::create(['user_id' => $referrer->id, 'invoice_id' => $invoice->id, 'type' => TransactionType::AFFILIATE_CLAWBACK,
            'amount' => $commission->amount, 'reference_key' => 'clawback:'.$commission->id,
            'balance_after' => $referrer->credit, 'frozen_after' => $referrer->frozen_credit,
            'meta' => ['currency' => $invoice->currency, 'commission_id' => $commission->id, 'previous_status' => $previousStatus,
                'affiliate_balance_before' => $before, 'affiliate_balance_after' => $after, 'recovered_amount' => $recovered, 'receivable_amount' => $receivable]]);
        $commission->update(['status' => 'clawed_back', 'clawed_back_at' => now()]);
        app(ReceivableRevision::class)->bump($referrer);
        if (! Money::isZero($receivable)) {
            app(NotificationManager::class)->queueOperations('affiliate.receivable.created',
                ['invoice_no' => $invoice->invoice_no, 'amount' => $receivable, 'currency' => $invoice->currency, 'status' => 'outstanding'],
                'affiliate-commission:'.$commission->id.':receivable-created');
            DB::afterCommit(fn () => Log::warning('Affiliate clawback receivable requires collection.', [
                'invoice_id' => $invoice->id, 'commission_id' => $commission->id, 'referrer_id' => $referrer->id, 'receivable_amount' => $receivable,
            ]));
        }
    }

    private function audit(User $actor, string $action, string $subjectType, int $subjectId, array $before, array $after, string $reason): void
    {
        DB::table('audit_logs')->insert(['actor_id' => $actor->id, 'actor_type' => 'user', 'action' => $action,
            ...AdminAuditContext::fields(true),
            'subject_type' => $subjectType, 'subject_id' => $subjectId, 'reason' => $reason,
            'before' => json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($after, JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now()]);
    }
}
