<?php

declare(strict_types=1);

namespace App\Actions;

use App\Drivers\Notification\IdcsmartSmsDriver;
use App\Drivers\Notification\LogSmsDriver;
use App\Models\User;
use App\Models\UserNotificationBinding;
use App\Services\DriverManager;
use App\Services\SettingService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class PhoneBindingAction
{
    public function available(): bool
    {
        $driver = app(DriverManager::class)->sms();

        return app(SettingService::class)->getBool('sms.binding_enabled')
            && ! ($driver instanceof LogSmsDriver)
            && (! ($driver instanceof IdcsmartSmsDriver) || ($driver->ready() && $driver->hasTemplate(app(SettingService::class)->get('sms.binding_template', '') ?? '')));
    }

    public function send(User $user, string $phone): void
    {
        validator(['phone' => $phone], ['phone' => ['required', 'regex:/^\+[1-9][0-9]{7,14}$/D']])->validate();
        if (! $this->available()) {
            throw ValidationException::withMessages(['phone' => '真实短信通道尚未接入，暂不能验证或绑定手机号。']);
        }
        $throttleKey = 'phone-send:'.$user->id;
        $result = Cache::lock('phone-binding-lock:'.$user->id, 60)->get(function () use ($user, $phone, $throttleKey): bool {
            if (RateLimiter::tooManyAttempts($throttleKey, 1)) {
                throw ValidationException::withMessages(['phone' => '请稍候再发送验证码。']);
            }
            RateLimiter::hit($throttleKey, 60);
            Cache::forget('phone-binding:'.$user->id);
            $fresh = User::findOrFail($user->id);
            $code = (string) random_int(100000, 999999);
            $template = app(SettingService::class)->get('sms.binding_template', '') ?? '';
            if ($template === '' || ! app(DriverManager::class)->sms()->sendTemplate($phone, $template, ['code' => $code, 'minutes' => '10'])) {
                throw ValidationException::withMessages(['phone' => '验证码发送失败，请稍后重试。']);
            }
            // Delivery runs outside a database transaction. Recheck after it returns so account
            // closure/password rotation cannot be followed by a newly persisted stale proof.
            DB::transaction(function () use ($user, $phone, $code, $fresh): void {
                $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                if ($locked->anonymized_at !== null || ! hash_equals($fresh->getAuthPassword(), $locked->getAuthPassword())) {
                    throw ValidationException::withMessages(['phone' => '账户已改变，请重新登录后发送验证码。']);
                }
                Cache::put('phone-binding:'.$user->id, ['phone' => $phone, 'hash' => Hash::make($code),
                    'password_hash' => $locked->getAuthPassword(), 'attempts' => 0, 'expires' => now()->addMinutes(10)->timestamp], now()->addMinutes(10));
            });

            return true;
        });
        if ($result !== true) {
            throw ValidationException::withMessages(['phone' => '验证码处理中，请稍后重试。']);
        }
    }

    public function confirm(User $user, string $phone, string $code): void
    {
        if (! $this->available()) {
            throw ValidationException::withMessages(['phone' => '真实短信通道尚未接入。']);
        }
        $result = Cache::lock('phone-binding-lock:'.$user->id, 60)->get(function () use ($user, $phone, $code): bool {
            $key = 'phone-binding:'.$user->id;
            $challenge = Cache::get($key);
            if (! is_array($challenge) || $challenge['attempts'] >= 5 || $challenge['expires'] <= now()->timestamp) {
                throw ValidationException::withMessages(['code' => '验证码无效或已过期，请重新发送。']);
            }
            // Preserve the original expiry; failed attempts must not extend the challenge lifetime.
            $challenge['attempts']++;
            Cache::put($key, $challenge, max(1, $challenge['expires'] - now()->timestamp));
            if ($challenge['phone'] !== $phone || ! Hash::check($code, $challenge['hash'])) {
                if ($challenge['attempts'] >= 5) {
                    Cache::forget($key);
                }
                throw ValidationException::withMessages(['code' => '验证码错误。']);
            }
            try {
                DB::transaction(function () use ($user, $phone, $challenge): void {
                    $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                    if (! hash_equals($challenge['password_hash'], $locked->getAuthPassword())) {
                        throw ValidationException::withMessages(['code' => '账户已改变，请重新发送验证码。']);
                    }
                    if (User::withTrashed()->where('phone', $phone)->whereKeyNot($locked->id)->exists()) {
                        throw ValidationException::withMessages(['phone' => '此号码暂不能绑定，请联系管理员。']);
                    }
                    $locked->phone = $phone;
                    $locked->save();
                    UserNotificationBinding::where('user_id', $locked->id)->where('channel', 'sms')->update(['verified_at' => null]);
                    UserNotificationBinding::updateOrCreate(['user_id' => $locked->id, 'channel' => 'sms', 'target' => $phone], ['verified_at' => now()]);
                    DB::table('audit_logs')->insert(['actor_type' => 'user', 'actor_id' => $locked->id,
                        'action' => 'identity.phone_verified', 'subject_type' => User::class, 'subject_id' => $locked->id,
                        'reason' => 'Customer completed SMS ownership challenge', 'created_at' => now(), 'updated_at' => now()]);
                });
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['phone' => '此号码暂不能绑定，请联系管理员。']);
            }
            Cache::forget($key);

            return true;
        });
        if ($result !== true) {
            throw ValidationException::withMessages(['code' => '验证码处理中，请稍后重试。']);
        }
    }
}
