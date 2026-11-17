<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Models\UserNotificationBinding;
use App\Models\UserOauthAccount;
use App\Services\DriverManager;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\RedirectResponse;

class SocialLoginAction
{
    public function begin(Request $request, string $provider, bool $link): RedirectResponse
    {
        $actor = $request->user();
        if ($link) {
            $this->confirmAccount($actor, (string) $request->input('current_password'));
        } elseif ($actor !== null) {
            throw new DomainException('请从账户安全页面发起绑定。');
        }
        $manager = app(DriverManager::class);
        if ($provider === 'telegram' && ! $manager->telegramLogin()->ready()) {
            throw new DomainException('Telegram 登录尚未配置或未启用。');
        }
        $driver = $provider === 'telegram' ? null : $manager->social($provider);
        $request->session()->put('social_intent', ['provider' => $provider, 'user_id' => $link ? $actor?->id : null,
            'password_hash' => $link ? $actor?->getAuthPassword() : null, 'expires' => now()->addMinutes(10)->timestamp]);
        if ($provider === 'telegram') {
            $state = Str::random(40);
            $request->session()->put('telegram_state', $state);

            return redirect('/auth/telegram/widget?'.http_build_query(['state' => $state]));
        }

        return $driver->redirect();
    }

    public function telegramWidget(Request $request): array
    {
        $intent = $request->session()->get('social_intent');
        $state = $request->session()->get('telegram_state');
        $input = $request->query('state');
        $driver = app(DriverManager::class)->telegramLogin();
        if (! $driver->ready() || ! is_string($state) || ! is_string($input) || ! hash_equals($state, $input)
            || ! is_array($intent) || $intent['provider'] !== 'telegram' || $intent['expires'] < now()->timestamp
            || $intent['user_id'] !== $request->user()?->id) {
            throw new DomainException('Telegram 授权会话无效，请重新发起。');
        }

        return ['botUsername' => $driver->username(), 'callbackUrl' => rtrim((string) config('app.url'), '/').'/auth/telegram/callback?'.http_build_query(['state' => $state])];
    }

    public function complete(Request $request, string $provider): User
    {
        $intent = $request->session()->pull('social_intent');
        if (! is_array($intent) || $intent['provider'] !== $provider || $intent['expires'] < now()->timestamp
            || $intent['user_id'] !== $request->user()?->id
            || ($intent['user_id'] !== null && ! hash_equals($intent['password_hash'], $request->user()->getAuthPassword()))) {
            throw new DomainException('第三方授权会话无效，请重新发起。');
        }
        if ($provider === 'telegram') {
            $state = $request->session()->pull('telegram_state');
            $input = $request->query('state');
            if (! is_string($state) || ! is_string($input) || ! hash_equals($state, $input)) {
                throw new DomainException('Telegram 授权会话无效，请重新发起。');
            }
            $subject = app(DriverManager::class)->telegramLogin()->subject($request);
            if (! Cache::add('telegram-login-proof:'.hash('sha256', (string) $request->query('hash')), true, 660)) {
                throw new DomainException('Telegram 授权已被使用，请重新发起。');
            }
        } else {
            $subject = app(DriverManager::class)->social($provider)->subject();
        }
        $linked = UserOauthAccount::where('provider', $provider)->where('provider_subject', $subject)->first();
        $userId = $intent['user_id'] ?? $linked?->user_id;
        if ($userId === null) {
            throw new DomainException('此第三方账户尚未绑定。请先注册或登录并验证邮箱，再从账户安全页绑定。');
        }

        try {
            return DB::transaction(function () use ($userId, $provider, $subject, $intent): User {
                $user = User::whereKey($userId)->lockForUpdate()->first();
                if ($user === null) {
                    throw new DomainException('账户不可用。');
                }
                $linked = UserOauthAccount::where('provider', $provider)->where('provider_subject', $subject)->lockForUpdate()->first();
                if ($intent['user_id'] !== null) {
                    if ($user->email_verified_at === null || ! hash_equals($intent['password_hash'], $user->getAuthPassword())) {
                        throw new DomainException('账户已改变，请重新登录并验证邮箱。');
                    }
                    if ($linked !== null && $linked->user_id !== $user->id) {
                        throw new DomainException('此第三方账户已绑定其他账户。');
                    }
                    if ($linked === null) {
                        UserOauthAccount::create(['user_id' => $user->id, 'provider' => $provider, 'provider_subject' => $subject]);
                        $this->audit($user, $provider, 'identity.oauth_link');
                    }
                } elseif ($linked === null || $linked->user_id !== $user->id) {
                    throw new DomainException('第三方绑定已改变，请重新登录。');
                }

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw new DomainException('第三方绑定已改变，请重新发起授权。');
        }
    }

    public function unlink(User $user, string $provider, string $password): void
    {
        DB::transaction(function () use ($user, $provider, $password): void {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->confirmAccount($locked, $password);
            UserOauthAccount::where('user_id', $locked->id)->where('provider', $provider)->delete();
            if ($provider === 'telegram') {
                UserNotificationBinding::where('user_id', $locked->id)->where('channel', 'telegram')->update(['verified_at' => null]);
            }
            $this->audit($locked, $provider, 'identity.oauth_unlink');
        });
    }

    private function confirmAccount(?User $user, string $password): void
    {
        if ($user === null || $user->email_verified_at === null || ! Hash::check($password, $user->password)) {
            throw new DomainException('请验证邮箱并输入当前账户密码。');
        }
    }

    private function audit(User $user, string $provider, string $action): void
    {
        DB::table('audit_logs')->insert(['actor_type' => 'user', 'actor_id' => $user->id, 'action' => $action,
            'subject_type' => User::class, 'subject_id' => $user->id, 'reason' => 'Customer confirmed social account change',
            'after' => json_encode(['provider' => $provider]), 'created_at' => now(), 'updated_at' => now()]);
    }
}
