<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\InitiateKycAction;
use App\Actions\QueryKycAction;
use App\Models\KycVerification;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CustomerKycController extends Controller
{
    public function initiate(Request $request, InitiateKycAction $action): RedirectResponse
    {
        try {
            $action->execute($this->user($request), $request->only(['real_name', 'id_number', 'consent', 'current_password']));
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['operation' => $e->getMessage()]);
        }

        return redirect('/console/account')->with('status', '实名申请已创建，请继续前往认证；认证后需主动查询确认。');
    }

    public function authorization(Request $request, KycVerification $verification, QueryKycAction $action): View
    {
        try {
            $result = $action->authorization($this->user($request), $verification);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['operation' => $e->getMessage()]);
        }

        return view('sales.kyc-authorization', ['verification' => $verification, 'authorizationUrl' => $result->redirectUrl]);
    }

    public function refresh(Request $request, KycVerification $verification, QueryKycAction $action): RedirectResponse
    {
        try {
            $record = $action->refresh($this->user($request), $verification);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['operation' => $e->getMessage()]);
        }

        return redirect('/console/account')->with('status', $record->status === 'verified' ? '实名认证已通过。' : '尚未确认认证通过，请完成认证后稍后查询，或联系管理员核对。');
    }

    public function returned(): RedirectResponse
    {
        return redirect('/console/account')->with('status', '已从认证页面返回，请主动查询确认结果。');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
