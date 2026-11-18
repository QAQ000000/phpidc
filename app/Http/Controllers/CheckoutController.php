<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateComboInvoiceAction;
use App\Actions\DescribeStoreProductAction;
use App\Actions\FindPurchaseRequestAction;
use App\Actions\ValidateCheckoutAction;
use App\Enums\BillingCycle;
use App\Exceptions\PurchasePriceChanged;
use App\Models\Product;
use App\Models\User;
use App\Support\PurchaseRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class CheckoutController extends Controller
{
    public function store(Request $request, ValidateCheckoutAction $validateCheckout, CreateComboInvoiceAction $create): JsonResponse|RedirectResponse|Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $data = $request->validate([
            'product_id' => ['required', 'integer', 'min:1'], 'request_id' => ['required', 'ulid'],
            'hostname' => ['nullable', 'string'], 'password' => ['nullable', 'string'],
            'billing_cycle' => ['sometimes', Rule::enum(BillingCycle::class)],
            'use_credit' => ['sometimes', 'boolean'], 'gateway' => ['nullable', Rule::in(['epay_wechat', 'epay_alipay'])],
            'options' => ['sometimes', 'array'], 'coupon_code' => ['nullable', 'string', 'max:64'],
            'quote_token' => ['nullable', 'string', 'max:16384'],
        ]);
        $cycle = BillingCycle::from($data['billing_cycle'] ?? BillingCycle::ONETIME->value);
        $intent = app(PurchaseRequest::class);
        $requestId = $intent->id($data);
        $snapshot = $intent->snapshot((int) $data['product_id'], $cycle, $data['options'] ?? [], $request->boolean('use_credit'), $data['gateway'] ?? null, $data);
        $invoice = app(FindPurchaseRequestAction::class)->execute($user->id, $requestId, $snapshot);
        if ($invoice === null) {
            $validateCheckout->execute($user, (int) $data['product_id'], $request->only(['hostname', 'password']));
            $product = Product::findOrFail($data['product_id']);
            try {
                $invoice = $create->execute($user, $product, $cycle, $data['options'] ?? [],
                    $request->boolean('use_credit'), $data['gateway'] ?? null, $data + ['require_price_confirmation' => true]);
            } catch (PurchasePriceChanged $changed) {
                if ($request->expectsJson()) {
                    return response()->json(['price_changed' => true, 'message' => $changed->getMessage(), 'quote' => [
                        'price' => $changed->quote->downstreamPrice, 'setup_fee' => $changed->quote->setupFee,
                        'currency' => $changed->quote->currency, 'quote_token' => $changed->quoteToken,
                    ]], 409)->header('Cache-Control', 'private, no-store');
                }

                return response()->view('sales.configure', ['product' => $product,
                    'details' => app(DescribeStoreProductAction::class)->execute($product), 'quote' => $changed->quote,
                    'quoteToken' => $changed->quoteToken, 'confirmationRequired' => true,
                    'selection' => ['billing_cycle' => $cycle->value, 'options' => ['configoption' => $data['options']['configoption'] ?? []]],
                    'checkoutInput' => array_intersect_key($data, array_flip(['hostname', 'coupon_code', 'use_credit', 'gateway'])),
                    'purchaseRequestId' => $requestId,
                ], 409)->header('Cache-Control', 'private, no-store');
            }
        }
        if ($request->expectsJson()) {
            return response()->json(['invoice_id' => $invoice->id, 'status' => $invoice->status->value,
                'total_amount' => $invoice->total_amount, 'credit_applied' => $invoice->credit_applied, 'gateway_amount' => $invoice->gateway_amount,
                'invoice_url' => url('/console/invoices/'.$invoice->id)], 201)->header('Cache-Control', 'private, no-store');
        }

        return redirect('/console/invoices/'.$invoice->id);
    }
}
