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
use App\Services\NotificationManager;
use App\Support\Money;
use App\Support\RenewalRecovery;
use App\Support\SettlementCurrency;
use App\Support\WalletCapacity;
use App\Support\WalletTopupIntent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
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
            $closed = $user->trashed() || $user->anonymized_at !== null;
            $family = $candidate->type === InvoiceType::RENEW ? app(RenewalRecovery::class)->rows($candidate, true) : null;
            $invoice = $family === null ? Invoice::whereKey($candidate->id)->lockForUpdate()->firstOrFail() : $family->firstWhere('id', $candidate->id);
            if ($attempt !== null) {
                $attempt = PaymentAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            }
            $callback->update(['invoice_id' => $invoice->id, 'invoice_no' => $invoice->invoice_no]);
            if ($payment->gateway !== $invoice->gateway) {
                $this->finish($callback, Outcome::REJECTED_GATEWAY_MISMATCH);
            } elseif (! SettlementCurrency::supports($invoice->currency)) {
                $this->finish($callback, Outcome::REJECTED_UNSUPPORTED_CURRENCY);
            } elseif (in_array($invoice->status, [InvoiceStatus::EXPIRED, InvoiceStatus::CANCELLED], true)) {
                if ($this->canCredit($user, $payment->paidAmount)) {
                    $this->credit($user, $invoice, $callback, $payment->paidAmount);
                    $this->finish($callback, Outcome::PROCESSED_LATE_CREDIT, $closed ? 'closed_account' : null);
                } else {
                    $this->finish($callback, Outcome::REJECTED_INVALID_STATE, 'wallet_capacity');
                }
            } elseif ($invoice->status === InvoiceStatus::PAID) {
                if ($this->canCredit($user, $payment->paidAmount)) {
                    $this->credit($user, $invoice, $callback, $payment->paidAmount);
                    $this->finish($callback, Outcome::PROCESSED_OVERPAYMENT, $closed ? 'closed_account' : null);
                } else {
                    $this->finish($callback, Outcome::REJECTED_INVALID_STATE, 'wallet_capacity');
                }
            } elseif ($closed) {
                // An Unpaid invoice cannot normally survive approved account closure. Preserve evidence, never deliver.
                $this->finish($callback, Outcome::REJECTED_INVALID_STATE, 'closed_account');
            } elseif ($invoice->type === InvoiceType::WALLET_TOPUP && $invoice->status === InvoiceStatus::UNPAID) {
                $this->settleTopup($user, $invoice, $attempt, $callback, $payment);
            } elseif ($invoice->status !== InvoiceStatus::UNPAID || ! in_array($invoice->type, [InvoiceType::NEW_ORDER, InvoiceType::RENEW, InvoiceType::UPGRADE], true)
                || ($invoice->type !== InvoiceType::NEW_ORDER && ! isset($invoice->line_items['encrypted_operation']))) {
                $this->finish($callback, Outcome::REJECTED_INVALID_STATE);
            } elseif (Money::gt($invoice->gateway_amount, $payment->paidAmount)) {
                $this->finish($callback, Outcome::REJECTED_SHORT_AMOUNT);
            } else {
                if ($family !== null) {
                    try {
                        app(RenewalRecovery::class)->assertPayable($invoice, $family);
                    } catch (ValidationException) {
                        $this->finish($callback, Outcome::REJECTED_INVALID_STATE);

                        return $payment->ackBody;
                    }
                }
                if (! Money::eq(Money::add($invoice->credit_applied, $invoice->gateway_amount), $invoice->total_amount)) {
                    throw new LogicException('Stored invoice amount identity failed.');
                }
                $excess = Money::sub($payment->paidAmount, $invoice->gateway_amount);
                // N05: preflight the complete receipt before settlement or fulfillment.
                if (! WalletCapacity::canCredit($user, $excess, $invoice->credit_applied)) {
                    $this->finish($callback, Outcome::REJECTED_INVALID_STATE, 'wallet_capacity');

                    return $payment->ackBody;
                }
                $user->forceFill(['frozen_credit' => Money::sub($user->frozen_credit, $invoice->credit_applied)])->save();
                $this->ledger($user, $invoice, TransactionType::CREDIT_SETTLE, $invoice->credit_applied, "settle:{$invoice->id}");
                $this->ledger($user, $invoice, TransactionType::PAYMENT, $invoice->gateway_amount, "payment:{$invoice->id}");
                $invoice->update(['status' => InvoiceStatus::PAID, 'paid_at' => now(), 'gateway_trade_no' => $payment->gatewayTradeNo]);
                if ($attempt !== null) {
                    $attempt->update(['status' => 'paid', 'gateway_trade_no' => $payment->gatewayTradeNo, 'paid_at' => now()]);
                }
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

    private function canCredit(User $user, string $amount): bool
    {
        return WalletCapacity::canCredit($user, $amount);
    }

    private function settleTopup(User $user, Invoice $invoice, ?PaymentAttempt $attempt, PaymentCallback $callback, VerifiedPaymentResult $payment): void
    {
        if (! app(WalletTopupIntent::class)->isValid($invoice)) {
            $this->finish($callback, Outcome::REJECTED_INVALID_STATE, 'invalid_topup_snapshot');

            return;
        }
        if (Money::gt($invoice->gateway_amount, $payment->paidAmount)) {
            $this->finish($callback, Outcome::REJECTED_SHORT_AMOUNT);

            return;
        }
        // Validate the entire receipt before the principal or excess is applied.
        if (! $this->canCredit($user, $payment->paidAmount)) {
            $this->finish($callback, Outcome::REJECTED_INVALID_STATE, 'wallet_capacity');

            return;
        }
        $this->credit($user, $invoice, $callback, $invoice->gateway_amount, "topup:{$invoice->id}");
        $this->ledger($user, $invoice, TransactionType::PAYMENT, $invoice->gateway_amount, "payment:{$invoice->id}");
        $excess = Money::sub($payment->paidAmount, $invoice->gateway_amount);
        if (! Money::isZero($excess)) {
            $this->credit($user, $invoice, $callback, $excess, "overpaid:{$callback->id}");
        }
        $invoice->update(['status' => InvoiceStatus::PAID, 'paid_at' => now(), 'gateway_trade_no' => $payment->gatewayTradeNo]);
        if ($attempt !== null) {
            $attempt->update(['status' => 'paid', 'gateway_trade_no' => $payment->gatewayTradeNo, 'paid_at' => now()]);
        }
        app(NotificationManager::class)->queue($user->id, 'wallet.topup.credited',
            ['invoice_no' => $invoice->invoice_no, 'amount' => $invoice->gateway_amount, 'currency' => $invoice->currency],
            eventKey: 'invoice:'.$invoice->id.':wallet-topup-credited');
        $this->finish($callback, Money::isZero($excess) ? Outcome::PROCESSED_PAID : Outcome::PROCESSED_OVERPAYMENT);
    }

    private function ledger(User $user, Invoice $invoice, TransactionType $type, string $amount, string $key): void
    {
        FinancialTransaction::create(['user_id' => $user->id, 'invoice_id' => $invoice->id, 'type' => $type, 'amount' => $amount,
            'balance_after' => $user->credit, 'frozen_after' => $user->frozen_credit, 'reference_key' => $key]);
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
