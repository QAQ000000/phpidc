<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FulfillmentTaskStatus;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FulfillmentTask> */
class FulfillmentTaskFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'instance_id' => Instance::factory(),
            'user_id' => fn (array $attributes) => Instance::findOrFail($attributes['instance_id'])->user_id,
            'invoice_id' => fn (array $attributes) => Invoice::factory()->paid()->create([
                'user_id' => $attributes['user_id'],
                'instance_id' => $attributes['instance_id'],
                'product_id' => Instance::findOrFail($attributes['instance_id'])->product_id,
            ])->id,
            'driver_type' => fn (array $attributes) => Instance::findOrFail($attributes['instance_id'])->driver_type,
            'upstream_api_id' => fn (array $attributes) => Instance::findOrFail($attributes['instance_id'])->upstream_api_id,
            'action' => 'provision',
            'trigger_source' => 'order_paid',
            // INV-K2: reusing this invoice/instance/action must hit the unique key.
            'idempotency_key' => fn (array $attributes) => "{$attributes['invoice_id']}:{$attributes['action']}:{$attributes['instance_id']}",
            'payload' => [],
            'status' => FulfillmentTaskStatus::PENDING,
            'attempts' => 0,
            'max_attempts' => 5,
            'available_at' => now(),
        ];
    }
}
