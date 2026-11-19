<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AssignAdminRoleAction;
use App\Actions\RefundInvoiceAction;
use App\Actions\RetryFulfillmentTaskAction;
use App\Actions\UpdateAdminRolesAction;
use App\Models\FulfillmentTask;
use App\Models\Invoice;
use App\Models\User;
use App\Support\AdminRoles;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminOperationsController extends Controller
{
    public function role(Request $request, User $target, AssignAdminRoleAction $assign): RedirectResponse
    {
        $actor = $this->actor($request, 'access.view', 'access.manage');
        $data = $request->validate(['role' => ['required', Rule::in(AdminRoles::webAssignable())],
            'operation' => ['required', Rule::in(['grant', 'revoke'])], 'reason' => ['nullable', 'string', 'max:255'], 'confirm' => ['required', 'accepted']]);
        $reason = trim($data['reason'] ?? '');
        if ($reason === '') {
            $reason = ($data['operation'] === 'grant' ? '授予后台角色：' : '撤销后台角色：').AdminRoles::labels()[$data['role']];
        }
        try {
            $assign->execute($target, $data['role'], $reason, $data['operation'] === 'revoke', $actor);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['operation' => $e->getMessage()]);
        }

        return redirect('/admin/access?user_id='.$target->id)->with('inventory_status', '用户 #'.$target->id.' 的角色已更新并记录审计。');
    }

    public function roles(Request $request, User $target, UpdateAdminRolesAction $update): RedirectResponse
    {
        $actor = $this->actor($request, 'access.view', 'access.manage');
        $data = $request->validate(['roles' => ['sometimes', 'array'], 'expected_roles' => ['sometimes', 'array'],
            'reason' => ['nullable', 'string', 'max:255'], 'confirm' => ['required', 'accepted']]);
        try {
            $update->execute($target, $data['roles'] ?? [], $data['expected_roles'] ?? [], $actor, $data['reason'] ?? null);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['roles' => $e->getMessage()]);
        }

        return redirect('/admin/access?user_id='.$target->id)->with('inventory_status', '用户 #'.$target->id.' 的角色已保存，变更均已记录审计。');
    }

    public function refund(Request $request, Invoice $invoice, RefundInvoiceAction $refund): RedirectResponse
    {
        $actor = $this->actor($request, 'finance.view', 'finance.refund');
        Gate::forUser($actor)->authorize('refund-invoice', $invoice);
        $data = $request->validate(['mode' => ['required', Rule::in(['refund_wallet', 'external_record'])],
            'reason' => ['required_if:keep_service,1', 'nullable', 'string', 'max:255'], 'confirm' => ['required', 'accepted'],
            'keep_service' => ['sometimes', 'accepted'], 'external_confirm' => ['accepted_if:mode,external_record']]);
        if ($request->boolean('keep_service') && trim($data['reason'] ?? '') === '') {
            throw ValidationException::withMessages(['reason' => '保留服务的退款豁免必须填写实际原因。']);
        }
        $reason = trim($data['reason'] ?? '');
        if ($reason === '') {
            $reason = $data['mode'] === 'refund_wallet' ? '按账单全额退款至客户钱包' : '登记已完成的外部全额退款';
        }
        try {
            $refund->execute($invoice, $data['mode'], $request->boolean('keep_service'), $reason, $actor);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['operation' => $e->getMessage()]);
        }

        return redirect('/admin/finance/invoice?invoice_id='.$invoice->id)->with('inventory_status', '全额退款已入账；重复提交不会重复退款。外部登记不会发起打款。');
    }

    public function retry(Request $request, FulfillmentTask $task, RetryFulfillmentTaskAction $retry): RedirectResponse
    {
        $actor = $this->actor($request, 'tasks.view');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255'], 'confirm' => ['required', 'accepted']]);
        try {
            $retry->execute($task, $data['reason'], $actor);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['operation' => $e->getMessage()]);
        }

        return redirect('/admin/tasks?task_id='.$task->id)->with('inventory_status', '已确认重试原任务，等待队列处理；远程意图仍需按原有规则核对。');
    }

    private function actor(Request $request, string ...$abilities): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        foreach ($abilities as $ability) {
            Gate::forUser($actor)->authorize($ability);
        }

        return $actor;
    }
}
