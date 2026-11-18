<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\PaymentPostingResult;
use App\Data\VerifiedPaymentResult;
use App\Enums\InvoiceType;
use App\Enums\PaymentCallbackOutcome as Outcome;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\PaymentCallback;
use App\Models\User;
use App\Services\NotificationManager;
use App\Support\RenewalRecovery;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

class HandlePaymentCallbackAction
{
    public function execute(VerifiedPaymentResult $payment): string
    {
        // The adapter supplies a canonical amount, never a float or partial value.
        if (! preg_match('/^\d+\.\d{2}$/D', $payment->paidAmount)) {
            throw new LogicException('Callback amount must be a fixed-point string.');
        }

        return DB::transaction(function () use ($payment): string {
            try {
                // Savepoint contains only the dedupe insert, also safe on PostgreSQL.
                $callback = DB::transaction(fn () => PaymentCallback::create([
                    'gateway' => $payment->gateway, 'gateway_trade_no' => $payment->gatewayTradeNo,
                    'invoice_no' => $payment->outTradeNo, 'amount' => $payment->paidAmount,
                    'outcome' => Outcome::UNKNOWN_INVOICE, 'raw_payload' => $payment->rawPayload, 'processed_at' => now(),
                ]));
            } catch (UniqueConstraintViolationException $error) {
                $duplicate = PaymentCallback::where('gateway', $payment->gateway)->where('gateway_trade_no', $payment->gatewayTradeNo)->lockForUpdate()->first();
                if ($duplicate === null) {
                    throw $error;
                }

                return $payment->ackBody;
            }
            $attempt = PaymentAttempt::where('gateway', $payment->gateway)->where('out_trade_no', $payment->outTradeNo)->first();
            $candidate = $attempt === null ? Invoice::where('invoice_no', $payment->outTradeNo)->first() : Invoice::find($attempt->invoice_id);
            if ($candidate === null) {
                $this->finish($callback, Outcome::UNKNOWN_INVOICE);

                return $payment->ackBody;
            }
            // Non-locking lookup above only resolves the owner; all decisions use locked rows.
            // D02: financial identity survives anonymization; never resolve by reusable email/phone.
            $user = User::withTrashed()->whereKey($candidate->user_id)->lockForUpdate()->firstOrFail();
            $family = $candidate->type === InvoiceType::RENEW ? app(RenewalRecovery::class)->rows($candidate, true) : null;
            $invoice = $family === null ? Invoice::whereKey($candidate->id)->lockForUpdate()->firstOrFail() : $family->firstWhere('id', $candidate->id);
            if ($attempt !== null) {
                $attempt = PaymentAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            }
            $callback->update(['invoice_id' => $invoice->id, 'invoice_no' => $invoice->invoice_no]);
            app(PostVerifiedPaymentReceiptAction::class)->execute($user, $invoice, $attempt, $callback,
                fn (PaymentPostingResult $result) => $this->finish($callback, $result->outcome, $result->reason), $family);

            return $payment->ackBody;
        });
    }

    private function finish(PaymentCallback $callback, Outcome $outcome, ?string $reason = null): void
    {
        $callback->update(['outcome' => $outcome]);
        if ($outcome->requiresAlert() || $reason !== null) {
            $reason ??= $outcome->value;
            $invoice = $callback->invoice_id === null ? null : Invoice::find($callback->invoice_id);
            if ($invoice !== null && in_array($reason, ['wallet_capacity', 'closed_account'], true)) {
                app(RecordPaymentAttentionCaseAction::class)->execute($callback, $reason);
            }
            // Durable evidence remains queryable even when no operator channel is configured.
            DB::table('audit_logs')->insert(['actor_type' => 'system', 'action' => 'payment.callback.attention',
                'subject_type' => PaymentCallback::class, 'subject_id' => $callback->id, 'reason' => $reason,
                'after' => json_encode(['outcome' => $outcome->value, 'invoice_id' => $callback->invoice_id,
                    'amount' => $callback->amount, 'reason' => $reason], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            app(NotificationManager::class)->queueOperations('payment.callback.attention',
                ['invoice_no' => $callback->invoice_no, 'amount' => $callback->amount, 'currency' => $invoice === null ? 'CNY' : $invoice->currency, 'status' => $reason],
                eventKey: 'callback:'.$callback->id.':attention');
            DB::afterCommit(fn () => Log::warning('Payment callback requires attention.', ['callback_id' => $callback->id, 'outcome' => $outcome->value]));
        }
    }
}
