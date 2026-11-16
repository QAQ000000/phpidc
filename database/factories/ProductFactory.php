<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DriverType;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'name' => fake()->words(3, true),
            'slug' => fake()->unique()->slug(3),
            'description' => fake()->paragraph(),
            'driver_type' => DriverType::REDEMPTION,
            'upstream_api_id' => null,
            'upstream_pid' => null,
            'currency' => 'CNY',
            'is_hidden' => false,
            'require_kyc' => false,
            'require_phone' => false,
            'markup_percent' => '20.00',
            'price_locked' => false,
            'stock_control' => false,
            'qty' => 0,
            'upstream_base_prices' => [
                'monthly' => ['price' => '10.00', 'setup_fee' => '0.00', 'enabled' => true],
            ],
            'local_prices' => [
                'monthly' => ['price' => '10.00', 'setup_fee' => '0.00', 'enabled' => true],
            ],
            'provisioning_rules' => null,
            'network_mode' => null,
            'sort_order' => 0,
        ];
    }

    public function hidden(): static
    {
        return $this->state(fn (array $attributes) => ['is_hidden' => true]);
    }
}
