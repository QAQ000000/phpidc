<?php

declare(strict_types=1);

use App\Enums\DriverType;
use App\Models\Product;
use App\Models\ProductCustomField;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionItem;
use App\Models\UpstreamApi;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Http::preventStrayRequests();
    Queue::fake();
});

afterEach(function (): void {
    Queue::assertNothingPushed();
    foreach (['invoices', 'instances', 'financial_transactions', 'payment_attempts', 'fulfillment_tasks'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
});

function storefrontConfigurationProduct(array $attributes = []): Product
{
    $prices = ['monthly' => ['price' => '10.00', 'setup_fee' => '1.00', 'enabled' => true],
        'annually' => ['price' => '100.00', 'setup_fee' => '1.00', 'enabled' => true]];

    return Product::factory()->create($attributes + ['driver_type' => DriverType::ZJMF_API,
        'upstream_api_id' => UpstreamApi::factory()->create(['encrypted_secret' => 'PRIVATE-STORE-SUPPLIER-KEY'])->id,
        'upstream_pid' => '17', 'upstream_version' => 7, 'markup_percent' => '0.00',
        'stock_control' => true, 'qty' => 6, 'local_prices' => $prices, 'upstream_base_prices' => $prices,
        'provisioning_rules' => ['classic_catalog' => ['version' => 7, 'product_publisher_uid' => 0, 'dependencies' => [],
            'host' => ['show' => 0, 'prefix' => 'ser', 'rule' => ['upper' => 0, 'lower' => 0, 'num' => 1, 'len_num' => 12]],
            'password' => ['show' => 1, 'rule' => ['upper' => 1, 'lower' => 1, 'num' => 1, 'special' => 0, 'len_num' => 12]]]]]);
}

function storefrontConfigurationOptions(Product $product): void
{
    $group = ProductOptionGroup::create(['product_id' => $product->id, 'upstream_group_id' => 50, 'name' => '<b>Region</b>',
        'type' => 'select', 'sync_source' => 'upstream', 'upstream_meta' => ['option_type' => 1]]);
    foreach ([501 => '<script>Region A</script>', 502 => 'Region B'] as $id => $label) {
        $prices = ['monthly' => ['price' => '2.00', 'setup_fee' => '0.00', 'enabled' => true],
            'annually' => ['price' => '20.00', 'setup_fee' => '0.00', 'enabled' => true]];
        ProductOptionItem::create(['option_group_id' => $group->id, 'upstream_sub_id' => $id, 'label' => $label,
            'local_prices' => $prices, 'upstream_base_prices' => $prices]);
    }
    foreach ([80 => 'text', 81 => 'password'] as $id => $type) {
        ProductCustomField::create(['product_id' => $product->id, 'upstream_field_id' => $id, 'name' => 'Delivery field '.$id,
            'field_type' => $type, 'sync_source' => 'upstream', 'show_on_order' => true, 'is_required' => true]);
    }
}

function storefrontConfigurationXPath(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

    return new DOMXPath($document);
}

it('keeps a single checkout form with generated delivery inputs on the left and price payment controls in its summary', function (): void {
    $product = storefrontConfigurationProduct(['name' => '<b>Configured cloud</b>']);
    storefrontConfigurationOptions($product);
    $response = $this->actingAs(User::factory()->create())->get('/console/products/'.$product->id.'/configure')
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertSee('<b>Configured cloud</b>')
        ->assertDontSee('<b>Configured cloud</b>', false)->assertDontSee('<b>Region</b>', false)
        ->assertDontSee('<script>Region A</script>', false)->assertDontSee('PRIVATE-STORE-SUPPLIER-KEY')
        ->assertSee('最近同步库存：6')->assertSee('最终以提交核验为准。');
    $xpath = storefrontConfigurationXPath($response->getContent());
    $form = '//form[@id="classic-configuration"]';
    $main = $form.'/div[contains(@class,"store-configuration-main")]';
    $summary = $form.'/aside[contains(@class,"store-order-summary")]';
    expect($xpath->query($form.'[@method="post"][@action="/checkout"][@data-quote-url="/console/products/'.$product->id.'/quote"][@data-confirmation-required="0"]'))->toHaveCount(1)
        ->and($xpath->query($form.'//form'))->toHaveCount(0)
        ->and($xpath->query($form.'//input[@name="_token"]'))->toHaveCount(1)
        ->and($xpath->query($form.'//input[@name="product_id"][@value="'.$product->id.'"]'))->toHaveCount(1)
        ->and($xpath->query($form.'//input[@name="quote_token"][@value=""]'))->toHaveCount(1)
        ->and(Str::isUlid($xpath->query($form.'//input[@name="request_id"]')->item(0)->getAttribute('value')))->toBeTrue()
        ->and($xpath->query($main.'//select[@name="billing_cycle"]'))->toHaveCount(1)
        ->and($xpath->query($main.'//select[@name="options[configoption][50]"][@data-option="50"][@data-option-type="select"]'))->toHaveCount(1)
        ->and($xpath->query($main.'//input[@name="options[customfield][80]"][@required]'))->toHaveCount(1)
        ->and($xpath->query($main.'//input[@name="options[customfield][81]"][@type="password"][not(@value)]'))->toHaveCount(1)
        ->and($xpath->query($main.'//input[@name="hostname"][@required]')->item(0)->getAttribute('value'))->toMatch('/^ser[0-9]{12}$/')
        ->and($xpath->query($main.'//input[@name="password"][@type="password"][@required]')->item(0)->getAttribute('value'))->toMatch('/^(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])[A-Za-z0-9]{12}$/')
        ->and($xpath->query($summary.'//*[@id="classic-price"][@role="status"][@aria-live="polite"]'))->toHaveCount(1)
        ->and($xpath->query($summary.'//input[@name="coupon_code"][@maxlength="64"]'))->toHaveCount(1)
        ->and($xpath->query($summary.'//input[@name="use_credit"][@type="checkbox"][@value="1"]'))->toHaveCount(1)
        ->and($xpath->query($summary.'//select[@name="gateway"]'))->toHaveCount(1)
        ->and($xpath->query($summary.'//button[@data-quote-trigger][@formnovalidate][@formaction="/console/products/'.$product->id.'/quote"]'))->toHaveCount(1)
        ->and($xpath->query($summary.'//button[@data-checkout-trigger]'))->toHaveCount(1)
        ->and($xpath->query($summary.'//input[@name="password" or @name="hostname" or starts-with(@name,"options[customfield]")]'))->toHaveCount(0)
        ->and($xpath->query('//link[contains(@href,"storefront.css")]'))->toHaveCount(1)
        ->and($xpath->query('//script[@id="classic-dependencies"][@type="application/json"]'))->toHaveCount(1)
        ->and($xpath->query('//script[@src="/js/classic-config.js"][@defer]'))->toHaveCount(1)
        ->and($xpath->query('//script[@src="/js/storefront-configuration.js"][@defer]'))->toHaveCount(1);
    Http::assertNothingSent();
});

it('keeps local quote fallback tokens purchase identity and selected options without reflecting private delivery fields', function (): void {
    $product = storefrontConfigurationProduct();
    storefrontConfigurationOptions($product);
    $requestId = (string) Str::ulid();
    $response = $this->actingAs(User::factory()->create())->post('/console/products/'.$product->id.'/quote', [
        'billing_cycle' => 'annually', 'request_id' => $requestId, 'hostname' => 'PRIVATE-SUBMITTED-HOST',
        'password' => 'PRIVATE-SUBMITTED-PASSWORD', 'options' => ['configoption' => [50 => 502],
            'customfield' => [80 => 'PRIVATE-SUBMITTED-DETAIL', 81 => 'PRIVATE-SUBMITTED-FIELD-PASSWORD']],
    ])->assertOk()->assertSee('本地预估价格：120.00 CNY；开通费 1.00。')->assertDontSee('PRIVATE-SUBMITTED');
    $xpath = storefrontConfigurationXPath($response->getContent());
    expect($xpath->query('//input[@name="quote_token"]')->item(0)->getAttribute('value'))->not->toBe('')
        ->and($xpath->query('//input[@name="request_id"][@value="'.$requestId.'"]'))->toHaveCount(1)
        ->and($xpath->query('//select[@name="billing_cycle"]/option[@value="annually"][@selected]'))->toHaveCount(1)
        ->and($xpath->query('//select[@name="options[configoption][50]"]/option[@value="502"][@selected]'))->toHaveCount(1)
        ->and($xpath->query('//input[@name="options[customfield][80]"][@value=""]'))->toHaveCount(1)
        ->and($xpath->query('//input[@name="options[customfield][81]"][not(@value)]'))->toHaveCount(1);
    Http::assertNothingSent();
});

it('preserves initial sale admission empty cycle and sold out states without checkout controls', function (string $state, string $message): void {
    $product = storefrontConfigurationProduct();
    $rules = $product->provisioning_rules;
    if ($state === 'sold out') {
        $product->update(['qty' => 0]);
    } elseif ($state === 'empty cycles') {
        $product->update(['local_prices' => [], 'upstream_base_prices' => []]);
    } elseif ($state === 'trial only') {
        $prices = ['ontrial' => ['price' => '0.01', 'setup_fee' => '0.00', 'enabled' => true]];
        $product->update(['local_prices' => $prices, 'upstream_base_prices' => $prices]);
    } else {
        if ($state === 'missing publisher') {
            unset($rules['classic_catalog']['product_publisher_uid']);
        } else {
            $rules['classic_catalog']['product_publisher_uid'] = 8;
        }
        $product->update(['provisioning_rules' => $rules]);
    }
    $response = $this->actingAs(User::factory()->create())->get('/console/products/'.$product->id.'/configure')->assertOk()->assertSee($message);
    $xpath = storefrontConfigurationXPath($response->getContent());
    expect($xpath->query('//form[@id="classic-configuration"]'))->toHaveCount(0)
        ->and($xpath->query('//button[@data-checkout-trigger]'))->toHaveCount(0)
        ->and($xpath->query('//script[@src="/js/classic-config.js"]'))->toHaveCount(0);
    Http::assertNothingSent();
})->with([
    ['sold out', '最近同步显示售罄'], ['empty cycles', '暂无可购买的计费周期。'],
    ['trial only', '上游试用资格与共享次数尚无法完整核验'], ['missing publisher', '请先同步完整商品配置'],
    ['developer', '此商品需要绑定上游主机'],
]);

it('keeps type twenty branches hidden and disabled before script activation and retains quantity stage metadata', function (): void {
    $product = storefrontConfigurationProduct();
    foreach ([10 => [0, 101], 20 => [10, 201]] as $groupId => [$parent, $subId]) {
        $group = ProductOptionGroup::create(['product_id' => $product->id, 'upstream_group_id' => $groupId, 'name' => 'Level '.$groupId,
            'type' => 'select', 'sync_source' => 'upstream', 'upstream_meta' => ['option_type' => 20, 'linkage_pid' => $parent,
                'subs' => [['id' => $subId, 'linkage_pid' => $parent === 0 ? 0 : 101]]]]);
        ProductOptionItem::create(['option_group_id' => $group->id, 'upstream_sub_id' => $subId, 'label' => 'Branch '.$subId,
            'upstream_base_prices' => ['monthly' => ['price' => '0.00', 'setup_fee' => '0.00', 'enabled' => true]]]);
    }
    ProductOptionGroup::create(['product_id' => $product->id, 'upstream_group_id' => 30, 'name' => 'Bandwidth', 'type' => 'quantity',
        'sync_source' => 'upstream', 'min_value' => 1, 'max_value' => 20, 'step' => 5, 'upstream_meta' => ['option_type' => 4, 'qty_stage' => 5]]);
    $response = $this->actingAs(User::factory()->create())->get('/console/products/'.$product->id.'/configure')->assertOk()->assertSee('请启用 JavaScript 后选择完整配置和购买。');
    $xpath = storefrontConfigurationXPath($response->getContent());
    expect($xpath->query('//div[@data-linkage-group][@hidden]/label/select[@data-option-type="linkage"][@disabled]'))->toHaveCount(2)
        ->and($xpath->query('//select[@data-option="20"][@data-linkage-parent="10"][@data-linkage-parents="[101]"]'))->toHaveCount(1)
        ->and($xpath->query('//select[@data-option="20"]/option[@value="201"][@data-linkage-parent="101"]'))->toHaveCount(1)
        ->and($xpath->query('//input[@data-option="30"][@data-qty-stage="5"][@min="1"][@max="20"][@step="1"]'))->toHaveCount(1)
        ->and($xpath->query('//button[@data-quote-trigger][@disabled]'))->toHaveCount(1)
        ->and($xpath->query('//button[@data-checkout-trigger][@disabled]'))->toHaveCount(1)
        ->and($xpath->query('//*[@data-store-selection-summary]')->item(0)->textContent)->not->toContain('Branch 101', 'Branch 201');
    Http::assertNothingSent();
});

it('generates new provisioning secrets after validation failure without reusing flashed delivery input', function (): void {
    $product = storefrontConfigurationProduct();
    storefrontConfigurationOptions($product);
    $requestId = (string) Str::ulid();
    $url = '/console/products/'.$product->id.'/configure';
    $this->actingAs(User::factory()->create())->from($url)->post('/checkout', ['product_id' => $product->id, 'request_id' => $requestId,
        'billing_cycle' => 'monthly', 'hostname' => 'supplied-host', 'password' => 'PRIVATE-FAILED-PASSWORD',
        'options' => ['customfield' => [80 => 'PRIVATE-FAILED-DETAIL', 81 => 'PRIVATE-FAILED-FIELD']], 'gateway' => 'invalid'])
        ->assertRedirect($url)->assertSessionHasErrors('gateway')->assertSessionMissing('_old_input.password')->assertSessionMissing('_old_input.options');
    $response = $this->get($url)->assertOk()->assertDontSee('PRIVATE-FAILED');
    $xpath = storefrontConfigurationXPath($response->getContent());
    expect($xpath->query('//input[@name="request_id"][@value="'.$requestId.'"]'))->toHaveCount(1)
        ->and($xpath->query('//input[@name="password"]')->item(0)->getAttribute('value'))->toMatch('/^[A-Za-z0-9]{12}$/')
        ->and($xpath->query('//input[@name="options[customfield][81]"][not(@value)]'))->toHaveCount(1);
    Http::assertNothingSent();
});

it('renders fresh price confirmation inside the same summary while preserving public intent and replacing private inputs', function (): void {
    $product = storefrontConfigurationProduct();
    storefrontConfigurationOptions($product);
    Http::fake(function ($request) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return Http::response(match ($path) {
            '/zjmf_api_login' => ['status' => 200, 'jwt' => 'PRIVATE-STORE-QUOTE-JWT'],
            '/cart/get_product_config' => ['status' => 200, 'data' => ['products' => ['host' => ['show' => 0], 'password' => ['show' => 0]]]],
            '/user_info' => ['status' => 200, 'user' => ['currency' => 1]],
            '/cart/stock_control' => ['status' => 200, 'data' => ['product' => ['hidden' => 0, 'stock_control' => 1, 'qty' => 6]]],
            '/cart/get_total' => ['status' => 200, 'products' => ['total' => '16.00', 'setupfee_total' => '1.00'], 'currency' => ['code' => 'CNY']],
            default => throw new RuntimeException('Unexpected price confirmation UI request: '.$path),
        });
    });
    $requestId = (string) Str::ulid();
    $response = $this->actingAs(User::factory()->create())->post('/checkout', [
        'product_id' => $product->id, 'request_id' => $requestId, 'billing_cycle' => 'monthly', 'hostname' => 'confirmed-host',
        'password' => 'PrivatePass12', 'options' => ['configoption' => [50 => 502], 'customfield' => [80 => 'PRIVATE-CONFIRM-DETAIL', 81 => 'PRIVATE-CONFIRM-FIELD']],
        'coupon_code' => 'PUBLIC-COUPON', 'use_credit' => '1', 'gateway' => 'epay_alipay',
    ])->assertStatus(409)->assertSee('确认新价格并创建账单')->assertSee('开通密码已重新生成')
        ->assertDontSee('PrivatePass12')->assertDontSee('PRIVATE-CONFIRM')->assertDontSee('PRIVATE-STORE-QUOTE-JWT');
    $xpath = storefrontConfigurationXPath($response->getContent());
    $summary = '//form[@id="classic-configuration"][@data-confirmation-required="1"]/aside[contains(@class,"store-order-summary")]';
    expect($xpath->query($summary.'//*[@id="classic-price"]')->item(0)->textContent)->toContain('上游核验价格', '15.00 CNY', '开通费 1.00', '请确认新价格后创建账单。')
        ->and($xpath->query('//input[@name="request_id"][@value="'.$requestId.'"]'))->toHaveCount(1)
        ->and($xpath->query('//input[@name="quote_token"]')->item(0)->getAttribute('value'))->not->toBe('')
        ->and($xpath->query('//input[@name="hostname"][@value="confirmed-host"]'))->toHaveCount(1)
        ->and($xpath->query($summary.'//input[@name="coupon_code"][@value="PUBLIC-COUPON"]'))->toHaveCount(1)
        ->and($xpath->query($summary.'//input[@name="use_credit"][@checked]'))->toHaveCount(1)
        ->and($xpath->query($summary.'//select[@name="gateway"]/option[@value="epay_alipay"][@selected]'))->toHaveCount(1)
        ->and($xpath->query('//input[@name="options[customfield][81]"][not(@value)]'))->toHaveCount(1)
        ->and($xpath->query('//input[@name="password"]')->item(0)->getAttribute('value'))->toMatch('/^[A-Za-z0-9]{12}$/');
    Http::assertSent(fn ($request) => parse_url($request->url(), PHP_URL_PATH) === '/cart/get_total');
});
