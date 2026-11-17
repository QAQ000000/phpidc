<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\RegisterCustomerAction;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CustomerAuthController extends Controller
{
    public function form(): View
    {
        return view('sales.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        if (! Auth::attempt($data)) {
            throw ValidationException::withMessages(['email' => '邮箱或密码错误。']);
        }
        $request->session()->regenerate();

        return redirect('/console');
    }

    public function register(Request $request, RegisterCustomerAction $register): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed']]);
        Auth::login($register->execute($data['name'], $data['email'], $data['password']));
        $request->session()->regenerate();

        return redirect('/console');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
