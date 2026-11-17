<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\InitiatePaymentAction;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerSalesController extends Controller
{
    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    public function index(Request $request): View
    {
        $user = $this->user($request);

        return view('sales.console', ['user' => $user,
            'products' => Product::where('is_hidden', false)->orderBy('id')->get(),
            'invoices' => Invoice::where('user_id', $user->id)->latest('id')->limit(50)->get(),
            'instances' => Instance::where('user_id', $user->id)->latest('id')->limit(50)->get()]);
    }

    public function invoice(Request $request, Invoice $invoice): View
    {
        abort_unless($invoice->user_id === $this->user($request)->id, 403);

        return view('sales.invoice', ['invoice' => $invoice]);
    }

    public function instance(Request $request, Instance $instance): View
    {
        abort_unless($instance->user_id === $this->user($request)->id, 403);

        return view('sales.instance', ['instance' => $instance]);
    }

    public function pay(Request $request, Invoice $invoice, InitiatePaymentAction $pay): JsonResponse|View
    {
        $result = $pay->execute($invoice, $this->user($request));
        if ($request->expectsJson()) {
            return response()->json(['success' => $result->success, 'payment_url' => $result->paymentUrl,
                'qr_code_url' => $result->qrCodeUrl, 'out_trade_no' => $result->outTradeNo, 'error' => $result->errorMessage], $result->success ? 200 : 502);
        }

        return view('sales.payment', ['invoice' => $invoice, 'result' => $result]);
    }
}
