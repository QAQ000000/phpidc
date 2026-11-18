<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CloseCustomerAccountAction;
use App\Models\User;
use App\Services\AccountClosureEligibility;
use App\Services\ReceivableAdmission;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerAccountClosureController extends Controller
{
    public function show(Request $request, AccountClosureEligibility $eligibility): View
    {
        $user = $this->user($request)->fresh();
        abort_unless($user !== null, 401);

        $check = null;
        try {
            $permit = app(ReceivableAdmission::class)->prepare($user, 'account-close-preview', 'preview', [], false, true);
            $result = $eligibility->forUser($user, $permit);
        } catch (ValidationException $error) {
            $check = $error->errors();
            $result = ['eligible' => false, 'reasons' => ['receivables' => '应收历史待完整核对，完成后刷新此页。']];
        }

        return view('sales.account-close', ['eligibility' => $result, 'receivableCheck' => $check, 'requestId' => old('request_id', (string) Str::ulid())]);
    }

    public function store(Request $request, CloseCustomerAccountAction $action): RedirectResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string', 'max:255'], 'confirm' => ['required', 'accepted'], 'request_id' => ['nullable', 'ulid']]);
        $action->execute($this->user($request), $data['current_password'], $request->boolean('confirm'), $data['request_id'] ?? null);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login')->with('status', '账户已注销，认证及联系资料已匿名化。历史财务、服务和审计证据按约定保留。');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
