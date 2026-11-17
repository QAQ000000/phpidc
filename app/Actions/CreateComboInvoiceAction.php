<?php

declare(strict_types=1);

namespace App\Actions;

use App\Contracts\ConfigurableResource;
use App\Contracts\LiveQuotable;
use App\Contracts\LocalPriced;
use App\Contracts\RenewableResource;
use App\Enums\BillingCycle;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Services\DriverManager;
use App\Services\SettingService;
use App\Support\Money;
use App\Support\ProvisioningValidator;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class CreateComboInvoiceAction
{
    public function __construct(private readonly DriverManager $drivers, private readonly SettingService $settings) {}

    public function execute(User $user, Product $product, BillingCycle $billingCycle, array $options, bool $useCredit, ?string $gateway = null, array $input = []): Invoice
    {
        $product = $product->fresh();
        $options = app(ResolveProductOptionsAction::class)->execute($product, $billingCycle->value, $options);
        $driver = $this->drivers->fulfillment($product->driver_type, $product->upstream_api_id);
        $liveQuote = null;
        $validated = null;
        $fingerprint = $product->getRawOriginal();
        if ($driver instanceof LiveQuotable) {
            [$validated, $liveQuote] = app(CallUpstreamAction::class)->execute(fn () => [
                $driver->validateParams($product, $input), $driver->quotePurchase($product, $billingCycle->value, $options),
            ]);
        }

        return DB::transaction(function () use ($user, $product, $billingCycle, $options, $useCredit, $gateway, $input, $liveQuote, $validated, $fingerprint): Invoice {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $product = Product::findOrFail($product->id);
            $driver = $this->drivers->fulfillment($product->driver_type, $product->upstream_api_id);
            // Use current server rules inside the same transaction as wallet changes.
            $params = $liveQuote === null ? $driver->validateParams($product, $input) : app(ProvisioningValidator::class)->validate($product, $input);
            if ($liveQuote !== null && $product->getRawOriginal() !== $fingerprint) {
                throw ValidationException::withMessages(['product_id' => 'Product changed during quotation; request a new quote.']);
            }
            if (isset($product->provisioning_rules['classic_catalog']) && app(ResolveProductOptionsAction::class)->execute($product, $billingCycle->value, $options) !== $options) {
                throw ValidationException::withMessages(['options' => 'Configuration changed during quotation.']);
            }
            $params = $validated ?? $params;
            if ($product->is_hidden || ! $product->category()->where('is_hidden', false)->exists() || ($product->require_kyc && $user->kyc_verified_at === null)
                || ($product->require_phone && $user->phone === null)) {
                throw ValidationException::withMessages(['product_id' => 'This product is not available for this account.']);
            }
            if ($liveQuote === null && ! $driver instanceof LocalPriced) {
                throw ValidationException::withMessages(['product_id' => 'Only local priced purchases are supported.']);
            }
            $quote = $liveQuote ?? $driver->calculateLocalPurchase($product, $billingCycle->value, $options);
            if ($quote->currency !== $product->currency) {
                throw ValidationException::withMessages(['currency' => 'Quote currency mismatch.']);
            }
            $subtotal = Money::add($quote->downstreamPrice, $quote->setupFee);
            $couponResult = app(ApplyCouponAction::class)->execute($user, $product, InvoiceType::NEW_ORDER, $subtotal, $product->currency, $input['coupon_code'] ?? null);
            $discount = $couponResult['discount'];
            $total = Money::sub($subtotal, $discount);
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
            $affiliate = app(AffiliateSnapshotAction::class)->execute($user, $product);
            $invoice = Invoice::create([
                'invoice_no' => 'INV'.Str::ulid(), 'user_id' => $user->id, 'product_id' => $product->id,
                'type' => InvoiceType::NEW_ORDER, 'currency' => $product->currency, 'total_amount' => $total,
                'credit_applied' => $credit, 'gateway_amount' => $gatewayAmount, 'discount_amount' => $discount, 'coupon_id' => $couponResult['coupon']?->id,
                'upstream_expected_cost' => $quote->upstreamBaseCost,
                'gateway' => Money::isZero($gatewayAmount) ? null : $gateway, 'status' => InvoiceStatus::UNPAID,
                'expires_at' => now()->addMinutes(max(1, $this->settings->getInt('billing.invoice_expiry_minutes', 30))),
                'line_items' => ['items' => $items, 'subtotal' => $subtotal, 'discount' => $discount, 'total' => $total,
                    ...($affiliate === null ? [] : ['affiliate' => $affiliate]),
                    'purchase' => ['recurring_amount' => $quote->downstreamPrice, 'name' => $product->name, 'driver_type' => $product->driver_type->value,
                        'upstream_api_id' => $product->upstream_api_id, 'billing_cycle' => $billingCycle->value, 'hostname' => $params['hostname'] ?? null,
                        'upstream_pid' => $product->upstream_pid, 'options' => $liveQuote === null ? $options : ['configoption' => $options['configoption'] ?? []],
                        'encrypted_params' => Crypt::encryptString(json_encode($params + ['options' => $options, 'upstream_pid' => $product->upstream_pid, 'expected_cost' => $quote->upstreamBaseCost], JSON_THROW_ON_ERROR))]],
            ]);
            app(ApplyCouponAction::class)->record($invoice, $couponResult['coupon']);
            if (! Money::eq($subtotal, Money::add($total, $discount))) {
                throw new LogicException('Invoice discount identity failed.');
            }
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

    public function renewal(User $user, Instance $instance, bool $useCredit = false, ?string $gateway = 'epay_wechat', ?string $requestId = null, ?string $couponCode = null): Invoice
    {
        return $this->service($user, $instance, InvoiceType::RENEW, [], $useCredit, $gateway, $requestId, $couponCode);
    }

    public function upgrade(User $user, Instance $instance, array $options, string $requestId, bool $useCredit = false, ?string $gateway = 'epay_wechat'): Invoice
    {
        if (! Str::isUlid($requestId)) {
            throw ValidationException::withMessages(['request_id' => 'A server-issued request identifier is required.']);
        }

        return $this->service($user, $instance, InvoiceType::UPGRADE, $options, $useCredit, $gateway, $requestId);
    }

    private function service(User $user, Instance $instance, InvoiceType $type, array $options, bool $useCredit, ?string $gateway, ?string $requestId, ?string $couponCode = null): Invoice
    {
        abort_unless($instance->user_id === $user->id, 403);
        if ($requestId !== null) {
            $existing = Invoice::where('user_id', $user->id)->where('instance_id', $instance->id)->where('type', $type)->where('line_items->request_id', $requestId)->first();
            if ($existing !== null) {
                return $existing;
            }
        }
        $instance = $instance->fresh();
        $driver = $this->drivers->fulfillment($instance->driver_type, $instance->upstream_api_id);
        if (! $driver instanceof RenewableResource || ! $instance->billing_cycle->isRenewable() || ! $instance->status->isBillable()) {
            throw ValidationException::withMessages(['instance' => 'A live renewable instance is required.']);
        }
        $snapshot = app(CallUpstreamAction::class)->execute(fn () => $driver->syncInfo($instance));
        if (! app(ApplyInstanceSnapshotAction::class)->execute($instance, $snapshot)) {
            throw ValidationException::withMessages(['instance' => 'Instance changed during pre-sync.']);
        }
        $instance = $instance->fresh();
        if (! $instance->status->isBillable() || $instance->next_due_at === null || isset($instance->active_config['cancellation'])) {
            throw ValidationException::withMessages(['instance' => 'A known due boundary and uncancelled live service are required.']);
        }
        $boundary = $instance->next_due_at->copy()->utc()->format('Y-m-d\TH:i:s\Z');
        $key = $instance->id.':'.$type->value.':'.($type === InvoiceType::RENEW ? $boundary : $requestId);
        $existing = Invoice::where('billing_period_key', $key)->first();
        if ($existing !== null) {
            return $existing;
        }
        $product = $instance->product;
        $target = $type === InvoiceType::UPGRADE ? app(ResolveProductOptionsAction::class)->execute($product, $instance->billing_cycle->value, $options, false) : [];
        $recurring = $type === InvoiceType::UPGRADE ? app(UpgradeCalculatorAction::class)->execute($instance, $target) : $instance->recurring_amount;
        if ($type === InvoiceType::UPGRADE) {
            $target['changes'] = array_diff_assoc($target['configoption'], $instance->active_config['upstream_configoption'] ?? []);
        }
        if ($type === InvoiceType::UPGRADE && (! $driver instanceof ConfigurableResource || ! $driver instanceof LiveQuotable)) {
            throw ValidationException::withMessages(['instance' => 'This driver does not support configuration upgrades.']);
        }
        $fingerprint = $instance->getRawOriginal();
        $productFingerprint = $product->getRawOriginal();
        if ($type === InvoiceType::RENEW) {
            $quote = app(CallUpstreamAction::class)->execute(fn () => $driver->quoteRenewal($instance, $instance->billing_cycle->value));
        } elseif ($driver instanceof LiveQuotable) {
            $quote = app(CallUpstreamAction::class)->execute(fn () => $driver->quoteUpgrade($instance, $target));
        } else {
            throw ValidationException::withMessages(['instance' => 'Upgrade quotation is unavailable.']);
        }
        if ($quote->currency !== $instance->currency) {
            throw ValidationException::withMessages(['currency' => 'Quote currency mismatch.']);
        }

        return DB::transaction(function () use ($user, $instance, $product, $type, $key, $quote, $target, $recurring, $boundary, $useCredit, $gateway, $fingerprint, $productFingerprint, $requestId, $couponCode): Invoice {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($requestId !== null) {
                $repeated = Invoice::where('user_id', $user->id)->where('instance_id', $instance->id)->where('type', $type)->where('line_items->request_id', $requestId)->lockForUpdate()->first();
                if ($repeated !== null) {
                    return $repeated;
                }
            }
            $existing = Invoice::where('billing_period_key', $key)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }
            $unpaid = Invoice::where('instance_id', $instance->id)->where('status', InvoiceStatus::UNPAID)->lockForUpdate()->exists();
            $locked = Instance::whereKey($instance->id)->lockForUpdate()->firstOrFail();
            if ($unpaid || $locked->getRawOriginal() !== $fingerprint || $product->fresh()->getRawOriginal() !== $productFingerprint
                || FulfillmentTask::where('instance_id', $locked->id)->whereIn('status', [FulfillmentTaskStatus::PENDING, FulfillmentTaskStatus::RUNNING, FulfillmentTaskStatus::FAILED])->exists()) {
                throw ValidationException::withMessages(['instance' => 'State or price changed, or an earlier operation requires reconciliation.']);
            }
            $subtotal = Money::add($quote->downstreamPrice, $quote->setupFee);
            $couponResult = app(ApplyCouponAction::class)->execute($user, $product, $type, $subtotal, $locked->currency, $couponCode);
            $discount = $couponResult['discount'];
            $total = Money::sub($subtotal, $discount);
            $credit = $useCredit ? Money::min($user->credit, $total) : Money::zero();
            $gatewayAmount = Money::sub($total, $credit);
            if (! Money::isZero($gatewayAmount) && ! in_array($gateway, ['epay_wechat', 'epay_alipay'], true)) {
                throw ValidationException::withMessages(['gateway' => 'Select a payment gateway.']);
            }
            if (! Money::eq(Money::add($credit, $gatewayAmount), $total)) {
                throw new LogicException('Invoice amount identity failed.');
            }
            if ($type === InvoiceType::UPGRADE && app(ResolveProductOptionsAction::class)->execute($product->fresh(), $locked->billing_cycle->value, $target, false) !== array_diff_key($target, ['changes' => true])) {
                throw ValidationException::withMessages(['options' => 'Configuration changed during quotation.']);
            }
            $operation = ['action' => $type->value, 'driver_type' => $locked->driver_type->value, 'upstream_api_id' => $locked->upstream_api_id,
                'upstream_instance_id' => $locked->upstream_instance_id, 'token_hash' => hash('sha256', $locked->downstream_token ?? ''),
                'base_url' => $locked->upstreamApi?->base_url, 'due_boundary' => $boundary, 'cycle' => $locked->billing_cycle->value,
                'renew_until' => $this->renewUntil($locked),
                'recurring_after' => $type === InvoiceType::RENEW ? $quote->downstreamPrice : $recurring, 'options' => $target];
            $invoice = Invoice::create(['invoice_no' => 'INV'.Str::ulid(), 'user_id' => $user->id, 'product_id' => $locked->product_id,
                'instance_id' => $locked->id, 'type' => $type, 'billing_period_key' => $key, 'currency' => $locked->currency,
                'status' => InvoiceStatus::UNPAID, 'total_amount' => $total, 'discount_amount' => $discount, 'coupon_id' => $couponResult['coupon']?->id, 'credit_applied' => $credit,
                'gateway_amount' => $gatewayAmount, 'upstream_expected_cost' => $quote->upstreamBaseCost, 'gateway' => Money::isZero($gatewayAmount) ? null : $gateway,
                'expires_at' => $type === InvoiceType::RENEW ? max(now()->addMinutes(30), $locked->next_due_at->copy()->addDays(max(1, $this->settings->getInt('billing.renewal_grace_days', 7)))) : now()->addMinutes(max(1, $this->settings->getInt('billing.invoice_expiry_minutes', 30))),
                'line_items' => ['items' => [['kind' => 'product', 'ref_id' => $locked->product_id, 'label' => $type->value.' '.$locked->display_name,
                    'qty' => 1, 'unit_price' => $subtotal, 'amount' => $subtotal, 'cycle' => $locked->billing_cycle->value]],
                    'subtotal' => $subtotal, 'discount' => $discount, 'total' => $total, 'request_id' => $requestId,
                    'encrypted_operation' => Crypt::encryptString(json_encode($operation, JSON_THROW_ON_ERROR))]]);
            app(ApplyCouponAction::class)->record($invoice, $couponResult['coupon']);
            if (! Money::eq($subtotal, Money::add($total, $discount))) {
                throw new LogicException('Invoice discount identity failed.');
            }
            if (! Money::isZero($credit)) {
                $user->forceFill(['credit' => Money::sub($user->credit, $credit), 'frozen_credit' => Money::add($user->frozen_credit, $credit)])->save();
                $this->ledger($user, $invoice, TransactionType::CREDIT_FREEZE, $credit, "freeze:{$invoice->id}");
            }
            if (Money::isZero($gatewayAmount)) {
                $user->forceFill(['frozen_credit' => Money::sub($user->frozen_credit, $credit)])->save();
                $this->ledger($user, $invoice, TransactionType::CREDIT_SETTLE, $credit, "settle:{$invoice->id}");
                $invoice->update(['status' => InvoiceStatus::PAID, 'paid_at' => now()]);
                app(CreateServiceFulfillmentAction::class)->execute($invoice);
            }
            if ($type === InvoiceType::RENEW) {
                DB::afterCommit(fn () => app(NotifyRenewalAction::class)->execute($invoice));
            }

            return $invoice;
        });
    }

    private function renewUntil(Instance $instance): string
    {
        $due = $instance->next_due_at->copy();
        $pay = $instance->active_config['binding_pay_type'] ?? $instance->product->provisioning_rules['classic_catalog']['pay_type'] ?? [];
        $pay = is_string($pay) ? json_decode($pay, true, flags: JSON_THROW_ON_ERROR) : $pay;
        $multiple = max(1, (int) ($pay['pay_'.$instance->billing_cycle->value.'_cycle'] ?? 1));
        $months = $instance->billing_cycle->months();
        $until = $months !== null ? $due->addMonthsNoOverflow($months * $multiple) : ($instance->billing_cycle === BillingCycle::HOUR ? $due->addHours($multiple) : $due->addDays($multiple));

        return $until->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private function ledger(User $user, Invoice $invoice, TransactionType $type, string $amount, string $key): void
    {
        FinancialTransaction::create(['user_id' => $user->id, 'invoice_id' => $invoice->id, 'type' => $type, 'amount' => $amount,
            'balance_after' => $user->credit, 'frozen_after' => $user->frozen_credit, 'reference_key' => $key]);
    }
}
