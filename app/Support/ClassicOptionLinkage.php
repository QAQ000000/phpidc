<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ProductOptionGroup;
use DomainException;

/** Classic type 20 has separate group and suboption trees, both keyed by upstream IDs. */
class ClassicOptionLinkage
{
    private array $groups = [];

    private array $subs = [];

    private array $roots = [];

    private array $choices = [];

    private array $next = [];

    public static function fromOptions(array $options): self
    {
        $tree = new self;
        foreach ($options as $option) {
            if ((int) ($option['option_type'] ?? 0) !== 20) {
                continue;
            }
            $id = $tree->integer($option['id'] ?? null, 1);
            if (isset($tree->groups[$id])) {
                throw new DomainException('Duplicate classic linkage group.');
            }
            $parent = $tree->integer($option['linkage_pid'] ?? null);
            $tree->groups[$id] = ['id' => $id, 'parent' => $parent, 'order' => (int) ($option['order'] ?? 0),
                'top' => $option['linkage_top_pid'] ?? null, 'path' => $option['linkage_level'] ?? null];
            if ($parent === 0) {
                $tree->roots[] = $id;
            }
            if (! is_array($option['sub'] ?? null) || $option['sub'] === []) {
                throw new DomainException('Classic linkage groups require visible suboptions.');
            }
            foreach ($option['sub'] as $sub) {
                $subId = $tree->integer($sub['id'] ?? null, 1);
                if (isset($tree->subs[$subId])) {
                    throw new DomainException('Duplicate classic linkage suboption.');
                }
                $subParent = $tree->integer($sub['linkage_pid'] ?? null);
                $tree->subs[$subId] = ['id' => $subId, 'group' => $id, 'parent' => $subParent,
                    'order' => (int) ($sub['sort_order'] ?? 0), 'top' => $sub['linkage_top_pid'] ?? null,
                    'path' => $sub['linkage_level'] ?? null];
                $tree->choices[$id][$subParent][] = $subId;
            }
        }
        $tree->validate();

        return $tree;
    }

    /** @param iterable<ProductOptionGroup> $groups */
    public static function fromGroups(iterable $groups): self
    {
        $options = [];
        $visibleItems = [];
        foreach ($groups as $group) {
            $meta = $group->upstream_meta ?? [];
            if ((int) ($meta['option_type'] ?? 0) !== 20) {
                continue;
            }
            if (! $group->relationLoaded('items')) {
                throw new DomainException('Load classic linkage suboptions before resolving the tree.');
            }
            $visibleItems[] = [$group->upstream_group_id, $group->items->where('is_hidden', false)];
            $options[] = array_replace($meta, ['id' => (int) $group->upstream_group_id,
                'order' => (int) $group->sort_order, 'sub' => $meta['subs'] ?? []]);
        }

        // Validate the full snapshot first: hiding an item must not mask a malformed tree.
        $tree = self::fromOptions($options);
        $visible = [];
        foreach ($visibleItems as [$groupId, $items]) {
            foreach ($items as $item) {
                $id = $tree->integer($item->upstream_sub_id, 1);
                if (! isset($tree->subs[$id]) || $tree->subs[$id]['group'] !== (int) $groupId || isset($visible[$id])) {
                    throw new DomainException('Classic linkage metadata does not cover the local suboptions.');
                }
                $visible[$id] = true;
                $tree->subs[$id]['order'] = (int) $item->sort_order;
            }
        }
        foreach ($tree->choices as &$parents) {
            foreach ($parents as &$choices) {
                $choices = array_values(array_filter($choices, fn (int $id): bool => isset($visible[$id])));
            }
        }
        unset($parents, $choices);
        // Keep next even when all children are hidden: a required priced layer cannot become a free leaf.
        $tree->sortChoices();

        return $tree;
    }

    /** @return array{active_group_ids: list<int>, configoption: array<int, int>, available_sub_ids: array<int, list<int>>} */
    public function resolve(array $selected, bool $defaults = false): array
    {
        $result = ['active_group_ids' => [], 'configoption' => [], 'available_sub_ids' => []];
        $visit = function (int $id, int $parentSub) use (&$visit, &$result, $selected, $defaults): void {
            $available = $this->choices[$id][$parentSub] ?? [];
            if ($available === []) {
                throw new DomainException('An active classic linkage group has no available suboptions.');
            }
            $value = $selected[$id] ?? null;
            $valid = (is_int($value) || is_string($value)) && filter_var($value, FILTER_VALIDATE_INT) !== false
                && in_array((int) $value, $available, true);
            if (! $valid && ! $defaults) {
                throw new DomainException('Select a valid suboption for every active classic linkage group.');
            }
            $value = $valid ? (int) $value : $available[0];
            $result['active_group_ids'][] = $id;
            $result['configoption'][$id] = $value;
            $result['available_sub_ids'][$id] = $available;
            if (isset($this->next[$value])) {
                $visit($this->next[$value], $value);
            }
        };
        foreach ($this->roots as $root) {
            $visit($root, 0);
        }
        if (! $defaults) {
            foreach (array_keys($selected) as $id) {
                if (isset($this->groups[$id]) && ! isset($result['configoption'][$id])) {
                    throw new DomainException('Do not submit inactive classic linkage groups.');
                }
            }
        }

        return $result;
    }

    private function validate(): void
    {
        foreach ($this->groups as $group) {
            $path = $this->path($group['id'], $this->groups);
            $this->checkAncestry($group, $path);
        }
        foreach ($this->subs as $sub) {
            $parentGroup = $this->groups[$sub['group']]['parent'];
            if (($parentGroup === 0 && $sub['parent'] !== 0)
                || ($parentGroup !== 0 && (! isset($this->subs[$sub['parent']])
                    || $this->subs[$sub['parent']]['group'] !== $parentGroup))) {
                throw new DomainException('Classic linkage suboptions must follow the immediate parent group.');
            }
            $path = $this->path($sub['id'], $this->subs);
            $this->checkAncestry($sub, $path);
            if ($sub['parent'] !== 0) {
                if (isset($this->next[$sub['parent']]) && $this->next[$sub['parent']] !== $sub['group']) {
                    throw new DomainException('Multiple child groups for one classic linkage suboption are not supported.');
                }
                $this->next[$sub['parent']] = $sub['group'];
            }
        }
        $this->sortChoices();
    }

    private function sortChoices(): void
    {
        usort($this->roots, fn (int $a, int $b) => [$this->groups[$a]['order'], $a] <=> [$this->groups[$b]['order'], $b]);
        foreach ($this->choices as &$parents) {
            foreach ($parents as &$choices) {
                usort($choices, fn (int $a, int $b) => [$this->subs[$a]['order'], $a] <=> [$this->subs[$b]['order'], $b]);
            }
        }
        unset($parents, $choices);
    }

    private function path(int $id, array $nodes): array
    {
        $path = [];
        while ($id !== 0) {
            if (! isset($nodes[$id]) || isset($path[$id])) {
                throw new DomainException('Classic linkage trees cannot contain missing parents or cycles.');
            }
            $path[$id] = $id;
            $id = $nodes[$id]['parent'];
        }

        return array_reverse(array_values($path));
    }

    private function checkAncestry(array $node, array $path): void
    {
        // Some classic APIs leave the redundant top/path fields empty; pid remains authoritative.
        if ($node['top'] !== null && $node['top'] !== '') {
            $top = $this->integer($node['top']);
            if ($top !== 0 && $top !== (count($path) === 1 ? 0 : $path[0])) {
                throw new DomainException('Classic linkage root does not match its parent tree.');
            }
        }
        // Classic's schema defaults linkage_level to '0' before its redundant path is expanded.
        if (! in_array($node['path'], [null, '', '0', 0], true) && $node['path'] !== '0-'.implode('-', $path)) {
            throw new DomainException('Classic linkage path does not match its parent tree.');
        }
    }

    private function integer(mixed $value, int $minimum = 0): int
    {
        if ((! is_int($value) && ! is_string($value))
            || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum]]) === false) {
            throw new DomainException('Classic linkage identifiers must be explicit nonnegative integers.');
        }

        return (int) $value;
    }
}
