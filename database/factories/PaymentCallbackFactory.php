<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentCallbackOutcome;
use App\Models\Invoice;
use App\Models\PaymentCallback;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentCallback> */
class PaymentCallbackFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory()->state(['gateway' => 'epay_wechat']),
            'invoice_no' => fn (array $attributes) => Invoice::findOrFail($attributes['invoice_id'])->invoice_no,
            'gateway' => fn (array $attributes) => Invoice::findOrFail($attributes['invoice_id'])->gateway,
            'gateway_trade_no' => fake()->unique()->uuid(),
            'amount' => '5.00',
            'outcome' => PaymentCallbackOutcome::REJECTED_SHORT_AMOUNT,
            'raw_payload' => [],
            'processed_at' => now(),
        ];
    }
}
