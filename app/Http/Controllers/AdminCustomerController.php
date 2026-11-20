<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\RequestCustomerPasswordResetAction;
use App\Actions\SetCustomerAccessAction;
use App\Actions\UpdateCustomerProfileAction;
use App\Models\User;
use App\Support\AdminWorkspaceNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AdminCustomerController extends Controller
{
    public function profile(Request $request, User $customer, UpdateCustomerProfileAction $action): RedirectResponse
    {
        $actor = $this->actor($request, 'customers.manage');
        $query = AdminWorkspaceNavigation::query($actor, 'customer', $request->query());
        $request->merge(['_admin_form' => 'customer-profile', 'customer_id' => $customer->id]);
        try {
            $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255'],
                'phone' => ['nullable', 'string', 'max:32'], 'reason' => ['nullable', 'string', 'max:255']]);
            $action->execute($customer, $actor, $data);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(AdminWorkspaceNavigation::url('customer', $customer->id, $query, $query === [] ? null : 'customer-profile'));
        }

        return redirect(AdminWorkspaceNavigation::url('customer', $customer->id, $query, 'customer-profile'))
            ->with('customer_status', '客户资料已保存；联系方式实际变更后，客户须重新登录并验证新的联系方式。');
    }

    public function passwordReset(Request $request, User $customer, RequestCustomerPasswordResetAction $action): RedirectResponse
    {
        $actor = $this->actor($request, 'customers.password-reset');
        $query = AdminWorkspaceNavigation::query($actor, 'customer', $request->query());
        $request->merge(['_admin_form' => 'customer-password-reset', 'customer_id' => $customer->id]);
        try {
            $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
            $result = $action->execute($customer, $actor, $data['reason'] ?? null);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(AdminWorkspaceNavigation::url('customer', $customer->id, $query, $query === [] ? null : 'customer-profile'));
        }
        $message = match ($result) {
            'sent' => '重置邮件已由通道受理，请客户检查登记邮箱；此操作不会直接修改密码。',
            'throttled' => '近期已提交过重置请求，请先检查登记邮箱；稍后再操作。',
            'uncertain' => '重置邮件发送结果尚未确认，请先核对收件箱；系统不会自动重发。',
            default => '重置邮件未获通道确认，请核对邮件配置后再操作；本次不会自动重发。',
        };

        return redirect(AdminWorkspaceNavigation::url('customer', $customer->id, $query, 'customer-profile'))->with('customer_status', $message);
    }

    public function access(Request $request, User $customer, SetCustomerAccessAction $action): RedirectResponse
    {
        $actor = $this->actor($request, 'customers.status.manage');
        $query = AdminWorkspaceNavigation::query($actor, 'customer', $request->query());
        $request->merge(['_admin_form' => 'customer-access', 'customer_id' => $customer->id]);
        try {
            $data = $request->validate(['disabled' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:255']]);
            $disabled = $request->boolean('disabled');
            $action->execute($customer, $actor, $disabled, $data['reason'] ?? null);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(AdminWorkspaceNavigation::url('customer', $customer->id, $query, $query === [] ? null : 'customer-profile'));
        }

        return redirect(AdminWorkspaceNavigation::url('customer', $customer->id, $query, 'customer-profile'))->with('customer_status', $disabled
            ? '账户访问已停用，旧会话已撤销；已有账单和服务继续按原流程处理。'
            : '账户访问已恢复，客户须重新登录；旧会话与旧恢复凭据不会恢复。');
    }

    private function actor(Request $request, string $ability): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->isAccessEnabled(), 403);
        Gate::forUser($actor)->authorize('customers.view');
        Gate::forUser($actor)->authorize($ability);

        return $actor;
    }
}
