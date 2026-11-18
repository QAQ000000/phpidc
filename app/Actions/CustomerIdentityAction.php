<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Models\UserNotificationBinding;
use App\Services\DriverManager;
use App\Services\SettingService;
use App\Support\CustomerEmailLookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class CustomerIdentityAction
{
    public function mailReady(): bool
    {
        $settings = app(SettingService::class);

        return $settings->get('mail.transport') === 'smtp' && trim($settings->get('mail.host', '') ?? '') !== ''
            && filter_var($settings->get('mail.from_address', ''), FILTER_VALIDATE_EMAIL) !== false;
    }

    public function requestPasswordReset(string $email): void
    {
        $this->requireMail();
        // Lock before creating/replacing a token, so concurrent requests cannot bypass broker throttle.
        $pending = DB::transaction(function () use ($email): ?array {
            $user = app(CustomerEmailLookup::class)->lockUnique($email);
            $tokens = Password::broker()->getRepository();
            if ($user === null || $tokens->recentlyCreatedToken($user)) {
                return null;
            }

            return [$user, $tokens->create($user)];
        });
        if ($pending !== null) {
            [$user, $token] = $pending;
            $url = $this->publicUrl('/reset-password/'.$token.'?'.http_build_query(['email' => $user->email]));
            $sent = $this->sendMail($user, '重置密码', "请在 60 分钟内打开以下链接重置密码：\n".$url."\n如果不是你发起的请求，请忽略。");
            DB::transaction(fn () => $this->audit($user, $sent ? 'identity.password_reset_sent' : 'identity.password_reset_delivery_failed'));
        }
    }

    public function resetPassword(string $email, string $token, string $password): void
    {
        validator(['password' => $password], ['password' => ['required', 'string', 'min:12', 'max:255']])->validate();
        DB::transaction(function () use ($email, $token, $password): void {
            $user = app(CustomerEmailLookup::class)->lockUnique($email);
            if ($user === null || ! Password::broker()->tokenExists($user, $token)) {
                throw ValidationException::withMessages(['token' => '重置链接无效或已过期。']);
            }
            $user->password = $password;
            $user->remember_token = Str::random(60);
            $user->save();
            Password::broker()->deleteToken($user);
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $this->audit($user, 'identity.password_reset');
        });
    }

    public function sendEmailVerification(User $user): void
    {
        $this->requireMail();
        $fresh = User::findOrFail($user->id);
        if ($fresh->email_verified_at !== null) {
            return;
        }
        $path = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60),
            ['id' => $fresh->id, 'hash' => sha1($fresh->email)], absolute: false);
        if (! $this->sendMail($fresh, '验证邮箱', "请登录你的账户后，在 60 分钟内打开以下链接验证邮箱：\n".$this->publicUrl($path))) {
            DB::transaction(fn () => $this->audit($fresh, 'identity.email_verification_delivery_failed'));
            throw ValidationException::withMessages(['operation' => '邮件发送失败，请稍后重试或联系管理员。']);
        }
    }

    /** Signature and expiry are verified by signed:relative middleware before this Action. */
    public function verifyEmail(User $user, int $id, string $hash): void
    {
        DB::transaction(function () use ($user, $id, $hash): void {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($locked->id !== $id || ! hash_equals(sha1($locked->email), $hash)) {
                throw ValidationException::withMessages(['email' => '验证链接不属于当前账户或邮箱已改变。']);
            }
            if ($locked->email_verified_at !== null) {
                return;
            }
            $locked->email_verified_at = now();
            $locked->save();
            UserNotificationBinding::updateOrCreate(['user_id' => $locked->id, 'channel' => 'email', 'target' => $locked->email], ['verified_at' => now()]);
            $this->audit($locked, 'identity.email_verified');
        });
    }

    private function requireMail(): void
    {
        if (! $this->mailReady()) {
            throw ValidationException::withMessages(['operation' => '账户邮件服务尚未配置，请联系管理员。']);
        }
    }

    private function sendMail(User $user, string $subject, string $body): bool
    {
        try {
            $sent = app(DriverManager::class)->mail()->send($user->email, $subject, $body);
        } catch (TransportExceptionInterface) {
            // Caller records an explicit delivery failure; no verification or reset is performed.
            return false;
        }

        return $sent;
    }

    private function publicUrl(string $path): string
    {
        // Never build recovery links from an untrusted incoming Host header.
        return rtrim((string) config('app.url'), '/').$path;
    }

    private function audit(User $user, string $event): void
    {
        DB::table('audit_logs')->insert(['actor_type' => 'user', 'actor_id' => $user->id, 'action' => $event,
            'subject_type' => User::class, 'subject_id' => $user->id, 'reason' => 'Customer account verification',
            'created_at' => now(), 'updated_at' => now()]);
    }
}
