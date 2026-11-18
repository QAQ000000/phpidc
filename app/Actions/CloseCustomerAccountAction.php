<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Models\UserNotificationBinding;
use App\Models\UserOauthAccount;
use App\Services\AccountClosureEligibility;
use App\Support\OperationsAudit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CloseCustomerAccountAction
{
    public function execute(User $user, string $currentPassword, bool $confirmed): User
    {
        if (! $confirmed) {
            throw ValidationException::withMessages(['confirm' => '请明确确认注销不可恢复及历史业务证据的留存范围。']);
        }

        return DB::transaction(function () use ($user, $currentPassword): User {
            $locked = User::withTrashed()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($locked->anonymized_at !== null && $locked->trashed()) {
                return $locked;
            }
            if ($locked->trashed() || $locked->anonymized_at !== null || ! Hash::check($currentPassword, $locked->getAuthPassword())) {
                throw ValidationException::withMessages(['current_password' => '当前密码错误或账户已停用。']);
            }
            app(AccountClosureEligibility::class)->assertMayClose($locked);
            $notifications = app(CancelUserNotificationsAction::class)->execute($locked);
            $oldEmail = $locked->email;
            $counts = ['oauth' => UserOauthAccount::where('user_id', $locked->id)->delete(),
                'bindings' => UserNotificationBinding::where('user_id', $locked->id)->delete(),
                'sessions' => DB::table('sessions')->where('user_id', $locked->id)->delete(),
                'reset_tokens' => DB::table('password_reset_tokens')->where('email', $oldEmail)->delete()];
            $locked->forceFill(['name' => '已注销用户', 'email' => 'closed-'.$locked->id.'-'.Str::lower(Str::random(32)).'@deleted.invalid',
                'phone' => null, 'email_verified_at' => null, 'kyc_verified_at' => null, 'password' => Str::random(96),
                'remember_token' => Str::random(60), 'anonymized_at' => now(), 'deleted_at' => now()])->save();
            OperationsAudit::record($locked, 'identity.account_anonymized', User::class, $locked->id,
                'Customer confirmed irreversible account authentication/contact anonymization; historical business evidence retained',
                [], ['confirmation_version' => 1, 'cleared' => $counts, 'notifications' => $notifications, 'anonymized' => true]);
            DB::afterCommit(function () use ($locked): void {
                try {
                    Cache::forget('phone-binding:'.$locked->id);
                    RateLimiter::clear('phone-send:'.$locked->id);
                } catch (\Exception $error) {
                    // Authentication is already revoked durably; cache cleanup failure cannot reauthorize an old proof.
                    Log::warning('Closed account challenge cache cleanup requires attention.', ['user_id' => $locked->id, 'exception_class' => $error::class]);
                }
            });

            return $locked;
        });
    }
}
