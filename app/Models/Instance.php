<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\DriverType;
use App\Enums\InstanceStatus;
use Database\Factories\InstanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Instance extends Model
{
    /** @use HasFactory<InstanceFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'driver_type' => DriverType::class,
            'status' => InstanceStatus::class,
            'billing_cycle' => BillingCycle::class,
            'active_config' => 'array',
            'assigned_ips' => 'array',
            'custom_field_values' => 'array',
            // Card keys and panel credentials must never sit in plaintext.
            'encrypted_credentials' => 'encrypted:array',
            'recurring_amount' => 'decimal:2',
            'is_price_locked' => 'boolean',
            'next_due_at' => 'datetime',
            'suspended_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'overdue_since' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function upstreamApi(): BelongsTo
    {
        return $this->belongsTo(UpstreamApi::class);
    }

    public function bindings(): HasMany
    {
        return $this->hasMany(InstanceUpstreamBinding::class);
    }

    public function fulfillmentTasks(): HasMany
    {
        return $this->hasMany(FulfillmentTask::class);
    }
}
