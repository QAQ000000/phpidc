<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use Database\Factories\FulfillmentTaskFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FulfillmentTask extends Model
{
    /** @use HasFactory<FulfillmentTaskFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'driver_type' => DriverType::class,
            'status' => FulfillmentTaskStatus::class,
            'payload' => 'array',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'locked_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'available_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Instance, $this> */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function upstreamApi(): BelongsTo
    {
        return $this->belongsTo(UpstreamApi::class);
    }
}
