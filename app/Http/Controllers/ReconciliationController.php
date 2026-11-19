<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ReadReconciliationEvidenceAction;
use App\Actions\ReconcileBindingOrphanAction;
use App\Actions\ReconcilePaymentCallbackAction;
use App\Actions\ReopenPaymentAttentionCaseAction;
use App\Models\ReconciliationCase;
use App\Models\User;
use Illuminate\Http\Request;

class ReconciliationController extends Controller
{
    public function payment(Request $request, ReconciliationCase $case, ReconcilePaymentCallbackAction $action)
    {
        $action->execute($case, $this->actor($request), $request->all());

        return $this->back($case);
    }

    public function binding(Request $request, ReconciliationCase $case, ReconcileBindingOrphanAction $action)
    {
        $action->execute($case, $this->actor($request), $request->all());

        return $this->back($case);
    }

    public function reopen(Request $request, ReconciliationCase $case)
    {
        if ($case->kind === 'payment_attention') {
            app(ReopenPaymentAttentionCaseAction::class)->execute($case, $this->actor($request), $request->all());
        } else {
            abort_unless($case->kind === 'binding_orphan', 404);
            app(ReconcileBindingOrphanAction::class)->execute($case, $this->actor($request), [...$request->all(), 'operation' => 'reopen']);
        }

        return $this->back($case);
    }

    public function evidence(Request $request, ReconciliationCase $case, ReadReconciliationEvidenceAction $action)
    {
        return response()->view('sales.reconciliation-evidence', $action->execute($case, $this->actor($request), '查看原笔核验证据'))
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function back(ReconciliationCase $case)
    {
        return redirect('/admin/reconciliations?case='.$case->id)->with('inventory_status', '原记录核对结果已追加保存。');
    }
}
