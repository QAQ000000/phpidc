<?php

declare(strict_types=1);

use App\Actions\QuoteLocalProductAction;
use App\Actions\ResolveProductOptionsAction;
use App\Actions\SyncClassicCatalogAction;
use App\Data\QuoteResult;
use App\Enums\DriverType;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\UpstreamApi;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

function localCatalogQuoteProduct(): Product
{
    return Product::factory()->create(['driver_type' => DriverType::ZJMF_API, 'upstream_pid' => '17', 'upstream_version' => 7,
        'upstream_base_prices' => ['monthly' => ['price' => '10.00', 'setup_fee' => '2.00', 'enabled' => true]],
        'local_prices' => ['monthly' => ['price' => '12.00', 'setup_fee' => '2.40', 'enabled' => true]],
        'provisioning_rules' => ['classic_catalog' => ['version' => 7, 'dependencies' => [], 'product_publisher_uid' => 0]]]);
}

function localCatalogQuoteGroup(Product $product, int $type, array $tiers = []): ProductOptionGroup
{
    $quantity = in_array($type, [4, 7, 9, 11, 14, 15, 16, 17, 18, 19], true);
    $tiers = $tiers ?: [['id' => 501, 'qty_minimum' => 0, 'qty_maximum' => 4, 'price' => '2.00', 'setup' => '1.00'],
        ['id' => 502, 'qty_minimum' => 5, 'qty_maximum' => 20, 'price' => '3.00', 'setup' => '5.00']];
    $group = ProductOptionGroup::create(['product_id' => $product->id, 'upstream_group_id' => 50, 'name' => 'Configuration',
        'type' => $quantity ? 'quantity' : ($type === 3 ? 'toggle' : 'select'), 'sync_source' => 'upstream', 'is_hidden' => false,
        'min_value' => 0, 'max_value' => 20, 'step' => 1, 'upstream_meta' => ['option_type' => $type, 'subs' => $tiers]]);
    foreach ($tiers as $tier) {
        $group->items()->create(['upstream_sub_id' => $tier['id'], 'label' => 'Tier '.$tier['id'], 'is_hidden' => false,
            'upstream_base_prices' => ['monthly' => ['price' => $tier['price'], 'setup_fee' => $tier['setup'], 'enabled' => true]],
            'local_prices' => ['monthly' => ['price' => Money::add($tier['price'], Money::percentOf($tier['price'], '20')),
                'setup_fee' => Money::add($tier['setup'], Money::percentOf($tier['setup'], '20')), 'enabled' => true]]]);
    }

    return $group;
}

function localCatalogQuote(Product $product, array $selected = []): QuoteResult
{
    $resolved = app(ResolveProductOptionsAction::class)->execute($product, 'monthly', ['configoption' => $selected], false);

    return app(QuoteLocalProductAction::class)->execute($product, 'monthly', $resolved);
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('estimates entirely from local prices without HTTP or business writes', function (): void {
    $product = localCatalogQuoteProduct();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $quote = localCatalogQuote($product);
    expect($quote->downstreamPrice)->toBe('12.00')->and($quote->setupFee)->toBe('2.40')
        ->and($quote->upstreamBaseCost)->toBe('12.00')->and($quote->currency)->toBe('CNY')
        ->and($quote->breakdown)->toBe(['estimate' => true, 'source' => 'local_catalog', 'catalog_version' => 7]);
    foreach ($queries as $query) {
        expect(str_starts_with(strtolower(ltrim($query)), 'select'))->toBeTrue();
    }
    Http::assertNothingSent();
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('upstream_operations', 0);
});

it('prices ordinary selection types once with their separate setup fee', function (int $type): void {
    $product = localCatalogQuoteProduct();
    localCatalogQuoteGroup($product, $type);
    $quote = localCatalogQuote($product, [50 => 502]);
    expect($quote->downstreamPrice)->toBe('15.60')->and($quote->setupFee)->toBe('8.40')->and($quote->upstreamBaseCost)->toBe('20.00');
})->with([1, 2, 5, 6, 8, 10, 12, 13]);

it('omits both period and setup for a disabled toggle', function (): void {
    $product = localCatalogQuoteProduct();
    localCatalogQuoteGroup($product, 3);
    $off = localCatalogQuote($product, [50 => 0]);
    $on = localCatalogQuote($product, [50 => 501]);
    expect($off->downstreamPrice)->toBe('12.00')->and($off->setupFee)->toBe('2.40')->and($off->upstreamBaseCost)->toBe('12.00')
        ->and($on->downstreamPrice)->toBe('14.40')->and($on->setupFee)->toBe('3.60')->and($on->upstreamBaseCost)->toBe('15.00');
});

it('multiplies all ordinary quantity units by the selected tier and charges setup once', function (int $type): void {
    $product = localCatalogQuoteProduct();
    localCatalogQuoteGroup($product, $type);
    $quote = localCatalogQuote($product, [50 => 7]);
    expect($quote->downstreamPrice)->toBe('37.20')->and($quote->setupFee)->toBe('8.40')->and($quote->upstreamBaseCost)->toBe('38.00');
})->with([4, 7, 9, 11, 14]);

it('accumulates quantity tiers and uses only the current tier setup fee', function (int $type): void {
    $product = localCatalogQuoteProduct();
    localCatalogQuoteGroup($product, $type);
    $quote = localCatalogQuote($product, [50 => 7]);
    expect($quote->downstreamPrice)->toBe('32.40')->and($quote->setupFee)->toBe('8.40')->and($quote->upstreamBaseCost)->toBe('34.00');
})->with([15, 16, 17, 18, 19]);

it('handles tier boundaries without billing an extra zero-indexed unit', function (int $quantity, string $period, string $setup): void {
    $product = localCatalogQuoteProduct();
    localCatalogQuoteGroup($product, 15);
    $quote = localCatalogQuote($product, [50 => $quantity]);
    expect($quote->downstreamPrice)->toBe($period)->and($quote->setupFee)->toBe($setup);
})->with([[0, '12.00', '2.40'], [1, '14.40', '3.60'], [4, '21.60', '3.60'], [5, '25.20', '8.40'], [20, '79.20', '8.40']]);

it('accumulates all complete preceding tiers when they start at one', function (): void {
    $product = localCatalogQuoteProduct();
    localCatalogQuoteGroup($product, 19, [
        ['id' => 501, 'qty_minimum' => 1, 'qty_maximum' => 2, 'price' => '2.00', 'setup' => '9.00'],
        ['id' => 502, 'qty_minimum' => 3, 'qty_maximum' => 5, 'price' => '3.00', 'setup' => '8.00'],
        ['id' => 503, 'qty_minimum' => 6, 'qty_maximum' => 20, 'price' => '4.00', 'setup' => '7.00'],
    ]);
    $quote = localCatalogQuote($product, [50 => 8]);
    expect($quote->downstreamPrice)->toBe('42.00')->and($quote->setupFee)->toBe('10.80')->and($quote->upstreamBaseCost)->toBe('44.00');
});

it('uses stored decimal prices and ignores submitted unit prices', function (): void {
    $product = localCatalogQuoteProduct();
    $group = localCatalogQuoteGroup($product, 4);
    $group->items()->first()->update(['local_prices' => ['monthly' => ['price' => '0.01', 'setup_fee' => '0.00', 'enabled' => true]]]);
    $resolved = app(ResolveProductOptionsAction::class)->execute($product, 'monthly', ['configoption' => [50 => 3]], false);
    $resolved['pairs'][0]['unit_price'] = '0.00';
    $quote = app(QuoteLocalProductAction::class)->execute($product, 'monthly', $resolved);
    expect($quote->downstreamPrice)->toBe('12.03')->and($quote->setupFee)->toBe('2.40');
});

it('rejects missing setup and disabled local price snapshots instead of guessing', function (array $price): void {
    $product = localCatalogQuoteProduct();
    $product->update(['local_prices' => ['monthly' => $price]]);
    expect(fn () => localCatalogQuote($product))->toThrow(ValidationException::class);
})->with([
    [['price' => '12.00', 'enabled' => true]],
    [['price' => '12.00', 'setup_fee' => '0.00', 'enabled' => false]],
]);

it('does not silently estimate an unknown type or an incomplete earlier tier', function (string $change): void {
    $product = localCatalogQuoteProduct();
    $group = localCatalogQuoteGroup($product, 15);
    $resolved = app(ResolveProductOptionsAction::class)->execute($product, 'monthly', ['configoption' => [50 => 7]], false);
    if ($change === 'type') {
        $meta = $group->upstream_meta;
        $meta['option_type'] = 21;
        $group->update(['upstream_meta' => $meta]);
    } else {
        $group->items()->where('upstream_sub_id', 501)->update(['local_prices' => json_encode([])]);
    }
    expect(fn () => app(QuoteLocalProductAction::class)->execute($product, 'monthly', $resolved))->toThrow(ValidationException::class);
})->with(['type', 'tier']);

it('enforces configuration dependencies and quantity bounds before local estimation', function (): void {
    $product = localCatalogQuoteProduct();
    localCatalogQuoteGroup($product, 4);
    $product->update(['provisioning_rules' => ['classic_catalog' => ['product_publisher_uid' => 0, 'dependencies' => [
        ['id' => 1, 'type' => 'condition', 'config_id' => 50, 'relation' => 'seq', 'sub_id' => [501 => ['qty_minimum' => 0, 'qty_maximum' => 20]]],
        ['id' => 2, 'type' => 'result', 'relation_id' => 1, 'config_id' => 50, 'relation' => 'seq', 'sub_id' => [501 => ['qty_minimum' => 6, 'qty_maximum' => 20]]],
    ]]]]);
    expect(fn () => localCatalogQuote($product, [50 => 3]))->toThrow(ValidationException::class);
    expect(fn () => localCatalogQuote($product, [50 => 21]))->toThrow(ValidationException::class);
    expect(localCatalogQuote($product, [50 => 7])->downstreamPrice)->toBe('37.20');
});

it('rejects an incomplete selected option snapshot before resolving its local price', function (): void {
    $product = localCatalogQuoteProduct();
    $group = localCatalogQuoteGroup($product, 1);
    $group->items()->where('upstream_sub_id', 501)->update(['local_prices' => []]);
    expect(fn () => localCatalogQuote($product, [50 => 501]))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('can estimate a local inventory product with no upstream cost', function (): void {
    $product = Product::factory()->create(['upstream_base_prices' => null]);
    $quote = app(QuoteLocalProductAction::class)->execute($product, 'monthly', []);
    expect($quote->downstreamPrice)->toBe('10.00')->and($quote->upstreamBaseCost)->toBe('0.00');
});

it('keeps quantity stage metadata during catalog sync without confusing it with tier pricing', function (): void {
    $account = UpstreamApi::factory()->create();
    $product = localCatalogQuoteProduct();
    $product->update(['upstream_api_id' => $account->id]);
    Http::fake([
        '*/zjmf_api_login' => Http::response(['status' => 200, 'jwt' => 'catalog-token']),
        '*/cart/get_product_config*' => Http::response(['status' => 200, 'data' => [
            'products' => ['id' => 17, 'location_version' => 8, 'p_uid' => 0],
            'product_pricings' => [['code' => 'CNY', 'monthly' => '10.00', 'msetupfee' => '2.00']],
            'config_groups' => [['id' => 10, 'options' => [['id' => 50, 'option_type' => 4, 'qty_stage' => 5,
                'qty_minimum' => 0, 'qty_maximum' => 20, 'sub' => [['id' => 501, 'qty_minimum' => 0, 'qty_maximum' => 20,
                    'pricings' => [['code' => 'CNY', 'monthly' => '2.00', 'msetupfee' => '1.00']]]]]]]],
        ]]),
    ]);
    app(SyncClassicCatalogAction::class)->execute($product, 'Refresh local estimate snapshot');
    $group = $product->optionGroups()->sole();
    expect($group->upstream_meta['qty_stage'])->toBe(5)->and($group->upstream_meta['option_type'])->toBe(4);
    expect(localCatalogQuote($product->fresh(), [50 => 10])->downstreamPrice)->toBe('36.00');
});
