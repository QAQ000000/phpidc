<?php

declare(strict_types=1);

use App\Actions\CreateLocalCardProductAction;
use App\Actions\ImportRedemptionCodesAction;
use App\Enums\DriverType;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionItem;
use App\Models\UpstreamApi;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Http::preventStrayRequests();
    Http::fake([]);
    Queue::fake();
});

function classicAvailabilityContentProduct(array $attributes = []): Product
{
    $prices = ['monthly' => ['price' => '10.00', 'setup_fee' => '0.00', 'enabled' => true]];

    return Product::factory()->create(array_replace([
        'driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => UpstreamApi::factory()->create()->id,
        'upstream_pid' => '17', 'local_prices' => $prices, 'upstream_base_prices' => $prices,
        'stock_control' => true, 'qty' => 0,
        'provisioning_rules' => ['classic_catalog' => ['product_publisher_uid' => 0]],
    ], $attributes));
}

function classicAvailabilityContentGet(Product $product, string $format): TestResponse
{
    return test()->get($product->publicPath().($format === 'suffix' ? '.md' : ''),
        $format === 'accept' ? ['Accept' => 'text/markdown'] : []);
}

function classicAvailabilityContentNoWrites(): void
{
    Http::assertNothingSent();
    Queue::assertNothingPushed();
    foreach (['invoices', 'instances', 'fulfillment_tasks', 'payment_attempts', 'financial_transactions', 'upstream_operations'] as $table) {
        test()->assertDatabaseCount($table, 0);
    }
}

it('reports synchronized sold-out stock in Markdown without offering a purchase link', function (string $format): void {
    $product = classicAvailabilityContentProduct();
    $response = classicAvailabilityContentGet($product, $format)->assertOk()
        ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')->assertHeader('Vary', 'Accept')
        ->assertSee('最近同步库存：0')->assertSee('最近同步显示售罄')
        ->assertSee('[查看产品详情](', false)->assertDontSee('[查看产品与购买](', false);
    expect($response->getContent())->toContain('Monthly: 10.00 CNY');
    classicAvailabilityContentNoWrites();
})->with(['suffix', 'accept']);

it('restores Markdown purchase links after restocking and distinguishes uncontrolled stock', function (string $format, bool $controlled, int $qty): void {
    $product = classicAvailabilityContentProduct();
    classicAvailabilityContentGet($product, $format)->assertOk()->assertSee('最近同步显示售罄');
    $product->update(['stock_control' => $controlled, 'qty' => $qty]);
    $response = classicAvailabilityContentGet($product, $format)->assertOk()
        ->assertDontSee('售罄')->assertSee('[查看产品与购买](', false)->assertDontSee('[查看产品详情](', false);
    if ($controlled) {
        $response->assertSee('最近同步库存：'.$qty);
    } else {
        $response->assertDontSee('最近同步库存：');
    }
    classicAvailabilityContentNoWrites();
})->with([['suffix', true, 3], ['accept', true, 3], ['suffix', false, 0], ['accept', false, 0]]);

it('shows the same unsupported-sale reason in either Markdown representation', function (string $format, string $case, string $reason): void {
    $product = classicAvailabilityContentProduct(['stock_control' => false]);
    if ($case === 'missing-publisher') {
        $product->update(['provisioning_rules' => ['classic_catalog' => []]]);
    } elseif ($case === 'developer') {
        $product->update(['provisioning_rules' => ['classic_catalog' => ['product_publisher_uid' => 9]]]);
    } else {
        $prices = ['ontrial' => ['price' => '1.00', 'setup_fee' => '0.00', 'enabled' => true]];
        $product->update(['local_prices' => $prices, 'upstream_base_prices' => $prices]);
    }
    classicAvailabilityContentGet($product, $format)->assertOk()->assertSee($reason)
        ->assertSee('[查看产品详情](', false)->assertDontSee('[查看产品与购买](', false)->assertDontSee('- On Trial:', false);
    classicAvailabilityContentNoWrites();
})->with([
    ['suffix', 'missing-publisher', '请先同步完整商品配置，再进行新购。'],
    ['accept', 'missing-publisher', '请先同步完整商品配置，再进行新购。'],
    ['suffix', 'developer', '此商品需要绑定上游主机，当前暂不支持新购。'],
    ['accept', 'developer', '此商品需要绑定上游主机，当前暂不支持新购。'],
    ['suffix', 'trial-only', '上游试用资格与共享次数尚无法完整核验，暂不支持新购试用周期。'],
    ['accept', 'trial-only', '上游试用资格与共享次数尚无法完整核验，暂不支持新购试用周期。'],
]);

it('explains an empty purchase-cycle list in Markdown', function (string $format): void {
    $product = classicAvailabilityContentProduct(['stock_control' => false, 'local_prices' => [], 'upstream_base_prices' => []]);
    classicAvailabilityContentGet($product, $format)->assertOk()->assertSee('暂无可购买的计费周期。')
        ->assertSee('[查看产品详情](', false)->assertDontSee('[查看产品与购买](', false);
    classicAvailabilityContentNoWrites();
})->with(['suffix', 'accept']);

it('labels a zero base price as excluding mandatory configuration charges in HTML and Markdown', function (string $format): void {
    $prices = ['monthly' => ['price' => '0.00', 'setup_fee' => '0.00', 'enabled' => true]];
    $product = classicAvailabilityContentProduct(['stock_control' => false, 'local_prices' => $prices, 'upstream_base_prices' => $prices]);
    $group = ProductOptionGroup::create(['product_id' => $product->id, 'upstream_group_id' => 50, 'name' => 'Required configuration',
        'type' => 'select', 'sync_source' => 'upstream', 'upstream_meta' => ['option_type' => 1]]);
    $optionPrices = ['monthly' => ['price' => '5.00', 'setup_fee' => '0.00', 'enabled' => true]];
    ProductOptionItem::create(['option_group_id' => $group->id, 'upstream_sub_id' => 501, 'label' => 'Required paid option',
        'local_prices' => $optionPrices, 'upstream_base_prices' => $optionPrices]);
    classicAvailabilityContentGet($product, $format)->assertOk()->assertSee('0.00')->assertSee('商品基础价')
        ->assertSee('配置费用以完整报价为准');
    classicAvailabilityContentNoWrites();
})->with(['html', 'suffix', 'accept']);

it('keeps fixed card prices and stock wording free of configurable-product claims', function (string $format): void {
    $product = app(CreateLocalCardProductAction::class)->execute('Fixed card', '12.34', '1.00', 'CNY', 'Create test card');
    app(ImportRedemptionCodesAction::class)->execute($product, ['CARD-CONTENT-FIXTURE'], 'Add test stock');
    classicAvailabilityContentGet($product, $format)->assertOk()->assertSee('12.34')->assertSee('1.00')
        ->assertDontSee('商品基础价')->assertDontSee('配置费用以完整报价为准');
    classicAvailabilityContentNoWrites();
})->with(['html', 'suffix', 'accept']);
