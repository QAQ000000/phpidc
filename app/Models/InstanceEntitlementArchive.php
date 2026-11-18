<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class InstanceEntitlementArchive extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['delivery_snapshot' => 'array', 'confirmation_version' => 'integer', 'confirmed_at' => 'datetime', 'archived_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Entitlement archives are append-only.'));
        static::deleting(fn () => throw new LogicException('Entitlement archives are append-only.'));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** @return BelongsTo<Instance, $this> */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }
}
