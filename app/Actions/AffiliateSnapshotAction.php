<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\AffiliateRule;
use App\Models\Product;
use App\Models\User;
use App\Services\SettingService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AffiliateSnapshotAction
{
    public function execute(User $buyer, Product $product): ?array
    {
        if ($product->currency !== 'CNY' || $buyer->referred_by === null || $buyer->referred_by === $buyer->id
            || ! User::whereKey($buyer->referred_by)->exists()) {
            return null;
        }
        $rule = AffiliateRule::where('is_active', true)->where(function ($q) use ($product): void {
            $q->whereNotIn('id', DB::table('affiliate_rule_product')->select('affiliate_rule_id'))
                ->orWhereIn('id', DB::table('affiliate_rule_product')->where('product_id', $product->id)->select('affiliate_rule_id'));
        })->orderByDesc('priority')->orderBy('id')->first();

        if ($rule !== null && Money::gt($rule->commission_rate, '100.00')) {
            throw ValidationException::withMessages(['product_id' => '推广规则费率异常，请联系运营核对。']);
        }

        return $rule === null || Money::isZero($rule->commission_rate) ? null : ['referrer_id' => $buyer->referred_by, 'rule_id' => $rule->id, 'rate' => $rule->commission_rate,
            'freeze_days' => max(0, app(SettingService::class)->getInt('affiliate.freeze_days', 7))];
    }
}
