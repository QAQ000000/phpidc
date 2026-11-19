<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\QuoteResult;
use App\Enums\BillingCycle;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionItem;
use App\Support\ClassicPurchaseAdmission;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

class QuoteLocalProductAction
{
    /** A catalog estimate only; checkout must obtain a fresh upstream quote. */
    public function execute(Product $product, string $cycle, array $resolvedOptions): QuoteResult
    {
        if (BillingCycle::tryFrom($cycle) === null) {
            $this->invalid('billing_cycle', 'Select a supported billing cycle.');
        }
        app(ClassicPurchaseAdmission::class)->assertAllowed($product, $cycle);
        [$period, $setup] = $this->price($product->local_prices ?? [], $cycle, 'billing_cycle');
        [$costPeriod, $costSetup] = $this->cost($product->upstream_base_prices ?? [], $cycle, 'billing_cycle');
        $groups = $product->optionGroups()->where('is_hidden', false)->where('sync_source', 'upstream')
            ->with(['items' => fn ($query) => $query->where('is_hidden', false)->orderBy('sort_order')->orderBy('id')])->get();
        $pairs = collect($resolvedOptions['pairs'] ?? []);
        if ($pairs->count() !== $groups->count() || $pairs->unique('local_group_id')->count() !== $pairs->count()
            || count($resolvedOptions['configoption'] ?? []) !== $groups->count()) {
            $this->invalid('options', 'Resolve every configuration option before estimating its price.');
        }
        foreach ($groups as $group) {
            $pair = $pairs->firstWhere('local_group_id', $group->id);
            $item = $group->items->firstWhere('id', $pair['local_item_id'] ?? null);
            $type = (int) ($group->upstream_meta['option_type'] ?? 0);
            if ($pair === null || $item === null || $type < 1 || $type > 19
                || (int) ($pair['upstream_group_id'] ?? 0) !== (int) $group->upstream_group_id
                || (int) ($pair['upstream_sub_id'] ?? 0) !== (int) $item->upstream_sub_id) {
                $this->invalid('options', 'The selected configuration has no complete local pricing snapshot.');
            }
            $quantityType = in_array($type, [4, 7, 9, 11, 14, 15, 16, 17, 18, 19], true);
            $quantity = $pair['qty'];
            if (! is_int($quantity) || $quantity < 0 || (! $quantityType && ! in_array($quantity, $type === 3 ? [0, 1] : [1], true))) {
                $this->invalid('options', 'The resolved configuration has an invalid quantity.');
            }
            if ($quantity === 0) {
                continue;
            }
            if ($type >= 15) {
                [$optionPeriod, $optionSetup, $optionCostPeriod, $optionCostSetup] = $this->tiered($group, $item, $cycle, $quantity);
            } else {
                [$optionPeriod, $optionSetup] = $this->price($item->local_prices ?? [], $cycle, 'options');
                [$optionCostPeriod, $optionCostSetup] = $this->cost($item->upstream_base_prices ?? [], $cycle, 'options');
                // Classic quantity setup fees are charged once, not per unit.
                $optionPeriod = Money::mul($optionPeriod, $quantity);
                $optionCostPeriod = Money::mul($optionCostPeriod, $quantity);
            }
            $period = Money::add($period, $optionPeriod);
            $setup = Money::add($setup, $optionSetup);
            $costPeriod = Money::add($costPeriod, $optionCostPeriod);
            $costSetup = Money::add($costSetup, $optionCostSetup);
        }

        return new QuoteResult(upstreamBaseCost: Money::add($costPeriod, $costSetup), downstreamPrice: $period,
            setupFee: $setup, currency: $product->currency,
            breakdown: ['estimate' => true, 'source' => 'local_catalog', 'catalog_version' => $product->upstream_version]);
    }

    /** @return array{string, string} */
    private function price(array $prices, string $cycle, string $field): array
    {
        $price = $prices[$cycle] ?? [];
        if (! ($price['enabled'] ?? false) || ! isset($price['price'], $price['setup_fee'])) {
            $this->invalid($field, 'An enabled local price with an explicit setup fee is required. Synchronize the catalog.');
        }

        return [Money::of($price['price']), Money::of($price['setup_fee'])];
    }

    /** Local inventory products may have no upstream cost at all. @return array{string, string} */
    private function cost(array $prices, string $cycle, string $field): array
    {
        return isset($prices[$cycle]) ? $this->price($prices, $cycle, $field) : [Money::zero(), Money::zero()];
    }

    /** @return array{string, string, string, string} */
    private function tiered(ProductOptionGroup $group, ProductOptionItem $selected, string $cycle, int $quantity): array
    {
        $tiers = collect($group->upstream_meta['subs'] ?? [])->sortBy(fn ($sub) => (int) ($sub['qty_maximum'] ?? -1))->values();
        $period = Money::zero();
        $cost = Money::zero();
        foreach ($tiers as $tier) {
            $min = $tier['qty_minimum'] ?? null;
            $max = $tier['qty_maximum'] ?? null;
            if (filter_var($min, FILTER_VALIDATE_INT) === false || filter_var($max, FILTER_VALIDATE_INT) === false || $min < 0 || $max < $min) {
                $this->invalid('options', 'A quantity tier has no valid local range. Synchronize the catalog.');
            }
            $item = $group->items->firstWhere('upstream_sub_id', $tier['id'] ?? null);
            if ($item === null) {
                $this->invalid('options', 'A quantity tier has no local price. Synchronize the catalog.');
            }
            [$unitPrice, $setup] = $this->price($item->local_prices ?? [], $cycle, 'options');
            [$unitCost, $costSetup] = $this->cost($item->upstream_base_prices ?? [], $cycle, 'options');
            // A tier starting at zero still bills q units, not q + 1.
            $units = min($quantity, (int) $max) - max(1, (int) $min) + 1;
            if ($units > 0) {
                $period = Money::add($period, Money::mul($unitPrice, $units));
                $cost = Money::add($cost, Money::mul($unitCost, $units));
            }
            if ($quantity >= $min && $quantity <= $max) {
                if ($selected->id !== $item->id) {
                    $this->invalid('options', 'The resolved quantity tier changed. Resolve the configuration again.');
                }

                // Like classic quantityStagePrice(), only the current tier supplies setup.
                return [$period, $setup, $cost, $costSetup];
            }
        }
        $this->invalid('options', 'The selected quantity is outside the local pricing tiers.');
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
