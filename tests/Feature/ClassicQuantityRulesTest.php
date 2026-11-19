<?php

declare(strict_types=1);

use App\Actions\QuoteLocalProductAction;
use App\Actions\ResolveProductOptionsAction;
use App\Actions\SyncClassicCatalogAction;
use App\Enums\DriverType;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\UpstreamApi;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

function classicQuantityRulesProduct(array $quantity = []): Product
{
    $product = Product::factory()->create(['driver_type' => DriverType::ZJMF_API,
        'upstream_api_id' => UpstreamApi::factory()->create()->id, 'upstream_pid' => '17', 'markup_percent' => '0.00']);
    Http::fake([
        '*/zjmf_api_login' => Http::response(['status' => 200, 'jwt' => 'quantity-rules-test-token']),
        '*/cart/get_product_config*' => Http::response(['status' => 200, 'data' => [
            'products' => ['id' => 17, 'location_version' => 1, 'p_uid' => 0],
            'product_pricings' => [['code' => 'CNY', 'monthly' => '10.00', 'msetupfee' => '0.00']],
            'config_groups' => [['id' => 10, 'options' => [array_replace([
                'id' => 50, 'option_name' => 'Disk', 'option_type' => 4, 'qty_minimum' => 1, 'qty_maximum' => 13,
                'sub' => [['id' => 501, 'qty_minimum' => 0, 'qty_maximum' => 20,
                    'pricings' => [['code' => 'CNY', 'monthly' => '2.00', 'msetupfee' => '1.00']]]],
            ], $quantity)]]],
        ]]),
    ]);
    app(SyncClassicCatalogAction::class)->execute($product, 'Synchronize quantity regression fixture');

    return $product->fresh();
}

function classicQuantityRulesResolve(Product $product, int $quantity, array $other = []): array
{
    return app(ResolveProductOptionsAction::class)->execute($product, 'monthly', ['configoption' => [50 => $quantity] + $other], false);
}

function classicQuantityRulesChoice(Product $product, string $type = 'select'): void
{
    $group = ProductOptionGroup::create(['product_id' => $product->id, 'upstream_group_id' => 60, 'name' => 'Region',
        'type' => $type, 'sync_source' => 'upstream', 'is_hidden' => false, 'upstream_meta' => ['option_type' => $type === 'toggle' ? 3 : 1]]);
    $prices = ['monthly' => ['enabled' => true, 'price' => '0.00', 'setup_fee' => '0.00']];
    foreach ($type === 'toggle' ? [601] : [601, 602] as $id) {
        $group->items()->create(['upstream_sub_id' => $id, 'label' => 'Choice '.$id, 'is_hidden' => false,
            'local_prices' => $prices, 'upstream_base_prices' => $prices]);
    }
}

function classicQuantityRulesDependencies(Product $product, array $condition, array $result): void
{
    $rules = $product->provisioning_rules;
    $rules['classic_catalog']['dependencies'] = [
        ['id' => 1, 'type' => 'condition'] + $condition + ['relation' => 'seq'],
        ['id' => 2, 'type' => 'result', 'relation_id' => 1] + $result + ['relation' => 'seq'],
    ];
    $product->update(['provisioning_rules' => $rules]);
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('accepts classic absolute stage multiples and nonmultiple endpoints after sync', function (int $minimum, int $maximum, int $quantity, string $period): void {
    $product = classicQuantityRulesProduct(['qty_stage' => 5, 'qty_minimum' => $minimum, 'qty_maximum' => $maximum]);
    $resolved = classicQuantityRulesResolve($product, $quantity);
    $quote = app(QuoteLocalProductAction::class)->execute($product, 'monthly', $resolved);
    expect($resolved['configoption'])->toBe([50 => $quantity])
        ->and($resolved['pairs'][0]['qty'])->toBe($quantity)
        ->and($quote->downstreamPrice)->toBe($period)->and($quote->setupFee)->toBe('1.00');
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
})->with([[1, 13, 1, '12.00'], [1, 13, 5, '20.00'], [1, 13, 10, '30.00'], [1, 13, 13, '36.00'],
    [10, 20, 10, '30.00'], [10, 20, 15, '40.00'], [10, 20, 20, '50.00']]);

it('rejects offset-only classic quantities and out of range endpoints', function (int $quantity): void {
    $product = classicQuantityRulesProduct(['qty_stage' => 5]);
    expect(fn () => classicQuantityRulesResolve($product, $quantity))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
})->with([0, 2, 6, 11, 14]);

it('treats classic zero and one stages as unrestricted integer steps', function (int $stage): void {
    $product = classicQuantityRulesProduct(['qty_stage' => $stage, 'qty_step' => 5]);
    expect(classicQuantityRulesResolve($product, 7)['configoption'])->toBe([50 => 7]);
})->with([0, 1]);

it('retains the existing offset qty_step contract when classic stage is absent', function (): void {
    $product = classicQuantityRulesProduct(['qty_step' => 5]);
    foreach ([1, 6, 11] as $quantity) {
        expect(classicQuantityRulesResolve($product, $quantity)['pairs'][0]['qty'])->toBe($quantity);
    }
    foreach ([5, 10, 13] as $quantity) {
        expect(fn () => classicQuantityRulesResolve($product, $quantity))->toThrow(ValidationException::class);
    }
});

it('applies a zero quantity condition instead of comparing its suboption id', function (string $relation): void {
    $product = classicQuantityRulesProduct(['qty_minimum' => 0, 'qty_stage' => 1]);
    classicQuantityRulesChoice($product);
    classicQuantityRulesDependencies($product,
        ['config_id' => 50, 'relation' => $relation, 'sub_id' => [501 => ['qty_minimum' => 0, 'qty_maximum' => 0]]],
        ['config_id' => 60, 'sub_id' => [602 => ['qty_minimum' => 0, 'qty_maximum' => 0]]]);
    $matching = $relation === 'seq' ? 0 : 1;
    $nonmatching = $relation === 'seq' ? 1 : 0;
    expect(fn () => classicQuantityRulesResolve($product, $matching, [60 => 601]))->toThrow(ValidationException::class);
    expect(classicQuantityRulesResolve($product, $matching, [60 => 602])['configoption'])->toBe([50 => $matching, 60 => 602]);
    expect(classicQuantityRulesResolve($product, $nonmatching, [60 => 601])['configoption'])->toBe([50 => $nonmatching, 60 => 601]);
})->with(['seq', 'sneq']);

it('applies a zero quantity result while select and toggle rules still match suboption ids', function (string $type, string $relation): void {
    $product = classicQuantityRulesProduct(['qty_minimum' => 0, 'qty_stage' => 1]);
    classicQuantityRulesChoice($product, $type);
    classicQuantityRulesDependencies($product,
        ['config_id' => 60, 'sub_id' => [601 => ['qty_minimum' => 0, 'qty_maximum' => 0]]],
        ['config_id' => 50, 'relation' => $relation, 'sub_id' => [501 => ['qty_minimum' => 0, 'qty_maximum' => 0]]]);
    $matching = $relation === 'seq' ? 0 : 1;
    $nonmatching = $relation === 'seq' ? 1 : 0;
    expect(classicQuantityRulesResolve($product, $matching, [60 => 601])['configoption'])->toBe([50 => $matching, 60 => 601]);
    expect(fn () => classicQuantityRulesResolve($product, $nonmatching, [60 => 601]))->toThrow(ValidationException::class);
    $unselected = $type === 'toggle' ? 0 : 602;
    expect(classicQuantityRulesResolve($product, $nonmatching, [60 => $unselected])['configoption'])->toBe([50 => $nonmatching, 60 => $unselected]);
})->with([['select', 'seq'], ['select', 'sneq'], ['toggle', 'seq'], ['toggle', 'sneq']]);

it('renders classic stages without imposing native minimum-offset step validation', function (): void {
    $product = classicQuantityRulesProduct(['qty_stage' => 5, 'qty_step' => 5]);
    $html = view('sales.config-options', ['product' => $product, 'selection' => [], 'serviceChange' => true])->render();
    $document = new DOMDocument;
    $document->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $input = $document->getElementsByTagName('input')->item(0);
    expect($input)->toBeInstanceOf(DOMElement::class)
        ->and($input->getAttribute('data-option-type'))->toBe('quantity')
        ->and($input->getAttribute('data-qty-stage'))->toBe('5')
        ->and($input->getAttribute('step'))->toBe('1')->and($input->getAttribute('min'))->toBe('1')
        ->and($html)->toContain('最小值 1、最大值 13');
});

it('keeps native offset step validation for quantity fields without a classic stage', function (): void {
    $product = classicQuantityRulesProduct(['qty_step' => 5]);
    $html = view('sales.config-options', ['product' => $product, 'selection' => [], 'serviceChange' => true])->render();
    $document = new DOMDocument;
    $document->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $input = $document->getElementsByTagName('input')->item(0);
    expect($input)->toBeInstanceOf(DOMElement::class)->and($input->hasAttribute('data-qty-stage'))->toBeFalse()
        ->and($input->getAttribute('step'))->toBe('5');
});
