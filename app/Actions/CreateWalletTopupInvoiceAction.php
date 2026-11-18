<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\User;
use App\Services\DriverManager;
use App\Services\SettingService;
use App\Support\Money;
use App\Support\WalletTopupIntent;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateWalletTopupInvoiceAction
{
    public function __construct(private readonly DriverManager $drivers, private readonly SettingService $settings) {}

    public function execute(User $user, array $input): Invoice
    {
        $intent = app(WalletTopupIntent::class);
        $request = $intent->request($input);
        if (! User::whereKey($user->id)->whereNull('anonymized_at')->exists()) {
            throw ValidationException::withMessages(['account' => 'An active account is required.']);
        }
        $existing = Invoice::where('user_id', $user->id)->where('topup_request_id', $request['id'])->first();
        if ($existing !== null) {
            return $this->replay($existing, $request['request']);
        }

        return DB::transaction(function () use ($user, $request, $intent): Invoice {
            $configuration = app(ConfigureWalletTopupAction::class);
            $configuration->lockConfiguration();
            $locked = User::whereKey($user->id)->whereNull('anonymized_at')->lockForUpdate()->first();
            if ($locked === null) {
                throw ValidationException::withMessages(['account' => 'An active account is required.']);
            }
            $existing = Invoice::where('user_id', $locked->id)->where('topup_request_id', $request['id'])->lockForUpdate()->first();
            if ($existing !== null) {
                return $this->replay($existing, $request['request']);
            }
            $amount = $request['request']['amount'];
            if (! $configuration->enabled()) {
                throw ValidationException::withMessages(['amount' => 'Wallet top-ups are currently disabled.']);
            }
            if (Money::gt(WalletTopupIntent::MINIMUM, $amount) || Money::gt($amount, WalletTopupIntent::MAXIMUM)
                || Money::gt(Money::add($locked->credit, $amount), WalletTopupIntent::WALLET_LIMIT)) {
                throw ValidationException::withMessages(['amount' => 'Top-ups must be CNY 1.00–10000.00 and fit the wallet balance limit.']);
            }
            if (! $this->drivers->payment($request['request']['gateway'])->isEnabled()) {
                throw ValidationException::withMessages(['gateway' => 'Payment gateway is not configured.']);
            }

            // D01: invoice-only creation; receipt accounting belongs to the verified callback transaction.
            return Invoice::create(['invoice_no' => 'INV'.Str::ulid(), 'user_id' => $locked->id,
                'type' => InvoiceType::WALLET_TOPUP, 'status' => InvoiceStatus::UNPAID, 'currency' => 'CNY',
                'topup_request_id' => $request['id'], 'total_amount' => $amount, 'gateway_amount' => $amount,
                'credit_applied' => '0.00', 'discount_amount' => '0.00', 'upstream_expected_cost' => '0.00',
                'gateway' => $request['request']['gateway'], 'product_id' => null, 'instance_id' => null, 'coupon_id' => null,
                'line_items' => ['items' => $intent->lines($amount), 'subtotal' => $amount, 'discount' => '0.00', 'total' => $amount,
                    'wallet_topup' => ['version' => 1, 'request' => $request['request']]],
                'expires_at' => now()->addMinutes(max(1, $this->settings->getInt('billing.invoice_expiry_minutes', 30)))]);
        });
    }

    private function replay(Invoice $invoice, array $request): Invoice
    {
        $stored = $invoice->line_items['wallet_topup']['request'] ?? null;
        if ($invoice->type !== InvoiceType::WALLET_TOPUP || ($invoice->line_items['wallet_topup']['version'] ?? null) !== 1
            || ! is_array($stored) || Arr::sortRecursive($stored) !== Arr::sortRecursive($request)) {
            throw ValidationException::withMessages(['request_id' => 'This top-up request was already used with different details.']);
        }

        return $invoice;
    }
}
