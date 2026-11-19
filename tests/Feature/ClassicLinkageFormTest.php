<?php

declare(strict_types=1);

use App\Enums\DriverType;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionItem;
use App\Models\UpstreamApi;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Http::preventStrayRequests();
    Http::fake([]);
    Queue::fake();
});

function classicLinkageFormFixture(): array
{
    $user = User::factory()->create();
    $prices = collect(['monthly', 'annually'])->mapWithKeys(fn ($cycle) => [$cycle => [
        'price' => '10.00', 'setup_fee' => '0.00', 'enabled' => true,
    ]])->all();
    $product = Product::factory()->create([
        'driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => UpstreamApi::factory()->create()->id,
        'upstream_pid' => '17', 'upstream_base_prices' => $prices, 'local_prices' => $prices,
        'stock_control' => false, 'markup_percent' => '0.00',
        'provisioning_rules' => ['classic_catalog' => ['product_publisher_uid' => 0,
            'host' => ['show' => 0], 'password' => ['show' => 0], 'dependencies' => [],
            'pay_type' => ['pay_type' => 'recurring']]],
    ]);
    $definitions = [
        50 => ['parent' => 0, 'top' => 0, 'path' => '0-50', 'subs' => [
            ['id' => 501, 'linkage_pid' => 0], ['id' => 502, 'linkage_pid' => 0], ['id' => 503, 'linkage_pid' => 0],
        ]],
        60 => ['parent' => 50, 'top' => 50, 'path' => '0-50-60', 'subs' => [
            ['id' => 601, 'linkage_pid' => 501], ['id' => 602, 'linkage_pid' => 501],
        ]],
        70 => ['parent' => 50, 'top' => 50, 'path' => '0-50-70', 'subs' => [
            ['id' => 701, 'linkage_pid' => 502],
        ]],
        90 => ['parent' => 0, 'top' => 0, 'path' => '0-90', 'subs' => [
            ['id' => 901, 'linkage_pid' => 0], ['id' => 902, 'linkage_pid' => 0],
        ]],
    ];
    $groups = [];
    $optionPrices = collect(['monthly', 'annually'])->mapWithKeys(fn ($cycle) => [$cycle => [
        'price' => '1.00', 'setup_fee' => '0.00', 'enabled' => true,
    ]])->all();
    foreach ($definitions as $id => $definition) {
        $groups[$id] = ProductOptionGroup::create(['product_id' => $product->id, 'upstream_group_id' => $id,
            'name' => $id === 50 ? 'Region <script>unsafe</script>' : 'Group '.$id,
            'type' => 'select', 'sync_source' => 'upstream', 'upstream_meta' => [
                'option_type' => 20, 'linkage_pid' => $definition['parent'], 'linkage_top_pid' => $definition['top'],
                'linkage_level' => $definition['path'], 'subs' => $definition['subs'],
            ]]);
        foreach ($definition['subs'] as $sub) {
            ProductOptionItem::create(['option_group_id' => $groups[$id]->id, 'upstream_sub_id' => $sub['id'],
                'label' => $sub['id'] === 501 ? 'Choice <img src=x onerror=alert(1)>' : 'Choice '.$sub['id'],
                'local_prices' => $optionPrices, 'upstream_base_prices' => $optionPrices]);
        }
    }

    return [$user, $product, $groups];
}

function classicLinkageFormDom(string $html): DOMXPath
{
    $document = new DOMDocument;
    $document->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

    return new DOMXPath($document);
}

function classicLinkageFormElement(DOMXPath $xpath, string $query): DOMElement
{
    $node = $xpath->query($query)?->item(0);
    if (! $node instanceof DOMElement) {
        throw new RuntimeException('Missing element: '.$query);
    }

    return $node;
}

function classicLinkageFormNoWrites(): void
{
    Http::assertNothingSent();
    Queue::assertNothingPushed();
    foreach (['invoices', 'instances', 'financial_transactions', 'payment_attempts', 'fulfillment_tasks', 'upstream_operations'] as $table) {
        test()->assertDatabaseCount($table, 0);
    }
}

it('renders escaped hierarchy metadata with hidden containers and disabled purchase until script initialization', function (): void {
    [$user, $product] = classicLinkageFormFixture();
    $response = $this->actingAs($user)->get('/console/products/'.$product->id.'/configure')->assertOk()
        ->assertSee('此商品使用层级联动配置，请启用 JavaScript 后选择完整配置和购买。')
        ->assertSee('Region &lt;script&gt;unsafe&lt;/script&gt;', false)
        ->assertSee('Choice &lt;img src=x onerror=alert(1)&gt;', false);
    $xpath = classicLinkageFormDom($response->getContent());
    expect($xpath->query('//select[@data-option-type="linkage"]')->length)->toBe(4)
        ->and($xpath->query('//select[@data-option-type="linkage"][@disabled][@required]')->length)->toBe(4)
        ->and($xpath->query('//div[@data-linkage-group][@hidden]/label/select')->length)->toBe(4)
        ->and($xpath->query('//button[@data-quote-trigger][@disabled]')->length)->toBe(1)
        ->and($xpath->query('//button[@data-checkout-trigger][@disabled]')->length)->toBe(1)
        ->and($xpath->query('//noscript/p[@role="alert"]')->length)->toBe(1)
        ->and($xpath->query('//select/option/img')->length)->toBe(0);
    $child = classicLinkageFormElement($xpath, '//select[@data-option="60"]');
    expect($child->getAttribute('data-linkage-parent'))->toBe('50')
        ->and(json_decode($child->getAttribute('data-linkage-parents'), true))->toBe([501]);
    $option = classicLinkageFormElement($xpath, '//select[@data-option="60"]/option[@value="601"]');
    expect($option->getAttribute('data-linkage-parent'))->toBe('501')
        ->and(json_decode($option->getAttribute('data-cycles'), true))->toBe(['monthly', 'annually']);
    classicLinkageFormNoWrites();
});

it('keeps structurally required parent IDs even if all their local suboptions are hidden', function (): void {
    [$user, $product, $groups] = classicLinkageFormFixture();
    $groups[70]->items()->update(['is_hidden' => true]);
    $response = $this->actingAs($user)->get('/console/products/'.$product->id.'/configure')->assertOk();
    $xpath = classicLinkageFormDom($response->getContent());
    $child = classicLinkageFormElement($xpath, '//select[@data-option="70"]');
    expect(json_decode($child->getAttribute('data-linkage-parents'), true))->toBe([502])
        ->and($xpath->query('//select[@data-option="70"]/option[@value!=""]')->length)->toBe(0)
        ->and($child->hasAttribute('disabled'))->toBeTrue();
    classicLinkageFormNoWrites();
});

it('preserves the server quoted active branch and keeps other branch controls disabled on HTML responses', function (): void {
    [$user, $product] = classicLinkageFormFixture();
    $selection = [50 => 502, 70 => 701, 90 => 901];
    $response = $this->actingAs($user)->post('/console/products/'.$product->id.'/quote', [
        'billing_cycle' => 'monthly', 'options' => ['configoption' => $selection],
    ])->assertOk();
    $xpath = classicLinkageFormDom($response->getContent());
    foreach ($selection as $group => $sub) {
        expect($xpath->query('//select[@data-option="'.$group.'"]/option[@value="'.$sub.'"][@selected]')->length)->toBe(1);
    }
    expect($xpath->query('//select[@data-option="60"]/option[@selected]')->length)->toBe(0)
        ->and(classicLinkageFormElement($xpath, '//input[@name="quote_token"]')->getAttribute('value'))->not->toBe('')
        ->and($xpath->query('//button[@data-checkout-trigger][@disabled]')->length)->toBe(1);
    classicLinkageFormNoWrites();
});

it('leaves ordinary configurable forms usable without hierarchy initialization', function (): void {
    [$user, $product, $groups] = classicLinkageFormFixture();
    foreach ($groups as $group) {
        $group->update(['upstream_meta' => ['option_type' => 1]]);
    }
    $response = $this->actingAs($user)->get('/console/products/'.$product->id.'/configure')->assertOk()
        ->assertDontSee('此商品使用层级联动配置');
    $xpath = classicLinkageFormDom($response->getContent());
    expect($xpath->query('//select[@data-option-type="linkage"]')->length)->toBe(0)
        ->and($xpath->query('//select[@data-option][@disabled]')->length)->toBe(0)
        ->and($xpath->query('//button[@data-checkout-trigger][@disabled]')->length)->toBe(0)
        ->and($xpath->query('//button[@data-quote-trigger][@disabled]')->length)->toBe(0);
    classicLinkageFormNoWrites();
});

it('explains the current lifecycle boundary instead of exposing editable hierarchy choices for service changes', function (): void {
    [, $product] = classicLinkageFormFixture();
    $html = view('sales.config-options', ['product' => $product, 'selection' => [], 'serviceChange' => true])->render();
    $xpath = classicLinkageFormDom($html);
    expect($html)->toContain('此服务含层级联动配置，当前暂不支持升级、降配和绑定后的价格复核。')
        ->and($xpath->query('//select[@data-option-type="linkage"][@disabled]')->length)->toBe(4)
        ->and($xpath->query('//noscript')->length)->toBe(0);
    classicLinkageFormNoWrites();
});
