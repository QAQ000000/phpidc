<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateComboInvoiceAction;
use App\Actions\QuoteRenewalRecoveryAction;
use App\Models\Invoice;
use App\Models\User;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class RenewalRecoveryController extends Controller
{
    public function quote(Request $request, Invoice $invoice, QuoteRenewalRecoveryAction $quote)
    {
        $user = $request->user();
        abort_unless($user instanceof User && $invoice->user_id === $user->id, 403);
        $data = $this->choices($request);
        try {
            $result = $quote->execute($user, $invoice, $request->boolean('use_credit'), $data['gateway'] ?? null, $data['coupon_code'] ?? null);
        } catch (DomainException $error) {
            throw ValidationException::withMessages(['recovery' => $error->getMessage()]);
        }
        if ($request->expectsJson()) {
            return response()->json(array_intersect_key($result, array_flip(['quote_token', 'expires', 'payment_window_minutes', 'currency', 'subtotal', 'discount', 'total', 'credit', 'gateway_amount'])))->header('Cache-Control', 'private, no-store');
        }

        return response()->view('sales.renewal-recovery', ['invoice' => $invoice, 'quote' => $result,
            'requestId' => $data['request_id'] ?? (string) Str::ulid()])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Invoice $invoice, CreateComboInvoiceAction $create)
    {
        $user = $request->user();
        abort_unless($user instanceof User && $invoice->user_id === $user->id, 403);
        $data = $this->choices($request) + $request->validate(['request_id' => ['required', 'ulid'], 'quote_token' => ['required', 'string'],
            'reason' => ['required', 'string', 'max:1000'], 'confirm' => ['accepted']]);
        try {
            $result = $create->recoverRenewal($user, $invoice, $data['request_id'], $data['quote_token'], $request->boolean('use_credit'),
                $data['gateway'] ?? null, $data['coupon_code'] ?? null, $data['reason']);
        } catch (DomainException $error) {
            throw ValidationException::withMessages(['recovery' => $error->getMessage()]);
        }
        if ($request->expectsJson()) {
            return response()->json(['invoice_id' => $result->id, 'status' => $result->status->value,
                'invoice_url' => url('/console/invoices/'.$result->id)], 201)->header('Cache-Control', 'private, no-store');
        }

        return redirect('/console/invoices/'.$result->id);
    }

    private function choices(Request $request): array
    {
        return $request->validate(['request_id' => ['sometimes', 'ulid'], 'use_credit' => ['sometimes', 'boolean'],
            'gateway' => ['nullable', Rule::in(['epay_wechat', 'epay_alipay'])], 'coupon_code' => ['nullable', 'string', 'max:64']]);
    }
}
