<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\RecordAffiliateReceivableSettlementAction;
use App\Actions\ReverseAffiliateReceivableSettlementAction;
use App\Models\AffiliateReceivableSettlement;
use App\Models\FinancialTransaction;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AffiliateReceivableController extends Controller
{
    public function store(Request $request, FinancialTransaction $clawback, RecordAffiliateReceivableSettlementAction $action): RedirectResponse
    {
        $entry = $action->execute($clawback, $this->actor($request), $request->all());

        return redirect('/admin/finance/receivables?clawback='.$entry->clawback_transaction_id)->with('inventory_status', '已登记外部收款；原始应收及历史流水保持不变。');
    }

    public function reverse(Request $request, AffiliateReceivableSettlement $settlement, ReverseAffiliateReceivableSettlementAction $action): RedirectResponse
    {
        $entry = $action->execute($settlement, $this->actor($request), $request->all());

        return redirect('/admin/finance/receivables?clawback='.$entry->clawback_transaction_id)->with('inventory_status', '清偿登记已完整冲正，剩余应收已恢复；本操作不会发起外部退款。');
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize('finance.view');
        Gate::forUser($actor)->authorize('finance.receivable');

        return $actor;
    }
}
