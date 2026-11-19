<?php

declare(strict_types=1);

use App\Models\ProductOptionGroup;
use App\Models\ProductOptionItem;
use App\Support\ClassicOptionLinkage;
use Illuminate\Database\Eloquent\Collection;

function classicLinkageTreeOptions(): array
{
    $group = fn (int $id, int $parent, array $subs): array => [
        'id' => $id, 'option_type' => 20, 'linkage_pid' => $parent, 'sub' => array_map(
            fn (array $sub): array => ['id' => $sub[0], 'linkage_pid' => $sub[1]], $subs),
    ];

    return [$group(10, 0, [[101, 0], [102, 0]]), $group(20, 10, [[201, 101], [202, 101]]),
        $group(21, 10, [[211, 102]]), $group(30, 20, [[301, 201], [302, 202]]),
        $group(40, 0, [[401, 0]])];
}

function classicLinkageTreeGroups(array $options, array $hidden = []): array
{
    return array_map(function (array $option) use ($hidden): ProductOptionGroup {
        $meta = $option;
        $meta['subs'] = $meta['sub'];
        unset($meta['sub']);
        $group = new ProductOptionGroup(['upstream_group_id' => $option['id'], 'sort_order' => 0,
            'is_hidden' => false, 'upstream_meta' => $meta]);
        $group->setRelation('items', new Collection(array_map(fn (array $sub): ProductOptionItem => new ProductOptionItem([
            'upstream_sub_id' => $sub['id'], 'is_hidden' => in_array($sub['id'], $hidden, true),
            'sort_order' => 0,
        ]), $option['sub'])));

        return $group;
    }, $options);
}

it('resolves a complete three level branch and independent root with only active choices', function (): void {
    $result = ClassicOptionLinkage::fromOptions(classicLinkageTreeOptions())->resolve([10 => 101, 20 => 202, 30 => 302, 40 => 401]);

    expect($result)->toBe(['active_group_ids' => [10, 20, 30, 40],
        'configoption' => [10 => 101, 20 => 202, 30 => 302, 40 => 401],
        'available_sub_ids' => [10 => [101, 102], 20 => [201, 202], 30 => [302], 40 => [401]]]);
});

it('projects visible items without requiring hidden metadata items to remain loaded', function (): void {
    $groups = classicLinkageTreeGroups(classicLinkageTreeOptions(), [102, 211]);
    foreach ($groups as $group) {
        $group->setRelation('items', $group->items->where('is_hidden', false)->values());
    }
    $result = ClassicOptionLinkage::fromGroups($groups)->resolve([10 => 101, 20 => 201, 30 => 301, 40 => 401]);

    expect($result['configoption'])->toBe([10 => 101, 20 => 201, 30 => 301, 40 => 401])
        ->and($result['available_sub_ids'])->toBe([10 => [101], 20 => [201, 202], 30 => [301], 40 => [401]]);
    expect(fn () => ClassicOptionLinkage::fromGroups($groups)->resolve([10 => 102, 21 => 211, 40 => 401]))
        ->toThrow(DomainException::class, 'Select a valid suboption');
});

it('does not reject a sellable branch because an unreachable hidden parent has visible descendants', function (): void {
    $tree = ClassicOptionLinkage::fromGroups(classicLinkageTreeGroups(classicLinkageTreeOptions(), [101]));

    expect($tree->resolve([10 => 102, 21 => 211, 40 => 401])['configoption'])->toBe([10 => 102, 21 => 211, 40 => 401]);
    expect(fn () => $tree->resolve([10 => 102, 20 => 201, 21 => 211, 30 => 301, 40 => 401]))
        ->toThrow(DomainException::class, 'Do not submit inactive');
});

it('keeps required successor layers when every child item in a selected branch is hidden', function (): void {
    $tree = ClassicOptionLinkage::fromGroups(classicLinkageTreeGroups(classicLinkageTreeOptions(), [201, 202]));

    expect(fn () => $tree->resolve([10 => 101, 40 => 401]))
        ->toThrow(DomainException::class, 'An active classic linkage group has no available suboptions.');
    expect($tree->resolve([10 => 102, 21 => 211, 40 => 401])['active_group_ids'])->toBe([10, 21, 40]);
});

it('uses local visible item ordering for defaults after metadata validation', function (): void {
    $groups = classicLinkageTreeGroups(classicLinkageTreeOptions());
    $groups[0]->items->firstWhere('upstream_sub_id', 101)->sort_order = 9;
    $groups[1]->items->firstWhere('upstream_sub_id', 201)->sort_order = 9;
    $tree = ClassicOptionLinkage::fromGroups($groups);

    expect($tree->resolve([], defaults: true)['configoption'])->toBe([10 => 102, 21 => 211, 40 => 401])
        ->and($tree->resolve([10 => 101], defaults: true)['configoption'])->toBe([10 => 101, 20 => 202, 30 => 302, 40 => 401]);
});

it('validates hidden metadata ancestry before applying visibility', function (): void {
    $options = classicLinkageTreeOptions();
    $options[1]['sub'][0]['linkage_pid'] = 999;

    expect(fn () => ClassicOptionLinkage::fromGroups(classicLinkageTreeGroups($options, [201])))
        ->toThrow(DomainException::class, 'Classic linkage suboptions must follow the immediate parent group.');
});

it('refuses visible items missing from their own group metadata', function (bool $duplicate): void {
    $groups = classicLinkageTreeGroups(classicLinkageTreeOptions());
    $groups[0]->items->push(new ProductOptionItem(['upstream_sub_id' => $duplicate ? 101 : 201,
        'is_hidden' => false, 'sort_order' => 0]));

    expect(fn () => ClassicOptionLinkage::fromGroups($groups))
        ->toThrow(DomainException::class, 'Classic linkage metadata does not cover the local suboptions.');
})->with([false, true]);

it('refuses child groups whose required parent group is unavailable', function (): void {
    $groups = classicLinkageTreeGroups(classicLinkageTreeOptions());
    unset($groups[0]);

    expect(fn () => ClassicOptionLinkage::fromGroups($groups))
        ->toThrow(DomainException::class, 'Classic linkage trees cannot contain missing parents or cycles.');
});
