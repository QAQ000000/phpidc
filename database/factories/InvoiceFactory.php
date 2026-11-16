<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_no' => 'INV'.fake()->unique()->numerify('##########'),
            'user_id' => User::factory(),
            'instance_id' => null,
            'product_id' => null,
            'type' => InvoiceType::NEW_ORDER,
            'currency' => 'CNY',
            'total_amount' => '10.00',
            'credit_applied' => '0.00',
            'gateway_amount' => '10.00',
            'discount_amount' => '0.00',
            'gateway' => null,
            'gateway_trade_no' => null,
            'upstream_expected_cost' => null,
            'status' => InvoiceStatus::UNPAID,
            'line_items' => null,
            'billing_period_key' => null,
            'paid_at' => null,
            'expires_at' => now()->addHour(),
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InvoiceStatus::PAID,
            'paid_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InvoiceStatus::EXPIRED,
            'expires_at' => now()->subHour(),
        ]);
    }
}
