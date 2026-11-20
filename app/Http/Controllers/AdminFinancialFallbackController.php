<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AdjustCustomerWalletAction;
use App\Actions\ReadOfflineInvoiceReceiptDetailsAction;
use App\Actions\RecordOfflineInvoiceReceiptAction;
use App\Models\Invoice;
use App\Models\OfflineInvoiceReceipt;
use App\Models\User;
use App\Support\AdminWorkspaceNavigation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AdminFinancialFallbackController extends Controller
{
    public function wallet(Request $request, User $customer, AdjustCustomerWalletAction $action): RedirectResponse
    {
        $actor = $this->actor($request, 'finance.wallet-adjust');
        Gate::forUser($actor)->authorize('customers.view');
        $query = AdminWorkspaceNavigation::query($actor, 'customer', $request->query());
        $request->merge(['_admin_form' => 'wallet-adjust', 'customer_id' => $customer->id]);
        try {
            $action->execute($customer, $actor, $request->only(['request_id', 'direction', 'amount', 'currency', 'reason', 'confirm']));
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(AdminWorkspaceNavigation::url('customer', $customer->id, $query, $query === [] ? null : 'customer-profile'));
        }

        return redirect(AdminWorkspaceNavigation::url('customer', $customer->id, $query, 'customer-profile'))->with('customer_status', '钱包调整已登记，账单冻结及其他资金保留原记录。');
    }

    public function offline(Request $request, Invoice $invoice, RecordOfflineInvoiceReceiptAction $action): RedirectResponse
    {
        $actor = $this->actor($request, 'finance.offline-receipt');
        $returnUrl = AdminWorkspaceNavigation::url('invoice', $invoice->id,
            AdminWorkspaceNavigation::query($actor, 'invoice', $request->query()));
        $request->merge(['_admin_form' => 'offline-receipt', 'invoice_id' => $invoice->id]);
        try {
            $action->execute($invoice, $actor, $request->only(['request_id', 'amount', 'currency', 'external_channel',
                'external_reference', 'received_at', 'reason', 'evidence', 'confirm']));
        } catch (ValidationException $exception) {
            throw $exception->redirectTo($returnUrl);
        }

        return redirect($returnUrl)->with('status', '线下收款已登记，原账单结清并进入原履约流程。');
    }

    public function details(Request $request, OfflineInvoiceReceipt $receipt, ReadOfflineInvoiceReceiptDetailsAction $action): JsonResponse
    {
        $actor = $this->actor($request, 'finance.offline-receipt');

        return response()->json($action->execute($receipt, $actor))->header('Cache-Control', 'no-store, private');
    }

    private function actor(Request $request, string $ability): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->isAccessEnabled(), 403);
        Gate::forUser($actor)->authorize('finance.view');
        Gate::forUser($actor)->authorize($ability);

        return $actor;
    }
}
