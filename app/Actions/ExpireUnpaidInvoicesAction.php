<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\InvoiceStatus;
use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class ExpireUnpaidInvoicesAction
{
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
                        $amount = $invoice->credit_applied;
                        if (! Money::isZero($amount)) {
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
}
