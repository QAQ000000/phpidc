<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\User;
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
            FinancialTransaction::create(['user_id' => $user->id, 'invoice_id' => $invoice->id, 'type' => TransactionType::CREDIT_UNFREEZE,
                'amount' => $amount, 'balance_after' => $user->credit, 'frozen_after' => $user->frozen_credit, 'reference_key' => "unfreeze:{$invoice->id}"]);
        }
        $invoice->attempts()->where('status', 'created')->update(['status' => 'expired']);
        $invoice->update(['status' => InvoiceStatus::CANCELLED]);
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
                    DB::transaction(function () use ($candidate): void {
                        $user = User::whereKey($candidate->user_id)->lockForUpdate()->firstOrFail();
                        $invoice = Invoice::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                        if ($invoice->status !== InvoiceStatus::UNPAID || $invoice->expires_at === null || $invoice->expires_at->isFuture()) {
                            return;
                        }
                        if (! SettlementCurrency::supports($invoice->currency)) {
                            DB::afterCommit(fn () => Log::warning('Non-CNY invoice expiry requires manual reconciliation.', ['invoice_id' => $invoice->id, 'currency' => $invoice->currency]));

                            return;
                        }
                        $amount = $invoice->credit_applied;
                        if (! Money::isZero($amount)) {
                            if (! WalletCapacity::canCredit($user, $amount, $amount)) {
                                $this->recordReleaseAttention($invoice);

                                return;
                            }
                            $user->forceFill(['credit' => Money::add($user->credit, $amount), 'frozen_credit' => Money::sub($user->frozen_credit, $amount)])->save();
                            FinancialTransaction::create(['user_id' => $user->id, 'invoice_id' => $invoice->id, 'type' => TransactionType::CREDIT_UNFREEZE,
                                'amount' => $amount, 'balance_after' => $user->credit, 'frozen_after' => $user->frozen_credit, 'reference_key' => "unfreeze:{$invoice->id}"]);
                        }
                        $invoice->attempts()->where('status', 'created')->update(['status' => 'expired']);
                        $invoice->update(['status' => InvoiceStatus::EXPIRED]);
                    });
                }
            });
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
