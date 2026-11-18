<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\InvoiceType;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class ApplyCouponAction
{
    /** Caller has locked the user in its invoice transaction (C16 / INV-K4). */
    public function execute(User $user, Product $product, InvoiceType $type, string $subtotal, string $currency, ?string $code): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Coupon use requires the invoice transaction.');
        }
        if ($code === null || trim($code) === '') {
            return ['coupon' => null, 'discount' => Money::zero()];
        }
        $coupon = Coupon::where('code', $code)->lockForUpdate()->first();

        return $this->discount($user, $product, $type, $subtotal, $currency, $coupon, true);
    }

    /** Read-only preview: confirmation rechecks eligibility under the coupon lock. */
    public function preview(User $user, Product $product, InvoiceType $type, string $subtotal, string $currency, ?string $code): array
    {
        if ($code === null || trim($code) === '') {
            return ['coupon' => null, 'discount' => Money::zero()];
        }

        return $this->discount($user, $product, $type, $subtotal, $currency, Coupon::where('code', $code)->first(), false);
    }

    private function discount(User $user, Product $product, InvoiceType $type, string $subtotal, string $currency, ?Coupon $coupon, bool $reserve): array
    {
        if ($coupon === null || $currency !== 'CNY' || ! in_array($type, [InvoiceType::NEW_ORDER, InvoiceType::RENEW], true)) {
            throw ValidationException::withMessages(['coupon_code' => '此优惠券不适用于当前账单类型或币种。']);
        }
        app(CouponEligibilityAction::class)->execute($coupon, $user);
        if (! DB::table('coupon_claims')->where('coupon_id', $coupon->id)->where('user_id', $user->id)->exists()
            || ($coupon->applies_to !== 'both' && $coupon->applies_to !== $type->value)
            || ($coupon->product_ids !== null && ! in_array($product->id, $coupon->product_ids, true))
            || Money::gt($coupon->min_order_amount, $subtotal)
            || ($coupon->total_quota !== null && $coupon->used_count >= $coupon->total_quota)
            || DB::table('coupon_usages')->where('coupon_id', $coupon->id)->where('user_id', $user->id)->count() >= $coupon->per_user_limit) {
            throw ValidationException::withMessages(['coupon_code' => '未领取优惠券、条件不符或使用次数已用完。']);
        }
        if (Money::isZero($coupon->value) || ($coupon->type === 'percent' && Money::gt($coupon->value, '100.00'))) {
            throw ValidationException::withMessages(['coupon_code' => '优惠规则数值异常，请联系运营核对。']);
        }
        $discount = match ($coupon->type) {
            'fixed' => Money::min($subtotal, $coupon->value),
            'percent' => Money::min($subtotal, Money::percentOf($subtotal, $coupon->value)),
            default => throw ValidationException::withMessages(['coupon_code' => '未知优惠券类型。']),
        };
        if ($reserve) {
            $coupon->increment('used_count');
        }

        return ['coupon' => $coupon, 'discount' => $discount];
    }

    public function record(Invoice $invoice, ?Coupon $coupon): void
    {
        if ($coupon !== null) {
            DB::table('coupon_usages')->insert(['coupon_id' => $coupon->id, 'user_id' => $invoice->user_id, 'invoice_id' => $invoice->id,
                'discount_amount' => $invoice->discount_amount, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
