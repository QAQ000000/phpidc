<?php

declare(strict_types=1);

use App\Actions\DescribeStoreProductAction;
use App\Actions\ProbeClassicCatalogAction;
use App\Enums\DriverType;
use App\Models\Product;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Support\ClassicPurchaseAdmission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Http::preventStrayRequests();
    Http::fake([]);
    Queue::fake();
});

function classicStockPresentationProduct(array $attributes = []): Product
{
    $prices = ['monthly' => ['price' => '10.00', 'setup_fee' => '0.00', 'enabled' => true]];

    return Product::factory()->create(array_replace([
        'driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => UpstreamApi::factory()->create()->id,
        'upstream_pid' => '17', 'upstream_version' => 7, 'markup_percent' => '0.00',
        'stock_control' => true, 'qty' => 0, 'upstream_base_prices' => $prices, 'local_prices' => $prices,
        'provisioning_rules' => ['classic_catalog' => ['version' => 7, 'product_publisher_uid' => 0, 'dependencies' => [],
            'host' => ['show' => 0], 'password' => ['show' => 0], 'pay_type' => ['pay_type' => 'recurring']]],
    ], $attributes));
}

function fakeClassicStockPresentationProbe(Product $product, bool $controlled, int $qty, bool $allowFullRead): stdClass
{
    $state = (object) ['paths' => []];
    Http::fake(function ($request) use ($product, $controlled, $qty, $allowFullRead, $state) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $state->paths[] = $path;

        return Http::response(match ($path) {
            '/zjmf_api_login' => ['status' => 200, 'jwt' => 'stock-presentation-jwt'],
            '/api/product/proinfo' => ['status' => 200, 'data' => ['info' => [['id' => (int) $product->upstream_pid,
                'location_version' => 7, 'stock_control' => $controlled ? 1 : 0, 'qty' => $qty]]]],
            '/cart/get_product_config' => $allowFullRead ? ['status' => 200, 'data' => [
                'products' => ['id' => (int) $product->upstream_pid, 'p_uid' => '0', 'name' => $product->name,
                    'location_version' => 7, 'stock_control' => $controlled ? 1 : 0, 'qty' => $qty,
                    'host' => ['show' => 0], 'password' => ['show' => 0], 'pay_type' => ['pay_type' => 'recurring']],
                'product_pricings' => [['code' => 'CNY', 'monthly' => '10.00', 'msetupfee' => '0.00']],
                'config_groups' => [], 'customfields' => [], 'advanced' => [],
            ]] : throw new RuntimeException('An unchanged complete catalog must not be read again.'),
            default => throw new RuntimeException('Unexpected stock presentation request: '.$path),
        });
    });

    return $state;
}

it('shows synchronized sold-out stock without a purchase entry or supplier request on either page', function (): void {
    $product = classicStockPresentationProduct();
    $this->actingAs(User::factory()->create())->get($product->publicPath())->assertOk()
        ->assertSee('最近同步库存：0')->assertSee('最近同步显示售罄')
        ->assertDontSee('href="/console/products/'.$product->id.'/configure"', false);
    $this->get('/console/products/'.$product->id.'/configure')->assertOk()->assertSee('最近同步显示售罄')
        ->assertDontSee('id="classic-configuration"', false)->assertDontSee('action="/checkout"', false);
    Http::assertNothingSent();
    $this->assertDatabaseCount('upstream_operations', 0);
    $this->assertDatabaseCount('invoices', 0);
    Queue::assertNothingPushed();
});

it('restores purchase entries after replenishment and treats uncontrolled zero quantity as available', function (bool $controlled, int $qty, ?int $expectedStock): void {
    $product = classicStockPresentationProduct();
    $this->actingAs(User::factory()->create())->get('/console/products/'.$product->id.'/configure')->assertOk()
        ->assertSee('最近同步显示售罄')->assertDontSee('id="classic-configuration"', false);
    $product->update(['stock_control' => $controlled, 'qty' => $qty]);
    $this->get($product->publicPath())->assertOk()->assertDontSee('最近同步显示售罄')
        ->assertSee('href="/console/products/'.$product->id.'/configure"', false);
    $this->get('/console/products/'.$product->id.'/configure')->assertOk()->assertDontSee('最近同步显示售罄')
        ->assertSee('id="classic-configuration"', false)->assertSee('action="/checkout"', false);
    expect(app(DescribeStoreProductAction::class)->execute($product->fresh())['stock'])->toBe($expectedStock);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
})->with([[true, 3, 3], [false, 0, null]]);

it('explains unsupported new sales without offering a purchase form on either page', function (string $case, string $reason): void {
    $product = classicStockPresentationProduct(['stock_control' => false]);
    $rules = $product->provisioning_rules;
    if ($case === 'missing-publisher') {
        unset($rules['classic_catalog']['product_publisher_uid']);
    } elseif ($case === 'developer') {
        $rules['classic_catalog']['product_publisher_uid'] = 9;
    } else {
        $prices = ['ontrial' => ['price' => '1.00', 'setup_fee' => '0.00', 'enabled' => true]];
        $product->update(['local_prices' => $prices, 'upstream_base_prices' => $prices]);
    }
    $product->update(['provisioning_rules' => $rules]);

    $this->actingAs(User::factory()->create())->get($product->publicPath())->assertOk()->assertSee($reason)
        ->assertDontSee('href="/console/products/'.$product->id.'/configure"', false);
    $this->get('/console/products/'.$product->id.'/configure')->assertOk()->assertSee($reason)
        ->assertDontSee('id="classic-configuration"', false)->assertDontSee('action="/checkout"', false);
    Http::assertNothingSent();
    $this->assertDatabaseCount('invoices', 0);
    Queue::assertNothingPushed();
})->with([
    ['missing-publisher', '请先同步完整商品配置，再进行新购。'],
    ['developer', '此商品需要绑定上游主机，当前暂不支持新购。'],
    ['trial-only', '上游试用资格与共享次数尚无法完整核验，暂不支持新购试用周期。'],
]);

it('keeps a supported monthly cycle available while removing only the trial cycle from both pages', function (): void {
    $product = classicStockPresentationProduct(['stock_control' => false]);
    $prices = $product->local_prices + ['ontrial' => ['price' => '1.00', 'setup_fee' => '0.00', 'enabled' => true]];
    $product->update(['local_prices' => $prices, 'upstream_base_prices' => $prices]);
    $public = $this->actingAs(User::factory()->create())->get($product->publicPath())->assertOk()
        ->assertSee('href="/console/products/'.$product->id.'/configure"', false)->assertDontSee('暂不支持新购试用周期');
    expect(array_column($public->viewData('cycles'), 'value'))->toBe(['monthly']);
    $configure = $this->get('/console/products/'.$product->id.'/configure')->assertOk()
        ->assertSee('<option value="monthly"', false)->assertDontSee('<option value="ontrial"', false)
        ->assertSee('id="classic-configuration"', false);
    expect(array_column($configure->viewData('details')['cycles'], 'value'))->toBe(['monthly']);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('still checks live upstream stock when a customer bypasses the cached purchase form', function (int $cachedQty): void {
    $product = classicStockPresentationProduct(['qty' => $cachedQty]);
    $user = User::factory()->create(['credit' => '100.00']);
    $paths = [];
    Http::fake(function ($request) use (&$paths) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $paths[] = $path;

        return Http::response(match ($path) {
            '/zjmf_api_login' => ['status' => 200, 'jwt' => 'stock-checkout-jwt'],
            '/cart/get_product_config' => ['status' => 200, 'data' => ['products' => ['host' => ['show' => 0], 'password' => ['show' => 0]]]],
            '/user_info' => ['status' => 200, 'user' => ['currency' => 1]],
            '/cart/stock_control' => ['status' => 200, 'data' => ['product' => ['hidden' => 0, 'stock_control' => 1, 'qty' => 0]]],
            default => throw new RuntimeException('Out-of-stock checkout must stop before price quotation or procurement: '.$path),
        });
    });

    $this->actingAs($user)->postJson('/checkout', ['product_id' => $product->id, 'request_id' => (string) Str::ulid(),
        'billing_cycle' => 'monthly', 'hostname' => 'stock-test-host', 'password' => 'Test-Password12', 'use_credit' => true,
        'gateway' => 'epay_wechat', 'options' => []])->assertUnprocessable()->assertJsonValidationErrors('product_id')
        ->assertJsonPath('errors.product_id.0', 'Upstream product is unavailable or out of stock.');
    expect($paths)->toContain('/cart/stock_control')->not->toContain('/cart/get_total', '/cart/add_to_shop', '/cart/settle', '/apply_credit');
    expect($user->fresh()->credit)->toBe('100.00')->and($user->fresh()->frozen_credit)->toBe('0.00');
    foreach (['invoices', 'payment_attempts', 'financial_transactions', 'instances', 'fulfillment_tasks', 'coupon_usages', 'notification_events'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    Queue::assertNothingPushed();
})->with([0, 2]);

it('refreshes a same-version legacy snapshot in full to restore verified purchase admission', function (): void {
    $product = classicStockPresentationProduct();
    $rules = $product->provisioning_rules;
    unset($rules['classic_catalog']['product_publisher_uid']);
    $product->update(['provisioning_rules' => $rules]);
    expect(app(ClassicPurchaseAdmission::class)->reason($product, 'monthly'))->toBe('请先同步完整商品配置，再进行新购。');
    $this->actingAs(User::factory()->create())->get('/console/products/'.$product->id.'/configure')->assertOk()
        ->assertSee('请先同步完整商品配置，再进行新购。')->assertDontSee('id="classic-configuration"', false);
    Http::assertNothingSent();
    $state = fakeClassicStockPresentationProbe($product, true, 3, true);

    $probe = app(ProbeClassicCatalogAction::class);
    expect($probe->execute([$product->id]))->toBe(1)->and($probe->summary)->toBe(['synced' => 1, 'products_failed' => 0, 'accounts_failed' => 0]);
    $product->refresh();
    expect($product->upstream_version)->toBe(7)->and($product->provisioning_rules['classic_catalog']['product_publisher_uid'])->toBe(0)
        ->and($product->stock_control)->toBeTrue()->and($product->qty)->toBe(3)
        ->and(app(ClassicPurchaseAdmission::class)->reason($product, 'monthly'))->toBeNull();
    expect($state->paths)->toBe(['/zjmf_api_login', '/api/product/proinfo', '/cart/get_product_config']);
    $this->get('/console/products/'.$product->id.'/configure')->assertOk()->assertSee('id="classic-configuration"', false)
        ->assertSee('最近同步库存：3');
    expect($state->paths)->toHaveCount(3);
    $this->assertDatabaseHas('audit_logs', ['action' => 'upstream.catalog_sync', 'subject_id' => $product->id]);
    $this->assertDatabaseCount('invoices', 0);
    Queue::assertNothingPushed();
});

it('updates stock without rereading a complete same-version catalog', function (bool $controlled, int $qty, ?int $expectedStock): void {
    $product = classicStockPresentationProduct(['qty' => 2]);
    $rules = $product->provisioning_rules;
    $prices = $product->upstream_base_prices;
    $state = fakeClassicStockPresentationProbe($product, $controlled, $qty, false);

    $probe = app(ProbeClassicCatalogAction::class);
    expect($probe->execute([$product->id]))->toBe(1)->and($probe->summary)->toBe(['synced' => 1, 'products_failed' => 0, 'accounts_failed' => 0]);
    $product->refresh();
    expect($product->stock_control)->toBe($controlled)->and($product->qty)->toBe($qty)
        ->and($product->provisioning_rules)->toBe($rules)->and($product->upstream_base_prices)->toBe($prices)
        ->and($product->upstream_version)->toBe(7)
        ->and(app(DescribeStoreProductAction::class)->execute($product)['stock'])->toBe($expectedStock);
    expect($state->paths)->toBe(['/zjmf_api_login', '/api/product/proinfo']);
    expect(DB::table('audit_logs')->where('action', 'upstream.catalog_sync')->count())->toBe(0);
    $this->assertDatabaseCount('invoices', 0);
    Queue::assertNothingPushed();
})->with([[true, 0, 0], [true, 5, 5], [false, 0, null]]);
