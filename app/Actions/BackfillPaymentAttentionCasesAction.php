<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\PaymentCallback;
use App\Models\ReconciliationCase;
use App\Models\User;
use App\Services\PaymentAttentionCases;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use JsonException;

class BackfillPaymentAttentionCasesAction
{
    /** Rebuild source projections only; never credit, deliver, or pay anything. */
    public function execute(): array
    {
        $callbackResults = $this->callbacks();
        $refunds = $releases = 0;
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

        foreach (FinancialTransaction::where('type', TransactionType::CREDIT_UNFREEZE)->where('reference_key', 'like', 'unfreeze:%')->lazyById(100) as $release) {
            DB::transaction(function () use ($release, &$releases): void {
                $owner = User::withTrashed()->whereKey($release->user_id)->lockForUpdate()->firstOrFail();
                if (! $owner->trashed() && $owner->anonymized_at === null) {
                    return;
                }
                Invoice::whereKey($release->invoice_id)->lockForUpdate()->firstOrFail();
                app(RecordPaymentAttentionCaseAction::class)->releasedCredit($owner, $release);
                $releases++;
            });
        }

        return [...$callbackResults, 'refunds' => $refunds, 'releases' => $releases];
    }

    /** Used by the additive migration before business reads stop consulting audit history. */
    public function callbacks(): array
    {
        $callbacks = $snapshots = $missing = 0;
        foreach ($this->attentionAudits() as $audit) {
            [$reason, $facts] = $this->attentionFacts($audit);
            if ($reason === null) {
                continue;
            }
            $hint = PaymentCallback::find($audit->subject_id);
            $invoice = $hint === null ? null : Invoice::find($hint->invoice_id);
            if ($invoice === null) {
                throw new DomainException('Payment attention audit '.$audit->id.' has no original financial owner.');
            }
            $hasSnapshot = DB::transaction(function () use ($hint, $invoice, $reason, $facts): bool {
                User::withTrashed()->whereKey($invoice->user_id)->lockForUpdate()->firstOrFail();
                $lockedInvoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                $callback = PaymentCallback::findOrFail($hint->id);
                if ($callback->invoice_id !== $lockedInvoice->id || $lockedInvoice->user_id !== $invoice->user_id) {
                    throw new DomainException('The historical attention financial identity changed.');
                }
                $snapshot = $this->verifiedSnapshot($callback, $lockedInvoice, $reason, $facts);
                $case = app(RecordPaymentAttentionCaseAction::class)->importHistorical($callback, $reason, $snapshot);

                return $case->source_snapshot !== null;
            });
            $callbacks++;
            $hasSnapshot ? $snapshots++ : $missing++;
        }

        // A successful upgrade must never turn an old audit-only blocker into permission to close.
        $omissions = 0;
        foreach ($this->attentionAudits() as $audit) {
            [$reason] = $this->attentionFacts($audit);
            if ($reason === null) {
                continue;
            }
            $callback = PaymentCallback::find($audit->subject_id);
            $invoice = $callback === null ? null : Invoice::find($callback->invoice_id);
            if ($invoice === null || ! ReconciliationCase::where('kind', 'payment_attention')->where('user_id', $invoice->user_id)
                ->where('source_type', 'payment_callback')->where('source_id', (string) $callback->id)
                ->where('payment_callback_id', $callback->id)->where('initial_reason', $reason)->exists()) {
                $omissions++;
            }
        }
        if ($omissions !== 0) {
            throw new DomainException('Payment attention upgrade left '.$omissions.' unregistered historical sources.');
        }

        return ['callbacks' => $callbacks, 'snapshots' => $snapshots, 'missing_evidence' => $missing, 'omissions' => $omissions];
    }

    private function attentionAudits(): LazyCollection
    {
        return DB::table('audit_logs')->where('action', 'payment.callback.attention')->where('subject_type', PaymentCallback::class)
            ->orderBy('id')->lazyById(100);
    }

    private function attentionFacts(object $audit): array
    {
        try {
            $facts = json_decode($audit->after ?? '', true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $facts = null;
        }
        if (! is_array($facts) && ! in_array($audit->reason, ['wallet_capacity', 'closed_account'], true)) {
            throw new DomainException('Payment attention audit '.$audit->id.' has no interpretable original reason.');
        }
        $facts = is_array($facts) ? $facts : [];
        $reasons = ['wallet_capacity', 'closed_account'];
        $inner = in_array($facts['reason'] ?? null, $reasons, true) ? $facts['reason'] : null;
        $outer = in_array($audit->reason, $reasons, true) ? $audit->reason : null;
        if ($inner !== null && $outer !== null && $inner !== $outer) {
            throw new DomainException('Payment attention audit '.$audit->id.' has conflicting reasons.');
        }

        return [$inner ?? $outer, $facts];
    }

    private function verifiedSnapshot(PaymentCallback $callback, Invoice $invoice, string $reason, array $facts): ?array
    {
        if (! is_string($facts['reason'] ?? null) || ! is_string($facts['outcome'] ?? null)
            || ! is_int($facts['invoice_id'] ?? null) || ! is_string($facts['amount'] ?? null)
            || ! preg_match('/^\d+\.\d{2}$/D', $facts['amount'])) {
            return null;
        }
        if ($facts['reason'] !== $reason || $facts['outcome'] !== $callback->outcome->value
            || $facts['invoice_id'] !== $invoice->id || ! Money::eq($facts['amount'], $callback->amount)
            || $callback->gateway !== $invoice->gateway || $invoice->currency !== 'CNY') {
            throw new DomainException('The historical payment attention facts conflict with the original receipt.');
        }

        return app(PaymentAttentionCases::class)->callbackSourceSnapshot($callback, $invoice, $reason);
    }
}
