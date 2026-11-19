<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ProductOptionGroup;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ConfigureDowngradeBoundsAction
{
    public function execute(ProductOptionGroup $group, bool $allow, ?int $minimum, array $itemIds, User $actor, string $reason): void
    {
        Gate::forUser($actor)->authorize('upstream.import');
        if (trim($reason) === '') {
            throw new DomainException('An operator reason is required.');
        }
        DB::transaction(function () use ($group, $allow, $minimum, $itemIds, $actor, $reason): void {
            $locked = ProductOptionGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();
            $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
            if ($allow && ($locked->is_hidden || $locked->sync_source !== 'upstream' || in_array((int) ($locked->upstream_meta['option_type'] ?? 0), [15, 16, 17, 18, 19, 20], true))) {
                throw new DomainException('Only visible supported upstream options can permit a billing downgrade.');
            }
            if ($allow && in_array($locked->type, ['quantity', 'slider'], true)) {
                if ($minimum === null || $minimum < $locked->min_value || $minimum > $locked->max_value || ($minimum - $locked->min_value) % max(1, $locked->step) !== 0) {
                    throw new DomainException('The downgrade minimum must respect the option range and step.');
                }
            } elseif ($allow && ($itemIds === [] || $locked->items()->where('is_hidden', false)->whereIn('id', $itemIds)->count() !== count($itemIds))) {
                throw new DomainException('Allowed items must belong to this visible option group.');
            }
            $before = $locked->downgrade_bounds;
            $bounds = ['allow_downgrade' => $allow, 'min_value' => $minimum, 'allowed_item_ids' => $itemIds];
            $locked->update(['downgrade_bounds' => $bounds]);
            DB::table('audit_logs')->insert(['actor_type' => 'user', 'actor_id' => $actor->id, 'action' => 'product.downgrade_bounds',
                'subject_type' => ProductOptionGroup::class, 'subject_id' => $locked->id, 'reason' => $reason,
                'before' => json_encode($before), 'after' => json_encode($bounds), 'created_at' => now(), 'updated_at' => now()]);
        });
    }
}
