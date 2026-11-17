<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CustomerIdentityAction;
use App\Actions\PhoneBindingAction;
use App\Actions\SocialLoginAction;
use App\Actions\TelegramNotificationBindingAction;
use App\Models\KycVerification;
use App\Models\User;
use App\Models\UserNotificationBinding;
use App\Models\UserOauthAccount;
use App\Services\DriverManager;
use App\Support\CustomerDestination;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\RedirectResponse as BaseRedirectResponse;

class CustomerIdentityController extends Controller
{
    public function account(Request $request, CustomerIdentityAction $identity, PhoneBindingAction $phone): View
    {
        $user = $this->user($request);

        return view('sales.account', ['user' => $user, 'mailReady' => $identity->mailReady(), 'phoneReady' => $phone->available(),
            'phoneVerified' => $user->phone !== null && UserNotificationBinding::where('user_id', $user->id)->where('channel', 'sms')->where('target', $user->phone)->whereNotNull('verified_at')->exists(),
            'providers' => app(DriverManager::class)->socialProviders(), 'bindings' => UserOauthAccount::where('user_id', $user->id)->get(['id', 'provider']),
            'verification' => KycVerification::where('user_id', $user->id)->latest('id')->first(), 'kycReady' => app(DriverManager::class)->kyc()->ready(),
            'telegramNotifications' => UserNotificationBinding::where('user_id', $user->id)->where('channel', 'telegram')->whereNotNull('verified_at')->exists()]);
    }

    public function forgot(CustomerIdentityAction $identity): View
    {
        return view('sales.forgot-password', ['mailReady' => $identity->mailReady()]);
    }

    public function sendReset(Request $request, CustomerIdentityAction $identity): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $identity->requestPasswordReset($data['email']);

        return back()->with('status', '如果此邮箱关联可用账户，我们会发送重置链接；请检查收件箱。');
    }

    public function resetForm(Request $request, string $token): View
    {
        return view('sales.reset-password', ['token' => $token, 'email' => (string) $request->query('email')]);
    }

    public function reset(Request $request, CustomerIdentityAction $identity): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed']]);
        $identity->resetPassword($data['email'], $data['token'], $data['password']);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login')->with('status', '密码已重置，请重新登录。');
    }

    public function sendVerification(Request $request, CustomerIdentityAction $identity): RedirectResponse
    {
        $identity->sendEmailVerification($this->user($request));

        return back()->with('status', '验证邮件已提交发送，请检查收件箱。');
    }

    public function verify(Request $request, int $id, string $hash, CustomerIdentityAction $identity): RedirectResponse
    {
        $identity->verifyEmail($this->user($request), $id, $hash);

        return redirect('/console/account')->with('status', '邮箱已验证。');
    }

    public function socialBegin(Request $request, string $provider, SocialLoginAction $social): BaseRedirectResponse
    {
        try {
            return $social->begin($request, $provider, $request->boolean('link'));
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['operation' => $e->getMessage()]);
        }
    }

    public function socialCallback(Request $request, string $provider, SocialLoginAction $social): RedirectResponse
    {
        $linking = $request->user() !== null;
        try {
            $user = $social->complete($request, $provider);
        } catch (DomainException $e) {
            return redirect($linking ? '/console/account' : '/login')->withErrors(['operation' => $e->getMessage()]);
        }
        Auth::login($user);
        $request->session()->regenerate();

        $destination = ! $linking && $request->session()->has('url.intended') ? CustomerDestination::pull($request) : '/console/account';

        return redirect($destination)->with('status', '第三方授权已完成。');
    }

    public function telegramWidget(Request $request, SocialLoginAction $social): View|RedirectResponse
    {
        try {
            return view('sales.telegram-login', $social->telegramWidget($request));
        } catch (DomainException $e) {
            return redirect($request->user() === null ? '/login' : '/console/account')->withErrors(['operation' => $e->getMessage()]);
        }
    }

    public function telegramNotifications(Request $request, TelegramNotificationBindingAction $action): RedirectResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string'], 'enabled' => ['required', 'boolean']]);
        $action->execute($this->user($request), $data['current_password'], $request->boolean('enabled'));

        return back()->with('status', $request->boolean('enabled') ? 'Telegram 通知已开启；请在机器人对话中点击开始并允许消息。' : 'Telegram 通知已关闭。');
    }

    public function unlink(Request $request, string $provider, SocialLoginAction $social): RedirectResponse
    {
        try {
            $social->unlink($this->user($request), $provider, (string) $request->input('current_password'));
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['operation' => $e->getMessage()]);
        }

        return back()->with('status', '第三方账户已解绑。');
    }

    public function sendPhone(Request $request, PhoneBindingAction $phone): RedirectResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:32']]);
        $phone->send($this->user($request), $data['phone']);

        return back()->with('status', '验证码已发送，10 分钟内有效。');
    }

    public function confirmPhone(Request $request, PhoneBindingAction $phone): RedirectResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:32'], 'code' => ['required', 'regex:/^[0-9]{6}$/D']]);
        $phone->confirm($this->user($request), $data['phone'], $data['code']);

        return back()->with('status', '手机号已验证并绑定。');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
