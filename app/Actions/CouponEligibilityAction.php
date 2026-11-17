<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Coupon;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CouponEligibilityAction
{
    public function execute(Coupon $coupon, User $user): void
    {
        $rules = $coupon->claim_conditions ?? [];
        $days = (int) $user->created_at->diffInDays(now());
        if (! $coupon->is_active || ($coupon->valid_from !== null && $coupon->valid_from->isFuture())
            || ($coupon->valid_until !== null && ! $coupon->valid_until->isFuture())
            || (isset($rules['registered_days_gt']) && $days <= (int) $rules['registered_days_gt'])
            || (isset($rules['registered_days_lt']) && $days >= (int) $rules['registered_days_lt'])
            || (($rules['require_kyc'] ?? false) && $user->kyc_verified_at === null)) {
            throw ValidationException::withMessages(['coupon_code' => '优惠券尚未生效、已失效或账户不符合领取条件。']);
        }
        foreach ($rules['require_bound_channels'] ?? [] as $channel) {
            if (! in_array($channel, ['email', 'telegram', 'sms'], true) || ! DB::table('user_notification_bindings')->where('user_id', $user->id)
                ->whereIn('channel', $channel === 'email' ? ['email', 'mail'] : [$channel])->whereNotNull('verified_at')->exists()) {
                throw ValidationException::withMessages(['coupon_code' => '请先验证优惠券要求的通知渠道。']);
            }
        }
    }
}
