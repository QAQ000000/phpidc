<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AffiliateCommission;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AffiliateCommission> */
class AffiliateCommissionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'referrer_id' => User::factory(), 'buyer_id' => User::factory(),
            'invoice_id' => fn (array $attributes) => Invoice::factory()->paid()->create(['user_id' => $attributes['buyer_id']])->id,
            'rate_snapshot' => '10.00', 'amount' => '1.00', 'status' => 'pending', 'available_at' => now()->addDays(7),
        ];
    }
}
