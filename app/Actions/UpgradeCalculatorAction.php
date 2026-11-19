<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Instance;
use App\Models\ProductOptionGroup;
use App\Support\Money;
use DomainException;

class UpgradeCalculatorAction
{
    public function execute(Instance $instance, array $target): string
    {
        if (($instance->active_config['mapping_requires_review'] ?? false) || ($instance->active_config['price_requires_review'] ?? false)) {
            throw new DomainException('Review the target product mapping before upgrading a rebound service.');
        }
        $this->assertSupportedConfigurations($instance, $target);
        $old = collect($instance->active_config['options'] ?? [])->keyBy('local_group_id');
        $billed = collect($instance->active_config['billing_adjustment']['options'] ?? $instance->active_config['options'] ?? [])->keyBy('local_group_id');
        $recurring = $instance->recurring_amount;
        $changed = false;
        foreach ($target['pairs'] ?? [] as $pair) {
            $before = $old->get($pair['local_group_id']);
            if ($before === null) {
                throw new DomainException('Existing option snapshot is missing; synchronize mapping before upgrading.');
            }
            if ($before['value'] === $pair['value'] && $before['qty'] === $pair['qty']) {
                $billingBefore = $billed->get($pair['local_group_id']);
                if ($billingBefore === null) {
                    throw new DomainException('Existing billing snapshot is missing.');
                }
                $previous = Money::mul(Money::of($billingBefore['unit_price']), $billingBefore['qty']);
                $next = Money::mul($pair['unit_price'], $pair['qty']);
                if (Money::gt($previous, $next)) {
                    throw new DomainException('Mixed billing price decreases are not allowed in upgrades.');
                }
                $recurring = Money::add($recurring, Money::sub($next, $previous));

                continue;
            }
            $group = ProductOptionGroup::findOrFail($pair['local_group_id']);
            if (! $group->is_upgradeable || in_array($group->upstream_meta['option_type'] ?? null, [15, 16, 17, 18, 19], true)) {
                throw new DomainException('This option does not support a verified upgrade calculation.');
            }
            $billingBefore = $billed->get($pair['local_group_id']);
            if ($billingBefore === null) {
                throw new DomainException('Existing billing configuration is missing.');
            }
            $remotePrevious = Money::mul(Money::of($before['unit_price']), $before['qty']);
            $previous = Money::mul(Money::of($billingBefore['unit_price']), $billingBefore['qty']);
            $next = Money::mul($pair['unit_price'], $pair['qty']);
            if (Money::gt($remotePrevious, $next) || Money::gt($previous, $next) || ($group->type === 'quantity' && $pair['qty'] < $before['qty'])) {
                throw new DomainException('Mixed upgrades and downgrades are not allowed.');
            }
            $recurring = Money::add($recurring, Money::sub($next, $previous));
            $changed = true;
        }
        if (! $changed) {
            throw new DomainException('Select an actual configuration upgrade.');
        }

        return $recurring;
    }

    public function assertSupportedConfigurations(Instance $instance, array $target = []): void
    {
        $groupIds = array_column($target['pairs'] ?? [], 'local_group_id');
        $groupIds = array_merge($groupIds, array_column($instance->active_config['options'] ?? [], 'local_group_id'));
        $groupIds = array_merge($groupIds, array_column($instance->active_config['billing_adjustment']['options'] ?? [], 'local_group_id'));
        if (ProductOptionGroup::where(fn ($query) => $query->where('product_id', $instance->product_id)->where('is_hidden', false))
            ->orWhereIn('id', $groupIds)->get()
            ->contains(fn (ProductOptionGroup $group): bool => (int) ($group->upstream_meta['option_type'] ?? 0) === 20)) {
            throw new DomainException('Hierarchical configurations do not support verified configuration upgrades.');
        }
    }
}
