<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\User;
use App\Services\NotificationManager;
use App\Support\Money;
use App\Support\SettlementCurrency;
use App\Support\WalletCapacity;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExpireUnpaidInvoicesAction
{
    /** The caller holds user, invoice and attempt locks, in that order. */
    public function cancelRenewalForTermination(User $user, Invoice $invoice, string $reason, ?User $actor): void
    {
        if (DB::transactionLevel() === 0 || $invoice->user_id !== $user->id || $invoice->type !== InvoiceType::RENEW
            || $invoice->status !== InvoiceStatus::UNPAID || $invoice->paid_at !== null) {
            throw new DomainException('Only locked, unpaid renewal invoices can be cancelled for termination.');
        }
        SettlementCurrency::assertSupported($invoice->currency);
        $amount = $invoice->credit_applied;
        $freeze = FinancialTransaction::where('reference_key', "freeze:{$invoice->id}")->first();
        if ((! Money::isZero($amount) && ($freeze === null || $freeze->user_id !== $user->id || $freeze->invoice_id !== $invoice->id
            || $freeze->type !== TransactionType::CREDIT_FREEZE || ! Money::eq($freeze->amount, $amount)))
            || (Money::isZero($amount) && $freeze !== null)
            || FinancialTransaction::whereIn('reference_key', ["settle:{$invoice->id}", "unfreeze:{$invoice->id}"])->exists()
            || ! Money::gte($user->frozen_credit, $amount)) {
            throw new DomainException('Renewal wallet evidence requires reconciliation before termination.');
        }
        if (! Money::isZero($amount)) {
            if (! WalletCapacity::canCredit($user, $amount, $amount)) {
                throw new DomainException('Wallet release exceeds the column limit; reconcile funds before termination.');
            }
            $user->forceFill(['credit' => Money::add($user->credit, $amount), 'frozen_credit' => Money::sub($user->frozen_credit, $amount)])->save();
            $release = FinancialTransaction::create(['user_id' => $user->id, 'invoice_id' => $invoice->id, 'type' => TransactionType::CREDIT_UNFREEZE,
                'amount' => $amount, 'balance_after' => $user->credit, 'frozen_after' => $user->frozen_credit, 'reference_key' => "unfreeze:{$invoice->id}"]);
        }
        $invoice->attempts()->where('status', 'created')->update(['status' => 'expired']);
        $invoice->update(['status' => InvoiceStatus::CANCELLED]);
        if (isset($release)) {
            $this->recordClosedRelease($user, $invoice, $release);
        }
        DB::table('audit_logs')->insert(['actor_type' => $actor === null ? 'system' : 'user', 'actor_id' => $actor?->id,
            'action' => 'invoice.cancel_for_termination', 'subject_type' => Invoice::class, 'subject_id' => $invoice->id,
            'reason' => $reason, 'before' => json_encode(['status' => InvoiceStatus::UNPAID->value]),
            'after' => json_encode(['status' => InvoiceStatus::CANCELLED->value, 'unfrozen' => $amount]), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function execute(): void
    {
        Invoice::where('status', InvoiceStatus::UNPAID)->where('expires_at', '<=', now())->select(['id', 'user_id'])
            ->chunkById(100, function ($invoices): void {
                foreach ($invoices as $candidate) {
                    $this->executeOne($candidate->id);
                }
            });
    }

    public function executeOne(int $id): void
    {
        $candidate = Invoice::find($id);
        if ($candidate === null) {
            return;
        }
        DB::transaction(function () use ($candidate): void {
            $user = User::withTrashed()->whereKey($candidate->user_id)->lockForUpdate()->firstOrFail();
            $invoice = Invoice::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if ($invoice->user_id !== $user->id || $invoice->status !== InvoiceStatus::UNPAID
                || $invoice->expires_at === null || $invoice->expires_at->isFuture()) {
                return;
            }
            $invoice->attempts()->orderBy('id')->lockForUpdate()->get();
            if (! SettlementCurrency::supports($invoice->currency)) {
                DB::afterCommit(fn () => Log::warning('Non-CNY invoice expiry requires manual reconciliation.', ['invoice_id' => $invoice->id, 'currency' => $invoice->currency]));

                return;
            }
            $amount = $invoice->credit_applied;
            $release = null;
            if (! Money::isZero($amount)) {
                if ($user->trashed() || $user->anonymized_at !== null) {
                    $freeze = FinancialTransaction::where('reference_key', "freeze:{$invoice->id}")->first();
                    if ($freeze === null || $freeze->user_id !== $user->id || $freeze->invoice_id !== $invoice->id
                        || $freeze->type !== TransactionType::CREDIT_FREEZE || ! Money::eq($freeze->amount, $amount)
                        || FinancialTransaction::whereIn('reference_key', ["settle:{$invoice->id}", "unfreeze:{$invoice->id}"])->exists()
                        || ! Money::gte($user->frozen_credit, $amount)) {
                        throw new DomainException('Closed-account wallet release requires its complete original freeze evidence.');
                    }
                }
                if (! WalletCapacity::canCredit($user, $amount, $amount)) {
                    $this->recordReleaseAttention($invoice);

                    return;
                }
                $user->forceFill(['credit' => Money::add($user->credit, $amount), 'frozen_credit' => Money::sub($user->frozen_credit, $amount)])->save();
                $release = FinancialTransaction::create(['user_id' => $user->id, 'invoice_id' => $invoice->id, 'type' => TransactionType::CREDIT_UNFREEZE,
                    'amount' => $amount, 'balance_after' => $user->credit, 'frozen_after' => $user->frozen_credit, 'reference_key' => "unfreeze:{$invoice->id}"]);
            }
            $invoice->attempts()->where('status', 'created')->update(['status' => 'expired']);
            $invoice->update(['status' => InvoiceStatus::EXPIRED]);
            if ($release !== null) {
                $this->recordClosedRelease($user, $invoice, $release);
            }
        });
    }

    private function recordClosedRelease(User $user, Invoice $invoice, FinancialTransaction $release): void
    {
        if (! $user->trashed() && $user->anonymized_at === null) {
            return;
        }
        $case = app(RecordPaymentAttentionCaseAction::class)->releasedCredit($user, $release);
        if ($case === null) {
            return;
        }
        DB::table('audit_logs')->insert(['actor_type' => 'system', 'action' => 'invoice.closed_wallet_release',
            'subject_type' => Invoice::class, 'subject_id' => $invoice->id, 'reason' => 'Original invoice hold released to the original closed financial identity.',
            'after' => json_encode(['release_transaction_id' => $release->id, 'case_id' => $case->id, 'amount' => $release->amount,
                'currency' => $invoice->currency, 'invoice_status' => $invoice->status->value], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now()]);
        app(NotificationManager::class)->queueOperations('reconciliation.updated', ['invoice_no' => $invoice->invoice_no,
            'amount' => $release->amount, 'currency' => $invoice->currency, 'status' => $case->status], 'reconciliation-case:'.$case->id.':'.$case->revision);
    }

    private function recordReleaseAttention(Invoice $invoice): void
    {
        // The user/invoice locks serialize this fact; repeated minute sweeps do not multiply it.
        if (! DB::table('audit_logs')->where('action', 'invoice.expiry.wallet_capacity')
            ->where('subject_type', Invoice::class)->where('subject_id', $invoice->id)->exists()) {
            DB::table('audit_logs')->insert(['actor_type' => 'system', 'action' => 'invoice.expiry.wallet_capacity',
                'subject_type' => Invoice::class, 'subject_id' => $invoice->id,
                'reason' => 'Invoice wallet release requires reconciliation before expiry.',
                'after' => json_encode(['amount' => $invoice->credit_applied, 'reason' => 'wallet_capacity'], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now()]);
            DB::afterCommit(fn () => Log::warning('Invoice wallet release requires reconciliation.', ['invoice_id' => $invoice->id]));
        }
    }
}
