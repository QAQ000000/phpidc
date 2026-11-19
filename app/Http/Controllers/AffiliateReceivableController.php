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
        $input = $request->all();
        if ((! isset($input['external_channel']) || $input['external_channel'] === '') && isset($input['custom_external_channel'])) {
            $input['external_channel'] = $input['custom_external_channel'];
        }
        if (! isset($input['reason']) || (is_string($input['reason']) && trim($input['reason']) === '')) {
            $input['reason'] = isset($input['corrects_settlement_id']) ? '登记更正后的外部应收收款' : '登记已核实收到的外部应收款';
        }
        $entry = $action->execute($clawback, $this->actor($request), $input);

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
