<?php

declare(strict_types=1);

use App\Enums\DriverType;
use App\Models\Category;
use App\Models\Product;
use App\Models\RedemptionCode;
use App\Models\UpstreamApi;
use App\Models\User;
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

afterEach(function (): void {
    Http::assertNothingSent();
    Queue::assertNothingPushed();
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
});

function storefrontCatalogUiDom(string $html): DOMXPath
{
    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">'.$html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($document);
}

function storefrontCatalogUiCard(array $attributes = []): Product
{
    return Product::factory()->create(array_replace([
        'local_prices' => ['onetime' => ['price' => '12.34', 'setup_fee' => '1.01', 'enabled' => true]],
    ], $attributes));
}

function storefrontCatalogUiClassic(array $attributes = []): Product
{
    return Product::factory()->create(array_replace([
        'driver_type' => DriverType::ZJMF_API,
        'upstream_api_id' => UpstreamApi::factory()->create()->id,
        'upstream_pid' => '17', 'upstream_version' => 7,
        'stock_control' => true, 'qty' => 3,
        'local_prices' => ['monthly' => ['price' => '19.99', 'setup_fee' => '0.00', 'enabled' => true]],
        'provisioning_rules' => ['classic_catalog' => ['version' => 7, 'product_publisher_uid' => 0,
            'dependencies' => [], 'host' => ['show' => 0], 'password' => ['show' => 0],
            'pay_type' => ['pay_type' => 'recurring']]],
    ], $attributes));
}

it('keeps category navigation in one native collapsible block and safely scopes category links', function (): void {
    $category = Category::factory()->create(['slug' => '香港 & BGP', 'name' => '香港 BGP']);
    $product = storefrontCatalogUiCard(['category_id' => $category->id, 'name' => 'Category visible product']);
    $other = storefrontCatalogUiCard(['name' => 'Other category product']);
    $path = '/store/'.rawurlencode($category->slug);
    $catalog = $this->get('/store')->assertOk()->assertSee('href="'.$path.'"', false);
    $dom = storefrontCatalogUiDom($catalog->getContent());
    expect($dom->query('//nav[@aria-label="产品分类"]'))->toHaveCount(1)
        ->and($dom->query('//details[@open]/nav[@aria-label="产品分类"]'))->toHaveCount(1);

    $filtered = $this->get($path)->assertOk()->assertSee($product->name)->assertDontSee($other->name);
    $filteredDom = storefrontCatalogUiDom($filtered->getContent());
    expect($filteredDom->evaluate('string(//nav[@aria-label="产品分类"]/a[@aria-current="page"]/@href)'))->toBe($path);
    $detail = $this->get($product->publicPath())->assertOk()->assertSee('href="'.$path.'"', false);
    expect(storefrontCatalogUiDom($detail->getContent())->query('//nav[@aria-label="面包屑"]'))->toHaveCount(1);
});

it('paginates actual public products and excludes hidden or disconnected catalog entries', function (): void {
    $category = Category::factory()->create();
    for ($index = 1; $index <= 13; $index++) {
        storefrontCatalogUiCard(['category_id' => $category->id, 'name' => 'Paged product '.str_pad((string) $index, 2, '0', STR_PAD_LEFT), 'sort_order' => $index]);
    }
    storefrontCatalogUiCard(['name' => 'Hidden product sentinel', 'is_hidden' => true]);
    storefrontCatalogUiCard(['name' => 'Hidden category sentinel', 'category_id' => Category::factory()->create(['is_hidden' => true])->id]);
    storefrontCatalogUiClassic(['name' => 'Disconnected product sentinel', 'upstream_api_id' => UpstreamApi::factory()->create(['is_active' => false])->id]);
    $path = '/store/'.rawurlencode($category->slug);
    $first = $this->get($path.'?source=catalog')->assertOk()->assertSee('Paged product 01')->assertDontSee('Paged product 13')
        ->assertViewHas('products', fn ($products) => $products->count() === 12 && $products->total() === 13);
    $dom = storefrontCatalogUiDom($first->getContent());
    expect($dom->query('//nav[@aria-label="分页"]/div/a[@rel="next"]'))->toHaveCount(1);
    $next = $dom->evaluate('string(//nav[@aria-label="分页"]//a[@rel="next"]/@href)');
    expect($next)->toContain($path, 'source=catalog', 'page=2');
    $second = $this->get($next)->assertOk()->assertSee('Paged product 13')->assertDontSee('Paged product 01')
        ->assertViewHas('products', fn ($products) => $products->count() === 1);
    expect(storefrontCatalogUiDom($second->getContent())->query('//nav[@aria-label="分页"]//a[@rel="prev"]'))->toHaveCount(1);
    $this->get('/store')->assertOk()->assertViewHas('products', fn ($products) => $products->total() === 13)
        ->assertDontSee('Hidden product sentinel')->assertDontSee('Hidden category sentinel')->assertDontSee('Disconnected product sentinel');
});

it('renders safe summaries and descriptions without exposing inventory or internal product fields', function (): void {
    $product = storefrontCatalogUiCard([
        'name' => 'Safe <product> name',
        'description' => 'Product <strong>summary</strong> <script>catalogUnsafe()</script>',
        'provisioning_rules' => ['internal_key' => 'PRIVATE-CATALOG-KEY'],
    ]);
    RedemptionCode::factory()->create(['product_id' => $product->id, 'code' => 'PRIVATE-INVENTORY-CODE']);
    foreach (['/', '/store'] as $path) {
        $response = $this->get($path)->assertOk()->assertSee($product->name)
            ->assertDontSee('<strong>summary</strong>', false)->assertDontSee('<script>catalogUnsafe()</script>', false)
            ->assertDontSee('PRIVATE-CATALOG-KEY')->assertDontSee('PRIVATE-INVENTORY-CODE');
        $dom = storefrontCatalogUiDom($response->getContent());
        expect($dom->query('//article[contains(@class,"store-product-card")]//a[@href="'.$product->publicPath().'"]'))->toHaveCount(2);
    }
    $this->get($product->publicPath())->assertOk()->assertSee($product->description)->assertSee('12.34 CNY')->assertSee('开通费 1.01 CNY')
        ->assertDontSee('<script>catalogUnsafe()</script>', false)->assertDontSee('PRIVATE-CATALOG-KEY')->assertDontSee('PRIVATE-INVENTORY-CODE')
        ->assertDontSee('配置费用')->assertSee('登录 / 注册后购买');
});

it('retains the direct purchase POST contract and never fills a password from old input', function (): void {
    $product = storefrontCatalogUiCard(['require_kyc' => true, 'require_phone' => true,
        'provisioning_rules' => ['hostname_mode' => 'custom', 'password_min_len' => 12]]);
    RedemptionCode::factory()->create(['product_id' => $product->id]);
    $requestId = (string) Str::ulid();
    $response = $this->actingAs(User::factory()->create())->withSession(['_old_input' => [
        'product_id' => (string) $product->id, 'request_id' => $requestId,
        'hostname' => 'saved-host', 'password' => 'PRIVATE-OLD-PASSWORD',
    ]])->get($product->publicPath())->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('购买此产品需要完成实名认证。')->assertSee('购买此产品需要绑定手机号。')
        ->assertSee('当前可用卡密：1 张')->assertDontSee('PRIVATE-OLD-PASSWORD')->assertDontSee('登录 / 注册后购买');
    $dom = storefrontCatalogUiDom($response->getContent());
    expect($dom->query('//form[@method="post"][@action="/checkout"]'))->toHaveCount(1)
        ->and($dom->evaluate('string(//form[@action="/checkout"]/input[@name="product_id"]/@value)'))->toBe((string) $product->id)
        ->and($dom->evaluate('string(//form[@action="/checkout"]/input[@name="request_id"]/@value)'))->toBe($requestId)
        ->and($dom->evaluate('string(//form[@action="/checkout"]/input[@name="_token"]/@value)'))->not->toBe('')
        ->and($dom->query('//select[@name="billing_cycle"]/option[@value="onetime"]'))->toHaveCount(1)
        ->and($dom->query('//input[@name="coupon_code"][@maxlength="64"]'))->toHaveCount(1)
        ->and($dom->query('//input[@name="hostname"][@required][@value="saved-host"]'))->toHaveCount(1)
        ->and($dom->query('//input[@name="password"][@required][@type="password"][@autocomplete="new-password"][not(@value)]'))->toHaveCount(1)
        ->and($dom->query('//input[@name="use_credit"][@type="checkbox"][@value="1"][not(@checked)]'))->toHaveCount(1)
        ->and($dom->query('//select[@name="gateway"]'))->toHaveCount(1)
        ->and($dom->query('//button[@type="submit"]'))->toHaveCount(1)
        ->and($dom->query('//input[contains(@name,"reason")]'))->toHaveCount(0);
});

it('reflects the existing payment channel switches in the direct purchase form', function (bool $wechat, bool $alipay): void {
    foreach (['epay_wechat_enabled' => $wechat, 'epay_alipay_enabled' => $alipay] as $key => $enabled) {
        DB::table('system_settings')->insert(['group' => 'payment', 'key' => $key, 'value' => $enabled ? '1' : '0', 'is_encrypted' => false]);
    }
    $product = storefrontCatalogUiCard();
    RedemptionCode::factory()->create(['product_id' => $product->id]);
    $response = $this->actingAs(User::factory()->create())->get($product->publicPath())->assertOk();
    $dom = storefrontCatalogUiDom($response->getContent());
    expect($dom->query('//select[@name="gateway"]/option[@value="epay_wechat"]'))->toHaveCount($wechat ? 1 : 0)
        ->and($dom->query('//select[@name="gateway"]/option[@value="epay_alipay"]'))->toHaveCount($alipay ? 1 : 0);
    if (! $wechat && ! $alipay) {
        $response->assertSee('外部支付暂未开放')->assertSee('使用钱包余额');
    }
})->with([[false, true], [true, false], [false, false]]);

it('keeps no-cycle and sold-out direct products unavailable before showing a purchase form', function (bool $hasCycle): void {
    $product = storefrontCatalogUiCard($hasCycle ? [] : ['local_prices' => []]);
    if (! $hasCycle) {
        RedemptionCode::factory()->create(['product_id' => $product->id]);
    }
    $this->actingAs(User::factory()->create())->get($product->publicPath())->assertOk()
        ->assertSee($hasCycle ? '暂时售罄，请稍后再来。' : '暂无可购买的计费周期。')
        ->assertDontSee('action="/checkout"', false)->assertDontSee('登录 / 注册后购买');
})->with([true, false]);

it('distinguishes remotely generated codes from local inventory without exposing upstream credentials', function (): void {
    $account = UpstreamApi::factory()->create(['driver' => DriverType::SUB2API,
        'base_url' => 'https://sub2.test/api/v1', 'encrypted_secret' => 'PRIVATE-REMOTE-ADMIN-KEY',
        'config' => ['mode' => 'remote_api', 'protocol' => 'balance-codes-v1']]);
    $product = storefrontCatalogUiCard(['driver_type' => DriverType::SUB2API, 'upstream_api_id' => $account->id,
        'provisioning_rules' => ['sub2api_face_value' => '100']]);
    $this->actingAs(User::factory()->create())->get($product->publicPath())->assertOk()
        ->assertSee('付款后自动生成兑换码')->assertSee('action="/checkout"', false)
        ->assertDontSee('当前可用卡密')->assertDontSee('暂时售罄')->assertDontSee('PRIVATE-REMOTE-ADMIN-KEY')->assertDontSee('sub2.test');
});

it('shows synchronized classic reference prices and routes available purchases to local estimation', function (): void {
    $product = storefrontCatalogUiClassic();
    $response = $this->actingAs(User::factory()->create())->get($product->publicPath())->assertOk()
        ->assertSee('商品基础价与参考价格')->assertSee('19.99 CNY')->assertSee('本地预估价')->assertSee('最近同步库存：3')
        ->assertSee('href="/console/products/'.$product->id.'/configure"', false)
        ->assertDontSee('action="/checkout"', false)->assertDontSee('开通费');
    expect(storefrontCatalogUiDom($response->getContent())->query('//aside[@aria-label="价格与购买"]'))->toHaveCount(1);
    $product->update(['qty' => 0]);
    $this->get($product->publicPath())->assertOk()->assertSee('最近同步显示售罄')
        ->assertDontSee('href="/console/products/'.$product->id.'/configure"', false);
    $product->update(['qty' => 3, 'provisioning_rules' => ['classic_catalog' => []]]);
    $this->get($product->publicPath())->assertOk()->assertSee('请先同步完整商品配置，再进行新购。')
        ->assertDontSee('href="/console/products/'.$product->id.'/configure"', false);
});
