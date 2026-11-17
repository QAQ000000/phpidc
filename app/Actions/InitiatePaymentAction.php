<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\GatewayChargeResult;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Services\DriverManager;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InitiatePaymentAction
{
    public function __construct(private readonly DriverManager $drivers) {}

    public function execute(Invoice $invoice, User $actor): GatewayChargeResult
    {
        abort_unless($invoice->user_id === $actor->id, 403);
        $gateway = $this->drivers->payment($invoice->gateway ?? '');
        if (! $gateway->isEnabled()) {
            throw ValidationException::withMessages(['gateway' => 'Payment gateway is not configured.']);
        }
        $attempt = DB::transaction(function () use ($invoice, $actor, $gateway): PaymentAttempt {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $locked = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($locked->user_id !== $actor->id || $locked->gateway !== $gateway->getIdentifier() || $locked->status !== InvoiceStatus::UNPAID
                || ($locked->expires_at !== null && $locked->expires_at->isPast()) || Money::isZero($locked->gateway_amount)) {
                throw ValidationException::withMessages(['invoice' => 'Invoice cannot be paid.']);
            }

            return PaymentAttempt::create(['invoice_id' => $locked->id, 'gateway' => $locked->gateway,
                'out_trade_no' => 'PAY'.Str::ulid(), 'amount' => $locked->gateway_amount, 'status' => 'created']);
        });
        // The committed attempt exists before HTTP; exceptions remain visible and it is retained.
        $result = $gateway->charge($invoice, $attempt->amount, $attempt->out_trade_no);
        if (! $result->success) {
            DB::transaction(function () use ($invoice, $actor, $attempt): void {
                User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                $locked = PaymentAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === 'created') {
                    $locked->update(['status' => 'failed']);
                }
            });
        }

        return $result;
    }
}
