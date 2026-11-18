<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\GatewayChargeResult;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Services\DriverManager;
use App\Support\EpayProfiles;
use App\Support\Money;
use App\Support\RenewalRecovery;
use App\Support\SettlementCurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InitiatePaymentAction
{
    public function __construct(private readonly DriverManager $drivers) {}

    public function execute(Invoice $invoice, User $actor): GatewayChargeResult
    {
        abort_unless($invoice->user_id === $actor->id, 403);
        SettlementCurrency::assertSupported($invoice->currency);
        [$attempt, $gateway] = DB::transaction(function () use ($invoice, $actor): array {
            $configuration = app(ConfigureEpayAction::class);
            $configuration->lockConfiguration();
            $profile = $configuration->captureCurrentProfile();
            $gateway = $this->drivers->payment($invoice->gateway ?? '', $profile);
            if ($profile === null || ! $gateway->isEnabled()) {
                throw ValidationException::withMessages(['gateway' => 'Payment gateway is not configured.']);
            }
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $family = $invoice->type === InvoiceType::RENEW ? app(RenewalRecovery::class)->rows($invoice, true) : null;
            $locked = $family === null ? Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail() : $family->firstWhere('id', $invoice->id);
            if ($family !== null) {
                app(RenewalRecovery::class)->assertPayable($locked, $family);
            }
            if (($locked->line_items['automatic_renewal']['selection_pending'] ?? false) === true) {
                throw ValidationException::withMessages(['invoice' => 'Confirm the automatic renewal payment allocation first.']);
            }
            SettlementCurrency::assertSupported($locked->currency);
            if ($locked->user_id !== $actor->id || $locked->gateway !== $gateway->getIdentifier() || $locked->status !== InvoiceStatus::UNPAID
                || ($locked->expires_at !== null && $locked->expires_at->isPast()) || Money::isZero($locked->gateway_amount)) {
                throw ValidationException::withMessages(['invoice' => 'Invoice cannot be paid.']);
            }

            $attempt = PaymentAttempt::create(['invoice_id' => $locked->id, 'gateway' => $locked->gateway,
                'out_trade_no' => 'PAY'.Str::ulid(), 'amount' => $locked->gateway_amount, 'status' => 'created',
                'request_payload' => [EpayProfiles::ATTEMPT_KEY => $profile['id']]]);

            return [$attempt, $gateway];
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
