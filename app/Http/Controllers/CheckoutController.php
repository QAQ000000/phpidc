<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateComboInvoiceAction;
use App\Actions\ValidateCheckoutAction;
use App\Enums\BillingCycle;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CheckoutController extends Controller
{
    public function store(Request $request, ValidateCheckoutAction $validateCheckout, CreateComboInvoiceAction $create): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $input = $request->validate(['product_id' => ['required', 'integer']]);
        $validateCheckout->execute($user, (int) $input['product_id'], $request->only(['hostname', 'password']));

        $data = $request->validate([
            'billing_cycle' => ['sometimes', Rule::enum(BillingCycle::class)],
            'use_credit' => ['sometimes', 'boolean'], 'gateway' => ['nullable', Rule::in(['epay_wechat', 'epay_alipay'])],
            'options' => ['sometimes', 'array'],
        ]);
        $invoice = $create->execute($user, Product::findOrFail($input['product_id']),
            BillingCycle::from($data['billing_cycle'] ?? BillingCycle::ONETIME->value), $data['options'] ?? [],
            $request->boolean('use_credit'), $data['gateway'] ?? null, $request->only(['hostname', 'password']));
        if ($request->expectsJson()) {
            return response()->json(['invoice_id' => $invoice->id, 'status' => $invoice->status->value,
                'total_amount' => $invoice->total_amount, 'credit_applied' => $invoice->credit_applied, 'gateway_amount' => $invoice->gateway_amount,
                'invoice_url' => url('/console/invoices/'.$invoice->id)], 201);
        }

        return redirect('/console/invoices/'.$invoice->id);
    }
}
