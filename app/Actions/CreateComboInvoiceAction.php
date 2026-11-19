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
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Services\DriverManager;
use App\Services\SettingService;
use App\Support\MachineOperation;
use App\Support\Money;
use App\Support\ProvisioningValidator;
use App\Support\PurchaseQuoteConfirmation;
use App\Support\PurchaseRequest;
use App\Support\RenewalRecovery;
use App\Support\SettlementCurrency;
use App\Support\WalletCapacity;
use Illuminate\Contracts\Encryption\DecryptException;
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
        $request = app(PurchaseRequest::class);
        $requestId = $request->id($input);
        $snapshot = $request->snapshot($product->id, $billingCycle, $options, $useCredit, $gateway, $input);
        if (($existing = app(FindPurchaseRequestAction::class)->execute($user->id, $requestId, $snapshot)) !== null) {
            return $existing;
        }
        $product = $product->fresh();
        SettlementCurrency::assertSupported($product->currency);
        $options = app(ResolveProductOptionsAction::class)->execute($product, $billingCycle->value, $options);
        $driver = $this->drivers->fulfillment($product->driver_type, $product->upstream_api_id);
        $liveQuote = null;
        $validated = null;
        $fingerprint = $product->getRawOriginal();
        if ($driver instanceof LiveQuotable) {
            $confirmation = app(PurchaseQuoteConfirmation::class);
            $confirmed = ($input['require_price_confirmation'] ?? false)
                ? $confirmation->read($input['quote_token'] ?? null, $user, $product, $billingCycle->value, $options) : null;
            [$validated, $liveQuote] = app(CallUpstreamAction::class)->execute(fn () => [
                $driver->validateParams($product, $input), $driver->quotePurchase($product, $billingCycle->value, $options),
            ]);
            if ($input['require_price_confirmation'] ?? false) {
                $confirmation->assertMatches($confirmed, $user, $product, $billingCycle->value, $options, $liveQuote);
            }
        }

        return DB::transaction(function () use ($user, $product, $billingCycle, $options, $useCredit, $gateway, $input, $liveQuote, $validated, $fingerprint, $requestId, $snapshot): Invoice {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (($existing = app(FindPurchaseRequestAction::class)->execute($user->id, $requestId, $snapshot, lock: true)) !== null) {
                return $existing;
            }
            $product = Product::findOrFail($product->id);
            SettlementCurrency::assertSupported($product->currency);
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
            SettlementCurrency::assertSupported($quote->currency);
            $subtotal = Money::add($quote->downstreamPrice, $quote->setupFee);
            $couponResult = app(ApplyCouponAction::class)->execute($user, $product, InvoiceType::NEW_ORDER, $subtotal, $product->currency, $input['coupon_code'] ?? null);
            $discount = $couponResult['discount'];
            $total = Money::sub($subtotal, $discount);
            $credit = $useCredit ? Money::min($user->credit, $total) : Money::zero();
            $gatewayAmount = Money::sub($total, $credit);
            $this->assertWalletAllocation($user, $credit, $gatewayAmount);
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
                'invoice_no' => 'INV'.Str::ulid(), 'user_id' => $user->id, 'product_id' => $product->id, 'purchase_request_id' => $requestId,
                'type' => InvoiceType::NEW_ORDER, 'currency' => $product->currency, 'total_amount' => $total,
                'credit_applied' => $credit, 'gateway_amount' => $gatewayAmount, 'discount_amount' => $discount, 'coupon_id' => $couponResult['coupon']?->id,
                'upstream_expected_cost' => $quote->upstreamBaseCost,
                'gateway' => Money::isZero($gatewayAmount) ? null : $gateway, 'status' => InvoiceStatus::UNPAID,
                'expires_at' => now()->addMinutes(max(1, $this->settings->getInt('billing.invoice_expiry_minutes', 30))),
                'line_items' => ['items' => $items, 'subtotal' => $subtotal, 'discount' => $discount, 'total' => $total,
                    ...($affiliate === null ? [] : ['affiliate' => $affiliate]),
                    'purchase' => ['recurring_amount' => $quote->downstreamPrice, 'name' => $product->name, 'driver_type' => $product->driver_type->value,
                        'encrypted_request' => Crypt::encryptString($snapshot),
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

    public function automaticRenewal(User $user, Instance $instance): Invoice
    {
        return $this->service($user, $instance, InvoiceType::RENEW, [], false, 'epay_wechat', null, automatic: true);
    }

    /** Internal handoff of the row just synchronized by the current lifecycle invocation. */
    public function automaticRenewalFromLifecycle(User $user, Instance $instance, array $binding, string $accountFingerprint): Invoice
    {
        return $this->service($user, $instance, InvoiceType::RENEW, [], false, 'epay_wechat', null, automatic: true,
            lifecycleSnapshot: ['attributes' => $instance->getRawOriginal(), 'binding' => $binding, 'account_fingerprint' => $accountFingerprint]);
    }

    public function recoverRenewal(User $user, Invoice $parent, string $requestId, string $quoteToken, bool $useCredit, ?string $gateway, ?string $couponCode, string $reason): Invoice
    {
        abort_unless($parent->user_id === $user->id, 403);
        $parent = $parent->fresh();
        abort_unless($parent->user_id === $user->id, 403);
        $recovery = app(RenewalRecovery::class);
        $requestId = app(PurchaseRequest::class)->id(['request_id' => $requestId]);
        if (trim($reason) === '' || mb_strlen($reason) > 1000) {
            $recovery->reject('Supply a recovery reason of 1–1000 characters.', 'reason');
        }
        $identity = json_encode(['version' => 1, 'parent_id' => $parent->id, 'request_id' => $requestId, 'quote_token' => $quoteToken,
            'use_credit' => $useCredit, 'gateway' => $gateway, 'coupon_code' => $couponCode, 'reason' => $reason], JSON_THROW_ON_ERROR);
        if (($existing = $recovery->repeat($parent, $requestId, $identity)) !== null) {
            SettlementCurrency::assertSupported($existing->currency);

            return $existing;
        }
        try {
            $confirmed = json_decode(Crypt::decryptString($quoteToken), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException) {
            $recovery->reject('Invalid recovery quotation. Request another quote.');
        }
        if (! is_array($confirmed) || ($confirmed['version'] ?? null) !== 1 || ($confirmed['user_id'] ?? null) !== $user->id
            || ($confirmed['parent_id'] ?? null) !== $parent->id || ($confirmed['use_credit'] ?? null) !== $useCredit
            || ($confirmed['gateway'] ?? null) !== $gateway || ($confirmed['coupon_code'] ?? null) !== $couponCode
            || ! is_int($confirmed['expires'] ?? null) || $confirmed['expires'] <= now()->getTimestamp()) {
            $recovery->reject('Recovery quotation expired or parameters changed. Request another quote.');
        }
        $instance = $parent->instance()->firstOrFail();
        $root = $recovery->eligible($parent, $instance);
        if (($confirmed['root_id'] ?? null) !== $root->id) {
            $recovery->reject('Recovery root changed.');
        }

        return $this->service($user, $instance, InvoiceType::RENEW, [], $useCredit, $gateway, $requestId, $couponCode,
            ['parent' => $parent, 'identity' => $identity, 'confirmed' => $confirmed, 'reason' => $reason]);
    }

    public function upgrade(User $user, Instance $instance, array $options, string $requestId, bool $useCredit = false, ?string $gateway = 'epay_wechat'): Invoice
    {
        if (! Str::isUlid($requestId)) {
            throw ValidationException::withMessages(['request_id' => 'A server-issued request identifier is required.']);
        }

        return $this->service($user, $instance, InvoiceType::UPGRADE, $options, $useCredit, $gateway, $requestId);
    }

    private function service(User $user, Instance $instance, InvoiceType $type, array $options, bool $useCredit, ?string $gateway, ?string $requestId, ?string $couponCode = null, ?array $recovery = null, bool $automatic = false, ?array $lifecycleSnapshot = null): Invoice
    {
        abort_unless($instance->user_id === $user->id, 403);
        $instance = $instance->fresh();
        abort_unless($instance->user_id === $user->id, 403);
        SettlementCurrency::assertSupported($instance->currency);
        if ($requestId !== null && $recovery === null) {
            $existing = Invoice::where('user_id', $user->id)->where('instance_id', $instance->id)->where('type', $type)->where('line_items->request_id', $requestId)->first();
            if ($existing !== null) {
                SettlementCurrency::assertSupported($existing->currency);
                $this->assertAutomaticSelectionReplay($existing, $useCredit, $gateway, $couponCode);

                return $existing;
            }
        }
        $instance = $instance->fresh();
        $driver = $this->drivers->fulfillment($instance->driver_type, $instance->upstream_api_id);
        if (! $driver instanceof RenewableResource || ! $instance->billing_cycle->isRenewable() || ! $instance->status->isBillable()) {
            throw ValidationException::withMessages(['instance' => 'A live renewable instance is required.']);
        }
        if ($lifecycleSnapshot !== null) {
            $this->assertLifecycleSnapshot($instance, $lifecycleSnapshot);
        } else {
            $snapshot = app(CallUpstreamAction::class)->execute(fn () => $driver->syncInfo($instance));
            if (! app(ApplyInstanceSnapshotAction::class)->execute($instance, $snapshot)) {
                throw ValidationException::withMessages(['instance' => 'Instance changed during pre-sync.']);
            }
            $instance = $instance->fresh();
        }
        if (! $instance->status->isBillable() || $instance->next_due_at === null || isset($instance->active_config['cancellation'])) {
            throw ValidationException::withMessages(['instance' => 'A known due boundary and uncancelled live service are required.']);
        }
        $boundary = $instance->next_due_at->copy()->utc()->format('Y-m-d\TH:i:s\Z');
        $key = $instance->id.':'.$type->value.':'.($type === InvoiceType::RENEW ? $boundary : $requestId);
        $existing = Invoice::where('billing_period_key', $key)->first();
        if ($existing !== null && $recovery === null) {
            SettlementCurrency::assertSupported($existing->currency);

            return $type === InvoiceType::RENEW
                ? $this->existingRenewal($user, $instance, app(RenewalRecovery::class)->current($existing), $useCredit, $gateway, $requestId, $couponCode)
                : $existing;
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
        SettlementCurrency::assertSupported($quote->currency);

        return DB::transaction(function () use ($user, $instance, $product, $type, $key, $quote, $target, $recurring, $boundary, $useCredit, $gateway, $fingerprint, $productFingerprint, $requestId, $couponCode, $recovery, $automatic, $lifecycleSnapshot): Invoice {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $recoveryRows = null;
            if ($recovery !== null) {
                $recoveryRows = app(RenewalRecovery::class)->rows($recovery['parent'], true);
                if (($repeated = app(RenewalRecovery::class)->repeat($recovery['parent'], $requestId, $recovery['identity'], $recoveryRows)) !== null) {
                    SettlementCurrency::assertSupported($repeated->currency);

                    return $repeated;
                }
            }
            if ($requestId !== null && $recovery === null) {
                $repeated = Invoice::where('user_id', $user->id)->where('instance_id', $instance->id)->where('type', $type)->where('line_items->request_id', $requestId)->lockForUpdate()->first();
                if ($repeated !== null) {
                    SettlementCurrency::assertSupported($repeated->currency);
                    $this->assertAutomaticSelectionReplay($repeated, $useCredit, $gateway, $couponCode);

                    return $repeated;
                }
            }
            $existing = Invoice::where('billing_period_key', $key)->lockForUpdate()->first();
            if ($existing !== null && $recovery === null) {
                SettlementCurrency::assertSupported($existing->currency);

                return $type === InvoiceType::RENEW
                    ? $this->existingRenewal($user, $instance, app(RenewalRecovery::class)->current($existing, true), $useCredit, $gateway, $requestId, $couponCode)
                    : $existing;
            }
            $unpaid = Invoice::where('instance_id', $instance->id)->where('status', InvoiceStatus::UNPAID)->lockForUpdate()->exists();
            $locked = Instance::whereKey($instance->id)->lockForUpdate()->firstOrFail();
            if ($lifecycleSnapshot !== null) {
                $locked->setRelation('upstreamApi', UpstreamApi::whereKey($locked->upstream_api_id)->lockForUpdate()->first());
                $this->assertLifecycleSnapshot($locked, $lifecycleSnapshot);
            }
            SettlementCurrency::assertSupported($locked->currency);
            if ($unpaid || $locked->getRawOriginal() !== $fingerprint || $product->fresh()->getRawOriginal() !== $productFingerprint
                || FulfillmentTask::where('instance_id', $locked->id)->whereIn('status', [FulfillmentTaskStatus::PENDING, FulfillmentTaskStatus::RUNNING, FulfillmentTaskStatus::FAILED])->exists()) {
                throw ValidationException::withMessages(['instance' => 'State or price changed, or an earlier operation requires reconciliation.']);
            }
            $root = null;
            if ($recovery !== null) {
                $guard = app(RenewalRecovery::class);
                $root = $guard->eligible($recovery['parent'], $locked, $recoveryRows, lock: true);
                $confirmed = $recovery['confirmed'];
                if ($confirmed['expires'] <= now()->getTimestamp() || $confirmed['fingerprint'] !== $guard->fingerprint($locked)
                    || $confirmed['payment_window_minutes'] !== max(1, $this->settings->getInt('billing.invoice_expiry_minutes', 30))
                    || $confirmed['currency'] !== $quote->currency || ! Money::eq($confirmed['upstream'], $quote->upstreamBaseCost)
                    || ! Money::eq($confirmed['period'], $quote->downstreamPrice) || ! Money::eq($confirmed['setup'], $quote->setupFee)) {
                    $guard->reject('Renewal quote or service changed. Request another quote.');
                }
            }
            $subtotal = Money::add($quote->downstreamPrice, $quote->setupFee);
            $couponResult = app(ApplyCouponAction::class)->execute($user, $product, $type, $subtotal, $locked->currency, $couponCode);
            $discount = $couponResult['discount'];
            $total = Money::sub($subtotal, $discount);
            $credit = $useCredit ? Money::min($user->credit, $total) : Money::zero();
            $gatewayAmount = Money::sub($total, $credit);
            $this->assertWalletAllocation($user, $credit, $gatewayAmount);
            if ($recovery !== null) {
                foreach (['subtotal' => $subtotal, 'discount' => $discount, 'total' => $total, 'credit' => $credit, 'gateway_amount' => $gatewayAmount] as $field => $amount) {
                    if (! Money::eq($recovery['confirmed'][$field], $amount)) {
                        app(RenewalRecovery::class)->reject('Coupon or payment allocation changed. Request another quote.');
                    }
                }
            }
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
            if ($automatic) {
                $operation['account_fingerprint'] = app(MachineOperation::class)->accountFingerprint($locked);
            }
            $invoice = Invoice::create(['invoice_no' => 'INV'.Str::ulid(), 'user_id' => $user->id, 'product_id' => $locked->product_id,
                'instance_id' => $locked->id, 'type' => $type, 'billing_period_key' => $recovery === null ? $key : null,
                'renewal_root_id' => $root?->id, 'replaces_invoice_id' => $recovery['parent']->id ?? null, 'currency' => $locked->currency,
                'status' => InvoiceStatus::UNPAID, 'total_amount' => $total, 'discount_amount' => $discount, 'coupon_id' => $couponResult['coupon']?->id, 'credit_applied' => $credit,
                'gateway_amount' => $gatewayAmount, 'upstream_expected_cost' => $quote->upstreamBaseCost, 'gateway' => Money::isZero($gatewayAmount) ? null : $gateway,
                'expires_at' => $type === InvoiceType::RENEW && $recovery === null ? max(now()->addMinutes(30), $locked->next_due_at->copy()->addDays(max(1, $this->settings->getInt('billing.renewal_grace_days', 7)))) : now()->addMinutes(max(1, $this->settings->getInt('billing.invoice_expiry_minutes', 30))),
                'line_items' => ['items' => [['kind' => 'product', 'ref_id' => $locked->product_id, 'label' => $type->value.' '.$locked->display_name,
                    'qty' => 1, 'unit_price' => $subtotal, 'amount' => $subtotal, 'cycle' => $locked->billing_cycle->value]],
                    'subtotal' => $subtotal, 'discount' => $discount, 'total' => $total, 'request_id' => $requestId,
                    ...($automatic ? ['automatic_renewal' => ['selection_pending' => ! Money::isZero($total)]] : []),
                    ...($recovery === null ? [] : ['recovery' => ['encrypted_request' => Crypt::encryptString($recovery['identity'])]]),
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
            if ($recovery !== null) {
                DB::table('audit_logs')->insert(['actor_type' => 'user', 'actor_id' => $user->id, 'action' => 'invoice.renewal_recovery',
                    'subject_type' => Invoice::class, 'subject_id' => $invoice->id, 'reason' => $recovery['reason'],
                    'before' => json_encode(['root_id' => $root->id, 'predecessor_id' => $recovery['parent']->id], JSON_THROW_ON_ERROR),
                    'after' => json_encode(['invoice_id' => $invoice->id, 'total' => $total, 'credit' => $credit, 'gateway_amount' => $gatewayAmount], JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now()]);
            }
            if ($type === InvoiceType::RENEW) {
                app(NotifyRenewalAction::class)->execute($invoice);
            }

            return $invoice;
        });
    }

    private function assertLifecycleSnapshot(Instance $instance, array $snapshot): void
    {
        $operation = app(MachineOperation::class);
        if ($instance->getRawOriginal() !== $snapshot['attributes'] || ! $instance->upstreamApi?->is_active
            || $operation->binding($instance) !== $snapshot['binding']
            || $operation->accountFingerprint($instance) !== $snapshot['account_fingerprint']) {
            throw ValidationException::withMessages(['instance' => 'The lifecycle synchronization is no longer current. Retry the lifecycle check.']);
        }
    }

    private function assertAutomaticSelectionReplay(Invoice $invoice, bool $useCredit, ?string $gateway, ?string $couponCode): void
    {
        $selection = $invoice->line_items['automatic_renewal']['selection'] ?? null;
        if ($selection !== null && (($selection['use_credit'] ?? null) !== $useCredit || ($selection['gateway'] ?? null) !== $gateway
            || ($selection['coupon_code'] ?? null) !== $couponCode)) {
            throw ValidationException::withMessages(['request_id' => 'This payment confirmation already has different choices.']);
        }
    }

    private function existingRenewal(User $user, Instance $instance, Invoice $invoice, bool $useCredit, ?string $gateway, ?string $requestId, ?string $couponCode): Invoice
    {
        if ($requestId === null) {
            return $invoice;
        }
        if (! Str::isUlid($requestId)) {
            throw ValidationException::withMessages(['request_id' => 'A server-issued request identifier is required.']);
        }
        if (! isset($invoice->line_items['automatic_renewal'])) {
            if ($invoice->status === InvoiceStatus::UNPAID && ($useCredit || $gateway !== $invoice->gateway || $couponCode !== null)) {
                throw ValidationException::withMessages(['invoice' => 'This existing invoice has a fixed payment allocation. Use its payment page or reconcile it before requesting a new renewal.']);
            }

            return $invoice;
        }

        return DB::transaction(function () use ($user, $instance, $invoice, $useCredit, $gateway, $requestId, $couponCode): Invoice {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $family = app(RenewalRecovery::class)->rows($invoice, true);
            $invoice = $family->firstWhere('id', $invoice->id);
            app(RenewalRecovery::class)->assertPayable($invoice, $family);
            SettlementCurrency::assertSupported($invoice->currency);
            $items = $invoice->line_items;
            if (($items['automatic_renewal']['selection_pending'] ?? false) !== true) {
                $this->assertAutomaticSelectionReplay($invoice, $useCredit, $gateway, $couponCode);

                return $invoice;
            }
            $attempts = PaymentAttempt::where('invoice_id', $invoice->id)->lockForUpdate()->get();
            $locked = Instance::whereKey($instance->id)->lockForUpdate()->firstOrFail();
            if ($locked->upstream_api_id !== null) {
                $locked->setRelation('upstreamApi', UpstreamApi::whereKey($locked->upstream_api_id)->lockForUpdate()->firstOrFail());
            }
            $operation = json_decode(Crypt::decryptString($items['encrypted_operation']), true, flags: JSON_THROW_ON_ERROR);
            if ($invoice->user_id !== $user->id || $locked->user_id !== $user->id || $invoice->instance_id !== $locked->id
                || $invoice->product_id !== $locked->product_id || $invoice->currency !== $locked->currency || $invoice->status !== InvoiceStatus::UNPAID || $invoice->paid_at !== null
                || ($invoice->expires_at !== null && $invoice->expires_at->lte(now())) || ! $locked->status->isBillable() || isset($locked->active_config['cancellation'])
                || ($locked->upstream_api_id !== null && ! $locked->upstreamApi?->is_active)
                || $attempts->isNotEmpty() || $invoice->callbacks()->exists()
                || ! Money::isZero($invoice->credit_applied) || ! Money::eq($invoice->gateway_amount, $invoice->total_amount)
                || FinancialTransaction::where('invoice_id', $invoice->id)->exists()
                || FulfillmentTask::where('instance_id', $locked->id)->whereIn('status', [FulfillmentTaskStatus::PENDING, FulfillmentTaskStatus::RUNNING, FulfillmentTaskStatus::FAILED])->exists()
                || ($operation['driver_type'] ?? null) !== $locked->driver_type->value || ($operation['upstream_api_id'] ?? null) !== $locked->upstream_api_id
                || ($operation['upstream_instance_id'] ?? null) !== $locked->upstream_instance_id || ($operation['base_url'] ?? null) !== $locked->upstreamApi?->base_url
                || ($operation['token_hash'] ?? null) !== hash('sha256', $locked->downstream_token ?? '') || ($operation['cycle'] ?? null) !== $locked->billing_cycle->value
                || ($operation['due_boundary'] ?? null) !== $locked->next_due_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z')
                || ($operation['account_fingerprint'] ?? null) !== app(MachineOperation::class)->accountFingerprint($locked)) {
                throw ValidationException::withMessages(['invoice' => 'The automatic renewal is no longer eligible for payment selection. Reconcile the existing invoice.']);
            }
            if ($couponCode !== null) {
                throw ValidationException::withMessages(['coupon_code' => 'The existing renewal price and coupon are fixed.']);
            }
            $credit = $useCredit ? Money::min($user->credit, $invoice->total_amount) : Money::zero();
            $gatewayAmount = Money::sub($invoice->total_amount, $credit);
            $this->assertWalletAllocation($user, $credit, $gatewayAmount);
            if (! Money::eq(Money::add($credit, $gatewayAmount), $invoice->total_amount)) {
                throw new LogicException('Invoice amount identity failed.');
            }
            if (! Money::isZero($gatewayAmount) && ! in_array($gateway, ['epay_wechat', 'epay_alipay'], true)) {
                throw ValidationException::withMessages(['gateway' => 'Select a supported payment gateway.']);
            }
            $items['request_id'] = $requestId;
            $items['automatic_renewal'] = ['selection_pending' => false, 'selection' => ['use_credit' => $useCredit, 'gateway' => $gateway, 'coupon_code' => $couponCode]];
            $invoice->update(['credit_applied' => $credit, 'gateway_amount' => $gatewayAmount,
                'gateway' => Money::isZero($gatewayAmount) ? null : $gateway, 'line_items' => $items]);
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
            DB::table('audit_logs')->insert(['actor_type' => 'user', 'actor_id' => $user->id, 'action' => 'invoice.automatic_renewal_confirm',
                'subject_type' => Invoice::class, 'subject_id' => $invoice->id, 'reason' => 'Owner confirmed automatic renewal payment allocation',
                'after' => json_encode(['credit' => $credit, 'gateway_amount' => $gatewayAmount, 'gateway' => $invoice->gateway]), 'created_at' => now(), 'updated_at' => now()]);

            return $invoice;
        });
    }

    private function assertWalletAllocation(User $user, string $credit, string $gatewayAmount): void
    {
        if (Money::isZero($credit)) {
            return;
        }
        $before = WalletCapacity::snapshot($user);
        $held = [...$before, 'credit' => Money::sub($before['credit'], $credit),
            'frozen_credit' => Money::add($before['frozen_credit'], $credit)];
        $after = Money::isZero($gatewayAmount) ? [...$held, 'frozen_credit' => $before['frozen_credit']] : $held;
        // Both the intermediate freeze ledger snapshot and the final result are persisted.
        if (! WalletCapacity::columnsFit($held) || ! WalletCapacity::allows($before, $after)) {
            throw ValidationException::withMessages(['credit' => '钱包分配超出可存储范围，请先完成资金核对。']);
        }
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
