<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Product;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

class ResolveProductOptionsAction
{
    public function execute(Product $product, string $cycle, array $options, bool $validateCustomFields = true): array
    {
        $groups = $product->optionGroups()->where('is_hidden', false)->with(['items' => fn ($q) => $q->where('is_hidden', false)->orderBy('sort_order')->orderBy('id')])->get();
        if (! isset($product->provisioning_rules['classic_catalog'])) {
            return $options;
        }
        $selected = $options['configoption'] ?? [];
        $mapped = [];
        $pairs = [];
        foreach ($groups as $group) {
            if ($group->sync_source !== 'upstream') {
                continue;
            }
            $key = $group->upstream_group_id;
            $value = $selected[$key] ?? null;
            if ($value === null || filter_var($value, FILTER_VALIDATE_INT) === false) {
                $this->invalid('Select every configuration option.');
            }
            $value = (int) $value;
            $quantity = in_array($group->type, ['quantity', 'slider'], true);
            if ($quantity) {
                $stage = $group->upstream_meta['qty_stage'] ?? null;
                // Classic stages use absolute multiples with both endpoints allowed.
                $validStep = $stage !== null
                    ? $value % max(1, (int) $stage) === 0 || $value === (int) $group->min_value || $value === (int) $group->max_value
                    : ($value - $group->min_value) % max(1, $group->step) === 0;
                if ($value < $group->min_value || $value > $group->max_value || ! $validStep) {
                    $this->invalid('Configuration quantity is outside its bounds or step.');
                }
            }
            $item = $group->type === 'toggle' && $value === 0 ? $group->items->first() : ($quantity ? $group->items->first(function ($item) use ($group, $value) {
                foreach ($group->upstream_meta['subs'] ?? [] as $sub) {
                    if ((int) $sub['id'] === (int) $item->upstream_sub_id && $value >= (int) ($sub['qty_minimum'] ?? 0) && $value <= (int) ($sub['qty_maximum'] ?? PHP_INT_MAX)) {
                        return true;
                    }
                }

                return false;
            }) : $group->items->firstWhere('upstream_sub_id', $value));
            if ($item === null || ! ($item->upstream_base_prices[$cycle]['enabled'] ?? false)) {
                $this->invalid('Selected option is not offered for this billing cycle.');
            }
            $localPrice = $item->local_prices[$cycle]['price'] ?? null;
            if (! is_string($localPrice) && ! is_int($localPrice)) {
                $this->invalid('Selected option has no local price. Synchronize the catalog.');
            }
            $mapped[$key] = $value;
            $pairs[] = ['local_group_id' => $group->id, 'local_item_id' => $item->id, 'upstream_group_id' => (int) $key,
                'upstream_sub_id' => (int) $item->upstream_sub_id, 'label' => $group->name, 'value' => $quantity ? $value : $item->id,
                'qty' => $quantity ? $value : ($group->type === 'toggle' && $value === 0 ? 0 : 1), 'unit_price' => Money::of($localPrice)];
        }
        if (array_diff(array_keys($selected), array_keys($mapped)) !== []) {
            $this->invalid('Unknown or hidden configuration option.');
        }
        $quantityGroupIds = $groups->filter(fn ($group) => in_array($group->type, ['quantity', 'slider'], true))
            ->map(fn ($group) => (int) $group->upstream_group_id)->all();
        $this->dependencies($product->provisioning_rules['classic_catalog']['dependencies'] ?? [], $mapped, $quantityGroupIds);
        if (! $validateCustomFields) {
            return ['configoption' => $mapped, 'customfield' => [], 'pairs' => $pairs];
        }
        $custom = [];
        $submitted = $options['customfield'] ?? [];
        foreach ($product->customFields()->where('is_hidden', false)->where('sync_source', 'upstream')->where('show_on_order', true)->get() as $field) {
            $value = $submitted[$field->upstream_field_id] ?? '';
            if (! is_string($value) || strlen($value) > 4096 || ($field->is_required && trim($value) === '')
                || ($value !== '' && $field->field_type === 'select' && ! in_array($value, $field->options ?? [], true))
                || ($value !== '' && $field->validation_regex && preg_match('/'.str_replace('/', '\/', $field->validation_regex).'/', $value) !== 1)) {
                $this->invalid('Custom field does not satisfy its required value, choices or format.');
            }
            $custom[$field->upstream_field_id] = $value;
        }
        if (array_diff(array_keys($submitted), array_keys($custom)) !== []) {
            $this->invalid('Unknown or hidden custom field.');
        }

        return ['configoption' => $mapped, 'customfield' => $custom, 'pairs' => $pairs];
    }

    public function dependencies(array $rules, array $values, array $quantityGroupIds = []): void
    {
        $conditions = [];
        foreach ($rules as $rule) {
            if (($rule['type'] ?? null) === 'condition') {
                $conditions[$rule['id']] = $rule;
            }
        }
        foreach ($rules as $rule) {
            if (($rule['type'] ?? null) !== 'result') {
                continue;
            }
            $condition = $conditions[$rule['relation_id']] ?? null;
            if ($condition === null) {
                $this->invalid('Unreadable configuration dependency.');
            }
            if ($this->matches($condition, $values, $quantityGroupIds) && ! $this->matches($rule, $values, $quantityGroupIds)) {
                $this->invalid('Selected configuration violates a linked option rule.');
            }
        }
    }

    private function matches(array $rule, array $values, array $quantityGroupIds): bool
    {
        $value = $values[$rule['config_id']] ?? null;
        $matched = false;
        foreach ($rule['sub_id'] ?? [] as $id => $bounds) {
            if (in_array((int) $rule['config_id'], $quantityGroupIds, true)) {
                $matched = $matched || ($value !== null && $value >= $bounds['qty_minimum'] && $value <= $bounds['qty_maximum']);
            } else {
                $matched = $matched || ($value !== null && (int) $value === (int) $id);
            }
        }
        if (! in_array($rule['relation'] ?? null, ['seq', 'sneq'], true)) {
            $this->invalid('Unsupported dependency relation.');
        }

        return $rule['relation'] === 'seq' ? $matched : ! $matched;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['options' => $message]);
    }
}
