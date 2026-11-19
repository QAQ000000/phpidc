<?php

declare(strict_types=1);

use App\Actions\SyncClassicCatalogAction;
use App\Data\CatalogSnapshotResult;
use App\Enums\DriverType;
use App\Models\Product;
use App\Models\UpstreamApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function classicLinkageSyncOptions(): array
{
    $sub = fn (int $id, int $parent, int $top, string $path): array => [
        'id' => $id, 'option_name' => 'Choice '.$id, 'linkage_pid' => $parent,
        'linkage_top_pid' => $top, 'linkage_level' => $path,
        'pricings' => [['code' => 'CNY', 'monthly' => '1.00', 'msetupfee' => '0.50']],
    ];
    $group = fn (int $id, int $parent, int $top, string $path, array $subs): array => [
        'id' => $id, 'option_name' => 'Layer '.$id, 'option_type' => 20, 'linkage_pid' => $parent,
        'linkage_top_pid' => $top, 'linkage_level' => $path, 'sub' => $subs,
    ];

    return [
        $group(10, 0, 0, '0-10', [$sub(101, 0, 0, '0-101'), $sub(102, 0, 0, '0-102')]),
        $group(20, 10, 10, '0-10-20', [$sub(201, 101, 101, '0-101-201')]),
        $group(21, 10, 10, '0-10-21', [$sub(211, 102, 102, '0-102-211')]),
        $group(30, 20, 10, '0-10-20-30', [$sub(301, 201, 101, '0-101-201-301')]),
        $group(40, 0, 0, '0-40', [$sub(401, 0, 0, '0-401')]),
    ];
}

function classicLinkageSyncSnapshot(int $version = 7, ?array $options = null): CatalogSnapshotResult
{
    return new CatalogSnapshotResult('17', $version,
        ['id' => 17, 'p_uid' => '0', 'location_version' => $version, 'stock_control' => 1, 'qty' => 9],
        [['code' => 'CNY', 'monthly' => '8.00', 'msetupfee' => '2.00']],
        [['id' => 5, 'options' => $options ?? classicLinkageSyncOptions()]],
        [['id' => 80, 'fieldname' => 'Site', 'fieldtype' => 'text', 'showorder' => 1, 'showdetail' => 1]], []);
}

function classicLinkageSyncProduct(): Product
{
    return Product::factory()->create(['driver_type' => DriverType::ZJMF_API,
        'upstream_api_id' => UpstreamApi::factory()->create()->id, 'upstream_pid' => '17',
        'upstream_version' => 0, 'markup_percent' => '20.00']);
}

function classicLinkageSyncApply(Product $product, CatalogSnapshotResult $snapshot, string $entry): void
{
    $action = app(SyncClassicCatalogAction::class);
    if ($entry === 'snapshot') {
        $action->applySnapshot($product, $snapshot, 'Synchronize type20 regression snapshot');

        return;
    }
    Http::fake([
        '*/zjmf_api_login' => Http::response(['status' => 200, 'jwt' => 'classic-linkage-test-token']),
        '*/cart/get_product_config*' => Http::response(['status' => 200, 'data' => [
            'products' => $snapshot->product, 'product_pricings' => $snapshot->prices,
            'config_groups' => $snapshot->optionGroups, 'customfields' => $snapshot->customFields,
            'advanced' => $snapshot->dependencies,
        ]]),
    ]);
    $action->execute($product, 'Synchronize type20 through the pure driver');
}

function classicLinkageSyncStoredState(Product $product): array
{
    return [
        'product' => $product->fresh()->getRawOriginal(),
        'groups' => DB::table('product_option_groups')->where('product_id', $product->id)->orderBy('id')->get()->toJson(),
        'items' => DB::table('product_option_items')->orderBy('id')->get()->toJson(),
        'fields' => DB::table('product_custom_fields')->where('product_id', $product->id)->orderBy('id')->get()->toJson(),
        'audits' => DB::table('audit_logs')->orderBy('id')->get()->toJson(),
    ];
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('persists both complete classic linkage ancestry trees without changing their identifiers', function (string $entry): void {
    $product = classicLinkageSyncProduct();
    $snapshot = classicLinkageSyncSnapshot();
    classicLinkageSyncApply($product, $snapshot, $entry);

    expect($product->fresh()->upstream_version)->toBe(7)
        ->and($product->optionGroups()->count())->toBe(5)
        ->and(DB::table('product_option_items')->count())->toBe(6);
    foreach ($snapshot->optionGroups[0]['options'] as $option) {
        $group = $product->optionGroups()->where('upstream_group_id', $option['id'])->firstOrFail();
        expect($group->upstream_meta)->toMatchArray([
            'option_type' => 20, 'linkage_pid' => $option['linkage_pid'],
            'linkage_top_pid' => $option['linkage_top_pid'], 'linkage_level' => $option['linkage_level'],
        ]);
        foreach ($option['sub'] as $sub) {
            $stored = collect($group->upstream_meta['subs'])->firstWhere('id', $sub['id']);
            expect($stored)->toBe(['id' => $sub['id'], 'linkage_pid' => $sub['linkage_pid'],
                'linkage_top_pid' => $sub['linkage_top_pid'], 'linkage_level' => $sub['linkage_level']]);
            expect($group->items()->where('upstream_sub_id', $sub['id'])->firstOrFail()->local_prices['monthly'])
                ->toBe(['price' => '1.20', 'setup_fee' => '0.60', 'enabled' => true]);
        }
    }
    $this->assertDatabaseHas('audit_logs', ['action' => 'upstream.catalog_sync', 'subject_id' => $product->id]);
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
    $entry === 'snapshot' ? Http::assertNothingSent() : Http::assertSentCount(2);
})->with(['already read snapshot' => ['snapshot'], 'driver configuration response' => ['driver']]);

it('accepts unexpanded optional classic ancestry metadata while preserving authoritative parent ids', function (mixed $empty): void {
    $options = classicLinkageSyncOptions();
    foreach ($options as &$option) {
        foreach (['linkage_top_pid', 'linkage_level'] as $field) {
            if ($empty === 'absent') {
                unset($option[$field]);
            } else {
                $option[$field] = $empty;
            }
            foreach ($option['sub'] as &$sub) {
                if ($empty === 'absent') {
                    unset($sub[$field]);
                } else {
                    $sub[$field] = $empty;
                }
            }
            unset($sub);
        }
    }
    unset($option);
    $product = classicLinkageSyncProduct();
    classicLinkageSyncApply($product, classicLinkageSyncSnapshot(options: $options), 'snapshot');
    $group = $product->optionGroups()->where('upstream_group_id', 30)->firstOrFail();

    expect($group->upstream_meta['linkage_pid'])->toBe(20)
        ->and($group->upstream_meta['subs'][0]['linkage_pid'])->toBe(201);
    foreach (['linkage_top_pid', 'linkage_level'] as $field) {
        if ($empty === 'absent') {
            expect(array_key_exists($field, $group->upstream_meta))->toBeFalse()
                ->and(array_key_exists($field, $group->upstream_meta['subs'][0]))->toBeFalse();
        } else {
            expect($group->upstream_meta[$field])->toBe($empty)
                ->and($group->upstream_meta['subs'][0][$field])->toBe($empty);
        }
    }
    Http::assertNothingSent();
})->with(['legacy schema string zero' => ['0'], 'integer zero' => [0], 'empty string' => [''],
    'null value' => [null], 'omitted optional fields' => ['absent']]);

it('atomically preserves the entire prior catalog when a classic linkage update is invalid', function (string $case, string $entry): void {
    $product = classicLinkageSyncProduct();
    classicLinkageSyncApply($product, classicLinkageSyncSnapshot(), 'snapshot');
    $product->update(['is_hidden' => true]);
    $before = classicLinkageSyncStoredState($product);
    $options = classicLinkageSyncOptions();
    $expected = match ($case) {
        'unknown type21' => 'Unsupported upstream option identifier or type.',
        'orphan group', 'group cycle' => 'Classic linkage trees cannot contain missing parents or cycles.',
        'orphan suboption', 'cross group parent suboption', 'cross root parent suboption' => 'Classic linkage suboptions must follow the immediate parent group.',
        'incorrect group top', 'incorrect suboption top' => 'Classic linkage root does not match its parent tree.',
        'incorrect group path', 'incorrect suboption path' => 'Classic linkage path does not match its parent tree.',
        'ambiguous child groups' => 'Multiple child groups for one classic linkage suboption are not supported.',
        'missing parent id' => 'Classic linkage identifiers must be explicit nonnegative integers.',
    };
    switch ($case) {
        case 'unknown type21':
            $options[4]['option_type'] = 21;
            break;
        case 'orphan group':
            $options[1]['linkage_pid'] = 999;
            break;
        case 'group cycle':
            $options[0]['linkage_pid'] = 20;
            break;
        case 'orphan suboption':
            $options[1]['sub'][0]['linkage_pid'] = 999;
            break;
        case 'cross group parent suboption':
            $options[3]['sub'][0]['linkage_pid'] = 101;
            break;
        case 'cross root parent suboption':
            $options[1]['sub'][0]['linkage_pid'] = 401;
            break;
        case 'incorrect group top':
            $options[1]['linkage_top_pid'] = 40;
            break;
        case 'incorrect suboption top':
            $options[1]['sub'][0]['linkage_top_pid'] = 401;
            break;
        case 'incorrect group path':
            $options[1]['linkage_level'] = '0-20';
            break;
        case 'incorrect suboption path':
            $options[1]['sub'][0]['linkage_level'] = '0-201';
            break;
        case 'ambiguous child groups':
            $options[2]['sub'][0]['linkage_pid'] = 101;
            $options[2]['sub'][0]['linkage_top_pid'] = 101;
            $options[2]['sub'][0]['linkage_level'] = '0-101-211';
            break;
        case 'missing parent id':
            unset($options[1]['linkage_pid']);
            break;
    }
    $snapshot = classicLinkageSyncSnapshot(8, $options);

    expect(fn () => classicLinkageSyncApply($product, $snapshot, $entry))->toThrow(DomainException::class, $expected);
    expect(classicLinkageSyncStoredState($product))->toBe($before);
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
    $entry === 'snapshot' ? Http::assertNothingSent() : Http::assertSentCount(2);
})->with(['unknown type21', 'orphan group', 'group cycle', 'orphan suboption', 'cross group parent suboption',
    'cross root parent suboption', 'incorrect group top', 'incorrect suboption top', 'incorrect group path',
    'incorrect suboption path', 'ambiguous child groups', 'missing parent id'])
    ->with(['already read snapshot' => ['snapshot'], 'driver configuration response' => ['driver']]);
