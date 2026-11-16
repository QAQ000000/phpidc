<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BillingCycle;
use App\Enums\DriverType;
use App\Enums\InstanceStatus;
use App\Models\Instance;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Instance>
 */
class InstanceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'product_id' => Product::factory(),
            'driver_type' => DriverType::REDEMPTION,
            'upstream_api_id' => null,
            'upstream_instance_id' => null,
            'downstream_token' => hash('md5', Str::uuid()->toString()),
            'display_name' => fake()->words(2, true),
            'hostname' => null,
            'primary_ip' => null,
            'assigned_ips' => [],
            'status' => InstanceStatus::PENDING,
            'active_config' => null,
            'custom_field_values' => null,
            'billing_cycle' => BillingCycle::MONTHLY,
            'currency' => 'CNY',
            'recurring_amount' => '10.00',
            'is_price_locked' => false,
            'encrypted_credentials' => null,
            'suspend_reason' => null,
            'next_due_at' => now()->addMonth(),
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => ['status' => InstanceStatus::ACTIVE]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => ['status' => InstanceStatus::SUSPENDED]);
    }
}
