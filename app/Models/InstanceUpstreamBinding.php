<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DriverType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Binding history. A hot swap closes the current row rather than overwriting
 * it, so an old upstream host is never silently orphaned (INV-S3).
 */
class InstanceUpstreamBinding extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'driver_type' => DriverType::class,
            'bound_at' => 'datetime',
            'unbound_at' => 'datetime',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }

    public function upstreamApi(): BelongsTo
    {
        return $this->belongsTo(UpstreamApi::class);
    }
}
