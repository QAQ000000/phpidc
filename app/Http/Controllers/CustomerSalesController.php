<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\InitiatePaymentAction;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Services\CustomerPortalQueries;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class CustomerSalesController extends Controller
{
    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    public function index(Request $request, CustomerPortalQueries $queries): View
    {
        $user = $this->user($request);

        return view('sales.console', ['user' => $user, 'products' => Product::publicCatalog()->with('category')->orderBy('id')->get(),
            ...$queries->dashboard($user)]);
    }

    public function invoices(Request $request, CustomerPortalQueries $queries): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::enum(InvoiceStatus::class)], 'page' => ['sometimes', 'integer', 'min:1']]);

        return view('sales.invoices', ['invoices' => $queries->invoiceList($this->user($request), $filters)]);
    }

    public function instances(Request $request, CustomerPortalQueries $queries): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::enum(InstanceStatus::class)], 'page' => ['sometimes', 'integer', 'min:1']]);

        return view('sales.instances', ['instances' => $queries->instanceList($this->user($request), $filters)]);
    }

    public function invoice(Request $request, Invoice $invoice, CustomerPortalQueries $queries): View
    {
        return view('sales.invoice', $queries->invoiceDetails($this->user($request), $invoice));
    }

    public function instance(Request $request, Instance $instance, CustomerPortalQueries $queries): Response
    {
        return response()->view('sales.instance', $queries->instanceDetails($this->user($request), $instance))->header('Cache-Control', 'private, no-store');
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
