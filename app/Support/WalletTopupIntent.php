<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\InvoiceType;
use App\Models\Invoice;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WalletTopupIntent
{
    public const MINIMUM = '1.00';

    public const MAXIMUM = '10000.00';

    public const WALLET_LIMIT = '9999999999.99';

    /** @return array{id: string, request: array{amount: string, currency: string, gateway: string}} */
    public function request(array $input): array
    {
        $id = $input['request_id'] ?? null;
        if (! is_string($id) || ! Str::isUlid($id)) {
            throw ValidationException::withMessages(['request_id' => 'A stable top-up request ULID is required.']);
        }
        $amount = $input['amount'] ?? null;
        if (! is_string($amount) || ! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $amount)) {
            throw ValidationException::withMessages(['amount' => 'Enter a decimal string with at most two decimal places.']);
        }
        $gateway = $input['gateway'] ?? null;
        if (! is_string($gateway) || ! in_array($gateway, ['epay_wechat', 'epay_alipay'], true)) {
            throw ValidationException::withMessages(['gateway' => 'Select a supported payment gateway.']);
        }

        return ['id' => strtoupper($id), 'request' => ['amount' => Money::of($amount), 'currency' => 'CNY', 'gateway' => $gateway]];
    }

    /** Immutable invoice validation; current enablement and amount limits are creation policy only. */
    public function isValid(Invoice $invoice): bool
    {
        $items = $invoice->line_items;
        $request = $items['wallet_topup']['request'] ?? null;
        $amount = $request['amount'] ?? null;
        if ($invoice->type !== InvoiceType::WALLET_TOPUP || ! is_array($request) || ! is_string($amount)
            || ! preg_match('/^\d{1,10}\.\d{2}$/D', $amount) || Money::isZero($amount)
            || ($items['wallet_topup']['version'] ?? null) !== 1 || $invoice->currency !== 'CNY'
            || Arr::sortRecursive($request) !== Arr::sortRecursive(['amount' => $amount, 'currency' => 'CNY', 'gateway' => $invoice->gateway])
            || ! in_array($invoice->gateway, ['epay_wechat', 'epay_alipay'], true)
            || ! is_string($invoice->topup_request_id) || ! Str::isUlid($invoice->topup_request_id)) {
            return false;
        }
        foreach (['product_id', 'instance_id', 'coupon_id', 'billing_period_key', 'purchase_request_id', 'renewal_root_id', 'replaces_invoice_id'] as $key) {
            if ($invoice->getAttribute($key) !== null) {
                return false;
            }
        }

        return Money::eq($invoice->total_amount, $amount) && Money::eq($invoice->gateway_amount, $amount)
            && Money::isZero($invoice->discount_amount) && Money::isZero($invoice->credit_applied)
            && ($items['subtotal'] ?? null) === $amount && ($items['discount'] ?? null) === '0.00'
            && ($items['total'] ?? null) === $amount && is_array($items['items'] ?? null)
            && Arr::sortRecursive($items['items']) === Arr::sortRecursive($this->lines($amount));
    }

    public function lines(string $amount): array
    {
        return [['kind' => 'wallet_topup', 'ref_id' => null, 'label' => '钱包充值', 'qty' => 1,
            'unit_price' => $amount, 'amount' => $amount, 'cycle' => null]];
    }
}
