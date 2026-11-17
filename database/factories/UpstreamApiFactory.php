<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DriverType;
use App\Models\UpstreamApi;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UpstreamApi> */
class UpstreamApiFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->unique()->uuid(), 'driver' => DriverType::ZJMF_API,
            'base_url' => 'https://classic.test', 'username' => 'operator@example.test',
            'encrypted_secret' => 'isolated-api-secret', 'is_active' => true];
    }
}
