<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\PaymentCallback;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BackfillPaymentAttentionCasesAction
{
    /** Rebuild source projections only; never credit, deliver, or pay anything. */
    public function execute(): array
    {
        $callbacks = $refunds = 0;
        foreach (DB::table('audit_logs')->where('action', 'payment.callback.attention')->where('subject_type', PaymentCallback::class)
            ->whereIn('after->reason', ['wallet_capacity', 'closed_account'])->orderBy('id')->lazyById(100) as $audit) {
            $callback = PaymentCallback::find($audit->subject_id);
            $invoice = $callback === null ? null : Invoice::find($callback->invoice_id);
            if ($invoice === null) {
                continue;
            }
            $reason = json_decode($audit->after, true, flags: JSON_THROW_ON_ERROR)['reason'];
            DB::transaction(function () use ($callback, $invoice, $reason): void {
                User::withTrashed()->whereKey($invoice->user_id)->lockForUpdate()->firstOrFail();
                Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                app(RecordPaymentAttentionCaseAction::class)->execute($callback->fresh(), $reason);
            });
            $callbacks++;
        }
        foreach (FinancialTransaction::where('type', TransactionType::CREDIT_IN)->where('reference_key', 'like', 'refund:%')->lazyById(100) as $credit) {
            DB::transaction(function () use ($credit, &$refunds): void {
                $owner = User::withTrashed()->whereKey($credit->user_id)->lockForUpdate()->firstOrFail();
                if (! $owner->trashed() && $owner->anonymized_at === null) {
                    return;
                }
                Invoice::whereKey($credit->invoice_id)->lockForUpdate()->firstOrFail();
                app(RecordPaymentAttentionCaseAction::class)->refundCredit($owner, $credit);
                $refunds++;
            });
        }

        return ['callbacks' => $callbacks, 'refunds' => $refunds];
    }
}
