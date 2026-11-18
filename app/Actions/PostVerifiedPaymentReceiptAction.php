<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\PaymentPostingResult;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentCallbackOutcome as Outcome;
use App\Enums\PaymentPostingKind;
use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\FulfillmentTask;
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
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Shared financial rules, called only with the original owner/invoices/attempts already locked. */
class PostVerifiedPaymentReceiptAction
{
    /** @param Closure(PaymentPostingResult): void $beforeNotification */
    public function execute(User $user, Invoice $invoice, ?PaymentAttempt $attempt, PaymentCallback $callback,
        Closure $beforeNotification, ?Collection $renewalFamily = null, bool $allowClosedUnpaidCredit = false): PaymentPostingResult
    {
        if (DB::transactionLevel() === 0 || $invoice->user_id !== $user->id || $callback->invoice_id !== $invoice->id
            || ! preg_match('/^\d+\.\d{2}$/D', $callback->amount)
            || ($attempt !== null && ($attempt->invoice_id !== $invoice->id || $attempt->gateway !== $callback->gateway))) {
            throw new LogicException('Verified posting requires its locked financial owner and original receipt.');
        }
        $closed = $user->trashed() || $user->anonymized_at !== null;
        $status = $invoice->status;
        if ($callback->gateway !== $invoice->gateway) {
            return $this->complete(new PaymentPostingResult(Outcome::REJECTED_GATEWAY_MISMATCH), $beforeNotification);
        }
        if (! SettlementCurrency::supports($invoice->currency)) {
            return $this->complete(new PaymentPostingResult(Outcome::REJECTED_UNSUPPORTED_CURRENCY), $beforeNotification);
        }
        if (in_array($status, [InvoiceStatus::EXPIRED, InvoiceStatus::CANCELLED, InvoiceStatus::PAID], true)
            || ($closed && $allowClosedUnpaidCredit && $status === InvoiceStatus::UNPAID)) {
            if (! WalletCapacity::canCredit($user, $callback->amount)) {
                return $this->complete(new PaymentPostingResult(Outcome::REJECTED_INVALID_STATE, 'wallet_capacity'), $beforeNotification);
            }
            $credit = $this->credit($user, $invoice, $callback->amount, 'credit_in:callback:'.$callback->id);

            return $this->complete(new PaymentPostingResult(
                $status === InvoiceStatus::PAID ? Outcome::PROCESSED_OVERPAYMENT : Outcome::PROCESSED_LATE_CREDIT,
                $closed ? 'closed_account' : null, PaymentPostingKind::WALLET_RECEIPT, $closed, $status,
                principalCreditTransactionId: $credit->id,
            ), $beforeNotification);
        }
        if ($closed) {
            return $this->complete(new PaymentPostingResult(Outcome::REJECTED_INVALID_STATE, 'closed_account'), $beforeNotification);
        }
        if ($invoice->type === InvoiceType::WALLET_TOPUP && $status === InvoiceStatus::UNPAID) {
            return $this->topup($user, $invoice, $attempt, $callback, $beforeNotification);
        }
        if ($status !== InvoiceStatus::UNPAID || ! in_array($invoice->type, [InvoiceType::NEW_ORDER, InvoiceType::RENEW, InvoiceType::UPGRADE], true)
            || ($invoice->type !== InvoiceType::NEW_ORDER && ! isset($invoice->line_items['encrypted_operation']))) {
            return $this->complete(new PaymentPostingResult(Outcome::REJECTED_INVALID_STATE), $beforeNotification);
        }
        if (Money::gt($invoice->gateway_amount, $callback->amount)) {
            return $this->complete(new PaymentPostingResult(Outcome::REJECTED_SHORT_AMOUNT), $beforeNotification);
        }
        if ($renewalFamily !== null) {
            try {
                app(RenewalRecovery::class)->assertPayable($invoice, $renewalFamily);
            } catch (ValidationException) {
                return $this->complete(new PaymentPostingResult(Outcome::REJECTED_INVALID_STATE), $beforeNotification);
            }
        }
        if (! Money::eq(Money::add($invoice->credit_applied, $invoice->gateway_amount), $invoice->total_amount)) {
            throw new LogicException('Stored invoice amount identity failed.');
        }
        $excessAmount = Money::sub($callback->amount, $invoice->gateway_amount);
        if (! WalletCapacity::canCredit($user, $excessAmount, $invoice->credit_applied)) {
            return $this->complete(new PaymentPostingResult(Outcome::REJECTED_INVALID_STATE, 'wallet_capacity'), $beforeNotification);
        }
        $user->forceFill(['frozen_credit' => Money::sub($user->frozen_credit, $invoice->credit_applied)])->save();
        $settle = $this->ledger($user, $invoice, TransactionType::CREDIT_SETTLE, $invoice->credit_applied, 'settle:'.$invoice->id);
        $payment = $this->ledger($user, $invoice, TransactionType::PAYMENT, $invoice->gateway_amount, 'payment:'.$invoice->id);
        $this->paid($invoice, $attempt, $callback);
        $excess = Money::isZero($excessAmount) ? null : $this->credit($user, $invoice, $excessAmount, 'overpaid:'.$callback->id);
        $result = null;
        $completed = function (FulfillmentTask $task) use ($beforeNotification, $status, $payment, $settle, $excess, $attempt, &$result): void {
            $result = new PaymentPostingResult($excess === null ? Outcome::PROCESSED_PAID : Outcome::PROCESSED_OVERPAYMENT,
                kind: PaymentPostingKind::INVOICE_SETTLEMENT, invoiceStatusBefore: $status,
                paymentTransactionId: $payment->id, settlementTransactionId: $settle->id,
                excessCreditTransactionId: $excess?->id, fulfillmentTaskId: $task->id, paymentAttemptId: $attempt?->id);
            $beforeNotification($result);
        };
        if ($invoice->type === InvoiceType::NEW_ORDER) {
            app(CreatePurchaseFulfillmentAction::class)->execute($invoice, $completed);
        } else {
            app(CreateServiceFulfillmentAction::class)->execute($invoice, $completed);
        }

        return $result ?? throw new LogicException('Paid fulfillment must finish before its notification.');
    }

    /** @param Closure(PaymentPostingResult): void $beforeNotification */
    private function topup(User $user, Invoice $invoice, ?PaymentAttempt $attempt, PaymentCallback $callback, Closure $beforeNotification): PaymentPostingResult
    {
        if (! app(WalletTopupIntent::class)->isValid($invoice)) {
            return $this->complete(new PaymentPostingResult(Outcome::REJECTED_INVALID_STATE, 'invalid_topup_snapshot'), $beforeNotification);
        }
        if (Money::gt($invoice->gateway_amount, $callback->amount)) {
            return $this->complete(new PaymentPostingResult(Outcome::REJECTED_SHORT_AMOUNT), $beforeNotification);
        }
        if (! WalletCapacity::canCredit($user, $callback->amount)) {
            return $this->complete(new PaymentPostingResult(Outcome::REJECTED_INVALID_STATE, 'wallet_capacity'), $beforeNotification);
        }
        $principal = $this->credit($user, $invoice, $invoice->gateway_amount, 'topup:'.$invoice->id);
        $payment = $this->ledger($user, $invoice, TransactionType::PAYMENT, $invoice->gateway_amount, 'payment:'.$invoice->id);
        $excessAmount = Money::sub($callback->amount, $invoice->gateway_amount);
        $excess = Money::isZero($excessAmount) ? null : $this->credit($user, $invoice, $excessAmount, 'overpaid:'.$callback->id);
        $status = $invoice->status;
        $this->paid($invoice, $attempt, $callback);
        $result = $this->complete(new PaymentPostingResult($excess === null ? Outcome::PROCESSED_PAID : Outcome::PROCESSED_OVERPAYMENT,
            kind: PaymentPostingKind::WALLET_TOPUP, invoiceStatusBefore: $status, paymentTransactionId: $payment->id,
            principalCreditTransactionId: $principal->id, excessCreditTransactionId: $excess?->id, paymentAttemptId: $attempt?->id), $beforeNotification);
        app(NotificationManager::class)->queue($user->id, 'wallet.topup.credited',
            ['invoice_no' => $invoice->invoice_no, 'amount' => $invoice->gateway_amount, 'currency' => $invoice->currency],
            eventKey: 'invoice:'.$invoice->id.':wallet-topup-credited');

        return $result;
    }

    private function paid(Invoice $invoice, ?PaymentAttempt $attempt, PaymentCallback $callback): void
    {
        $invoice->update(['status' => InvoiceStatus::PAID, 'paid_at' => now(), 'gateway_trade_no' => $callback->gateway_trade_no]);
        if ($attempt !== null) {
            $attempt->update(['status' => 'paid', 'gateway_trade_no' => $callback->gateway_trade_no, 'paid_at' => now()]);
        }
    }

    private function credit(User $user, Invoice $invoice, string $amount, string $key): FinancialTransaction
    {
        $user->forceFill(['credit' => Money::add($user->credit, $amount)])->save();

        return $this->ledger($user, $invoice, TransactionType::CREDIT_IN, $amount, $key);
    }

    private function ledger(User $user, Invoice $invoice, TransactionType $type, string $amount, string $key): FinancialTransaction
    {
        return FinancialTransaction::create(['user_id' => $user->id, 'invoice_id' => $invoice->id, 'type' => $type, 'amount' => $amount,
            'balance_after' => $user->credit, 'frozen_after' => $user->frozen_credit, 'reference_key' => $key]);
    }

    /** @param Closure(PaymentPostingResult): void $beforeNotification */
    private function complete(PaymentPostingResult $result, Closure $beforeNotification): PaymentPostingResult
    {
        $beforeNotification($result);

        return $result;
    }
}
