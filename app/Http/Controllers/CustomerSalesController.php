<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\DescribeRenewalRecoveryAction;
use App\Actions\InitiatePaymentAction;
use App\Actions\OpenInstancePanelAction;
use App\Actions\ReadInstanceControlsAction;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Services\CustomerPortalQueries;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

        $products = Product::publicCatalog()->select(['id', 'name', 'category_id', 'slug', 'currency', 'driver_type',
            'local_prices->onetime as console_price', 'provisioning_rules->hostname_mode as console_hostname_mode',
            'provisioning_rules->password_min_len as console_password_min_len', 'provisioning_rules->password_max_len as console_password_max_len',
            'provisioning_rules->password_regex as console_password_regex'])->orderBy('id')->limit(6)->get();
        foreach ($products as $product) {
            $product->local_prices = ['onetime' => json_decode($product->getAttribute('console_price') ?? '{}', true, flags: JSON_THROW_ON_ERROR)];
            $rules = [];
            foreach (['hostname_mode', 'password_min_len', 'password_max_len', 'password_regex'] as $key) {
                if ($product->getAttribute('console_'.$key) !== null) {
                    $rules[$key] = $product->getAttribute('console_'.$key);
                }
            }
            $product->provisioning_rules = $rules;
        }

        return view('sales.console', ['user' => $user, 'products' => $products,
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
        return view('sales.invoice', $queries->invoiceDetails($this->user($request), $invoice) + ['recoveryInfo' => app(DescribeRenewalRecoveryAction::class)->execute($invoice)]);
    }

    public function instance(Request $request, Instance $instance, CustomerPortalQueries $queries, OpenInstancePanelAction $panel): Response
    {
        $details = $queries->instanceDetails($this->user($request), $instance);
        $root = $instance->next_due_at === null ? null : Invoice::where('user_id', $instance->user_id)->where('instance_id', $instance->id)
            ->where('billing_period_key', $instance->id.':renew:'.$instance->next_due_at->copy()->utc()->format('Y-m-d\TH:i:s\Z'))->first();

        return response()->view('sales.instance', [...$details,
            'renewalRecoveryInfo' => $root === null ? null : app(DescribeRenewalRecoveryAction::class)->execute($root),
            'canOpenPanel' => $panel->available($this->user($request), $instance),
            'canControlMachine' => app(ReadInstanceControlsAction::class)->available($this->user($request), $instance)])->header('Cache-Control', 'private, no-store');
    }

    public function pay(Request $request, Invoice $invoice, InitiatePaymentAction $pay): JsonResponse|RedirectResponse|View
    {
        try {
            $result = $pay->execute($invoice, $this->user($request));
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }

            // No-referrer pages cannot rely on a session URL last visited by a browser probe.
            return redirect('/console/invoices/'.$invoice->id)->withErrors($exception->errors());
        }
        if ($request->expectsJson()) {
            return response()->json(['success' => $result->success, 'payment_url' => $result->paymentUrl,
                'qr_code_url' => $result->qrCodeUrl, 'out_trade_no' => $result->outTradeNo, 'error' => $result->errorMessage], $result->success ? 200 : 502);
        }

        return view('sales.payment', ['invoice' => $invoice, 'result' => $result]);
    }
}
