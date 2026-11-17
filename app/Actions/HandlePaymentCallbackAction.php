<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\VerifiedPaymentResult;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentCallbackOutcome as Outcome;
use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\PaymentCallback;
use App\Models\User;
use App\Support\Money;
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
            $user = User::whereKey($candidate->user_id)->lockForUpdate()->firstOrFail();
            $invoice = Invoice::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if ($attempt !== null) {
                $attempt = PaymentAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            }
            $callback->update(['invoice_id' => $invoice->id, 'invoice_no' => $invoice->invoice_no]);
            if ($payment->gateway !== $invoice->gateway) {
                $this->finish($callback, Outcome::REJECTED_GATEWAY_MISMATCH);
            } elseif (in_array($invoice->status, [InvoiceStatus::EXPIRED, InvoiceStatus::CANCELLED], true)) {
                $this->credit($user, $invoice, $callback, $payment->paidAmount);
                $this->finish($callback, Outcome::PROCESSED_LATE_CREDIT);
            } elseif ($invoice->status === InvoiceStatus::PAID) {
                $this->credit($user, $invoice, $callback, $payment->paidAmount);
                $this->finish($callback, Outcome::PROCESSED_OVERPAYMENT);
            } elseif ($invoice->status !== InvoiceStatus::UNPAID || ! in_array($invoice->type, [InvoiceType::NEW_ORDER, InvoiceType::RENEW, InvoiceType::UPGRADE], true)
                || ($invoice->type !== InvoiceType::NEW_ORDER && ! isset($invoice->line_items['encrypted_operation']))) {
                $this->finish($callback, Outcome::REJECTED_INVALID_STATE);
            } elseif (Money::gt($invoice->gateway_amount, $payment->paidAmount)) {
                $this->finish($callback, Outcome::REJECTED_SHORT_AMOUNT);
            } else {
                if (! Money::eq(Money::add($invoice->credit_applied, $invoice->gateway_amount), $invoice->total_amount)) {
                    throw new LogicException('Stored invoice amount identity failed.');
                }
                $user->forceFill(['frozen_credit' => Money::sub($user->frozen_credit, $invoice->credit_applied)])->save();
                $this->ledger($user, $invoice, TransactionType::CREDIT_SETTLE, $invoice->credit_applied, "settle:{$invoice->id}");
                $this->ledger($user, $invoice, TransactionType::PAYMENT, $invoice->gateway_amount, "payment:{$invoice->id}");
                $invoice->update(['status' => InvoiceStatus::PAID, 'paid_at' => now(), 'gateway_trade_no' => $payment->gatewayTradeNo]);
                if ($attempt !== null) {
                    $attempt->update(['status' => 'paid', 'gateway_trade_no' => $payment->gatewayTradeNo, 'paid_at' => now()]);
                }
                $excess = Money::sub($payment->paidAmount, $invoice->gateway_amount);
                if (! Money::isZero($excess)) {
                    $this->credit($user, $invoice, $callback, $excess, "overpaid:{$callback->id}");
                }
                if ($invoice->type === InvoiceType::NEW_ORDER) {
                    app(CreatePurchaseFulfillmentAction::class)->execute($invoice);
                } else {
                    app(CreateServiceFulfillmentAction::class)->execute($invoice);
                }
                $this->finish($callback, Money::isZero($excess) ? Outcome::PROCESSED_PAID : Outcome::PROCESSED_OVERPAYMENT);
            }

            return $payment->ackBody;
        });
    }

    private function credit(User $user, Invoice $invoice, PaymentCallback $callback, string $amount, ?string $key = null): void
    {
        $user->forceFill(['credit' => Money::add($user->credit, $amount)])->save();
        $this->ledger($user, $invoice, TransactionType::CREDIT_IN, $amount, $key ?? "credit_in:callback:{$callback->id}");
    }

    private function ledger(User $user, Invoice $invoice, TransactionType $type, string $amount, string $key): void
    {
        FinancialTransaction::create(['user_id' => $user->id, 'invoice_id' => $invoice->id, 'type' => $type, 'amount' => $amount,
            'balance_after' => $user->credit, 'frozen_after' => $user->frozen_credit, 'reference_key' => $key]);
    }

    private function finish(PaymentCallback $callback, Outcome $outcome): void
    {
        $callback->update(['outcome' => $outcome]);
        if ($outcome->requiresAlert()) {
            DB::afterCommit(fn () => Log::warning('Payment callback requires attention.', ['callback_id' => $callback->id, 'outcome' => $outcome->value]));
        }
    }
}
