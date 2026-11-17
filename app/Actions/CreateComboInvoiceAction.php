<?php

declare(strict_types=1);

namespace App\Actions;

use App\Contracts\LocalPriced;
use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Services\DriverManager;
use App\Services\SettingService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class CreateComboInvoiceAction
{
    public function __construct(private readonly DriverManager $drivers, private readonly SettingService $settings) {}

    public function execute(User $user, Product $product, BillingCycle $billingCycle, array $options, bool $useCredit, ?string $gateway = null, array $input = []): Invoice
    {
        return DB::transaction(function () use ($user, $product, $billingCycle, $options, $useCredit, $gateway, $input): Invoice {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $product = Product::findOrFail($product->id);
            $driver = $this->drivers->fulfillment($product->driver_type, $product->upstream_api_id);
            // Use current server rules inside the same transaction as wallet changes.
            $params = $driver->validateParams($product, $input);
            if ($product->is_hidden || ($product->require_kyc && $user->kyc_verified_at === null)
                || ($product->require_phone && $user->phone === null)) {
                throw ValidationException::withMessages(['product_id' => 'This product is not available for this account.']);
            }
            if (! $driver instanceof LocalPriced) {
                throw ValidationException::withMessages(['product_id' => 'Only local priced purchases are supported.']);
            }
            $quote = $driver->calculateLocalPurchase($product, $billingCycle->value, $options);
            if ($quote->currency !== $product->currency) {
                throw ValidationException::withMessages(['currency' => 'Quote currency mismatch.']);
            }
            $total = Money::add($quote->downstreamPrice, $quote->setupFee);
            $credit = $useCredit ? Money::min($user->credit, $total) : Money::zero();
            $gatewayAmount = Money::sub($total, $credit);
            if (! Money::isZero($gatewayAmount) && ! in_array($gateway, ['epay_wechat', 'epay_alipay'], true)) {
                throw ValidationException::withMessages(['gateway' => 'Select a supported payment gateway.']);
            }
            if (! Money::eq(Money::add($credit, $gatewayAmount), $total)) {
                throw new LogicException('Invoice amount identity failed.');
            }
            $items = [['kind' => 'product', 'ref_id' => $product->id, 'label' => $product->name, 'qty' => 1,
                'unit_price' => $quote->downstreamPrice, 'amount' => $quote->downstreamPrice, 'cycle' => $billingCycle->value]];
            if (! Money::isZero($quote->setupFee)) {
                $items[] = ['kind' => 'setup_fee', 'ref_id' => $product->id, 'label' => 'Setup', 'qty' => 1,
                    'unit_price' => $quote->setupFee, 'amount' => $quote->setupFee, 'cycle' => null];
            }
            $invoice = Invoice::create([
                'invoice_no' => 'INV'.Str::ulid(), 'user_id' => $user->id, 'product_id' => $product->id,
                'type' => InvoiceType::NEW_ORDER, 'currency' => $product->currency, 'total_amount' => $total,
                'credit_applied' => $credit, 'gateway_amount' => $gatewayAmount, 'discount_amount' => Money::zero(),
                'upstream_expected_cost' => $quote->upstreamBaseCost,
                'gateway' => Money::isZero($gatewayAmount) ? null : $gateway, 'status' => InvoiceStatus::UNPAID,
                'expires_at' => now()->addMinutes(max(1, $this->settings->getInt('billing.invoice_expiry_minutes', 30))),
                'line_items' => ['items' => $items, 'subtotal' => $total, 'discount' => Money::zero(), 'total' => $total,
                    'purchase' => ['name' => $product->name, 'driver_type' => $product->driver_type->value,
                        'upstream_api_id' => $product->upstream_api_id, 'billing_cycle' => $billingCycle->value, 'hostname' => $params['hostname'] ?? null]],
            ]);
            if (! Money::isZero($credit)) {
                $user->forceFill(['credit' => Money::sub($user->credit, $credit), 'frozen_credit' => Money::add($user->frozen_credit, $credit)])->save();
                $this->ledger($user, $invoice, TransactionType::CREDIT_FREEZE, $credit, "freeze:{$invoice->id}");
            }
            if (Money::isZero($gatewayAmount)) {
                $user->forceFill(['frozen_credit' => Money::sub($user->frozen_credit, $credit)])->save();
                $this->ledger($user, $invoice, TransactionType::CREDIT_SETTLE, $credit, "settle:{$invoice->id}");
                $invoice->update(['status' => InvoiceStatus::PAID, 'paid_at' => now()]);
                app(CreatePurchaseFulfillmentAction::class)->execute($invoice);
            }

            return $invoice;
        });
    }

    private function ledger(User $user, Invoice $invoice, TransactionType $type, string $amount, string $key): void
    {
        FinancialTransaction::create(['user_id' => $user->id, 'invoice_id' => $invoice->id, 'type' => $type, 'amount' => $amount,
            'balance_after' => $user->credit, 'frozen_after' => $user->frozen_credit, 'reference_key' => $key]);
    }
}
