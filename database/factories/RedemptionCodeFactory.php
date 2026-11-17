<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\RedemptionCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RedemptionCode> */
class RedemptionCodeFactory extends Factory
{
    public function definition(): array
    {
        $code = fake()->uuid();

        return [
            'product_id' => Product::factory(), 'code' => $code,
            'code_hash' => fn (array $attributes) => hash_hmac('sha256', $attributes['code'], config('app.key')),
            'status' => 'available',
        ];
    }
}
