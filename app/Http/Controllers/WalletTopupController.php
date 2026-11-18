<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ConfigureWalletTopupAction;
use App\Actions\CreateWalletTopupInvoiceAction;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class WalletTopupController extends Controller
{
    public function index(Request $request, ConfigureWalletTopupAction $configuration): Response
    {
        $user = $this->user($request)->fresh();
        abort_if($user === null || $user->anonymized_at !== null, 403);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return response()->view('sales.wallet', ['user' => $user, 'enabled' => $configuration->enabled(),
            'requestId' => (string) Str::ulid(),
            'invoices' => Invoice::where('user_id', $user->id)->where('type', InvoiceType::WALLET_TOPUP)->latest('id')
                ->paginate(20, ['id', 'invoice_no', 'product_id', 'type', 'status', 'total_amount', 'currency', 'created_at'])->withQueryString()])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, CreateWalletTopupInvoiceAction $action): JsonResponse|RedirectResponse
    {
        $invoice = $action->execute($this->user($request), $request->only(['request_id', 'amount', 'gateway']));
        if ($request->expectsJson()) {
            return response()->json(['invoice_id' => $invoice->id, 'invoice_no' => $invoice->invoice_no,
                'total_amount' => $invoice->total_amount, 'currency' => $invoice->currency, 'status' => $invoice->status->value], 201)
                ->header('Cache-Control', 'private, no-store');
        }

        return redirect('/console/invoices/'.$invoice->id)->header('Cache-Control', 'private, no-store');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && ! $user->trashed() && $user->anonymized_at === null, 401);

        return $user;
    }
}
