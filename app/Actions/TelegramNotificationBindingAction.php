<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Models\UserNotificationBinding;
use App\Models\UserOauthAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class TelegramNotificationBindingAction
{
    public function execute(User $user, string $password, bool $enabled): void
    {
        DB::transaction(function () use ($user, $password, $enabled): void {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($locked->email_verified_at === null || ! Hash::check($password, $locked->getAuthPassword())) {
                throw ValidationException::withMessages(['operation' => '请验证邮箱并输入当前账户密码。']);
            }
            $bindings = UserOauthAccount::where('user_id', $locked->id)->where('provider', 'telegram')->lockForUpdate()->get();
            if ($enabled && ($bindings->count() !== 1 || preg_match('/^[1-9][0-9]{0,19}$/D', $bindings->first()->provider_subject) !== 1)) {
                throw ValidationException::withMessages(['operation' => '请先绑定一个 Telegram 账户。']);
            }
            UserNotificationBinding::where('user_id', $locked->id)->where('channel', 'telegram')->update(['verified_at' => null]);
            if ($enabled) {
                UserNotificationBinding::updateOrCreate(['user_id' => $locked->id, 'channel' => 'telegram', 'target' => $bindings->first()->provider_subject], ['verified_at' => now()]);
            }
            DB::table('audit_logs')->insert(['actor_type' => 'user', 'actor_id' => $locked->id, 'action' => 'identity.telegram_notifications',
                'subject_type' => User::class, 'subject_id' => $locked->id, 'reason' => 'Customer changed Telegram notification subscription',
                'after' => json_encode(['enabled' => $enabled]), 'created_at' => now(), 'updated_at' => now()]);
        });
    }
}
