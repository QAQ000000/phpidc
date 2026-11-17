<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AuthenticateCustomerAction;
use App\Actions\RegisterCustomerAction;
use App\Services\DriverManager;
use App\Support\CustomerDestination;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CustomerAuthController extends Controller
{
    public function form(): View
    {
        return view('sales.login', ['providers' => app(DriverManager::class)->socialProviders()]);
    }

    public function registrationForm(): View
    {
        return view('sales.register');
    }

    public function login(Request $request, AuthenticateCustomerAction $authenticate): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'max:255'], 'remember' => ['sometimes', 'boolean']]);
        $authenticate->execute($data['email'], $data['password'], $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect(CustomerDestination::pull($request));
    }

    public function register(Request $request, RegisterCustomerAction $register): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed']]);
        Auth::login($register->execute($data['name'], $data['email'], $data['password'], $request->session()->pull('affiliate.referrer_id')));
        $request->session()->regenerate();

        return redirect(CustomerDestination::pull($request))->with('status', '注册成功，请在账户安全页验证邮箱。');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
