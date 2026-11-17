<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Coupon;
use App\Models\User;
use App\Support\OperationsAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ClaimCouponAction
{
    public function execute(User $user, string $code, ?User $actor = null, string $reason = ''): void
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('promotion.manage');
            validator(['reason' => $reason], ['reason' => ['required', 'string', 'max:255']])->validate();
        }
        DB::transaction(function () use ($user, $code, $actor, $reason): void {
            $users = User::whereKey(array_filter([$user->id, $actor?->id]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $locked = $users->get($user->id);
            abort_unless($locked !== null, 422);
            if ($actor !== null) {
                $actor = $users->get($actor->id);
                abort_unless($actor !== null, 403);
                Gate::forUser($actor)->authorize('promotion.manage');
            }
            $coupon = Coupon::where('code', $code)->lockForUpdate()->firstOrFail();
            app(CouponEligibilityAction::class)->execute($coupon, $locked);
            $inserted = DB::table('coupon_claims')->insertOrIgnore(['coupon_id' => $coupon->id, 'user_id' => $locked->id,
                'claimed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            if ($actor !== null && $inserted === 1) {
                OperationsAudit::record($actor, 'coupon.grant', Coupon::class, $coupon->id, $reason, [], ['user_id' => $locked->id]);
            }
        });
    }
}
