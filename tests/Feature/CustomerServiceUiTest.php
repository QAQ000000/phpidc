<?php

declare(strict_types=1);

use App\Enums\BillingCycle;
use App\Enums\DriverType;
use App\Enums\InstanceStatus;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductCustomField;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionItem;
use App\Models\UpstreamApi;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Http::preventStrayRequests();
});

afterEach(function (): void {
    Http::assertNothingSent();
});

function customerServiceUiInstance(array $attributes = []): Instance
{
    $account = UpstreamApi::factory()->create();
    $product = Product::factory()->create(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id, 'upstream_pid' => '17']);

    return Instance::factory()->active()->create($attributes + ['driver_type' => DriverType::ZJMF_API, 'product_id' => $product->id,
        'upstream_api_id' => $account->id, 'upstream_instance_id' => '4291']);
}

function customerServiceUiXPath(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

    return new DOMXPath($document);
}

function customerServiceUiOperation(int $instanceId, string $action): string
{
    return '//form[@action="/console/instances/'.$instanceId.'/operations"][input[@name="action"][@value="'.$action.'"]]';
}

it('keeps service search status and pagination scoped to the owner without listing delivered credentials', function (): void {
    $owner = User::factory()->create();
    $product = Product::factory()->create(['name' => 'Searchable service product']);
    $instances = Instance::factory()->count(23)->active()->create(['user_id' => $owner->id, 'product_id' => $product->id,
        'display_name' => 'Owned service', 'primary_ip' => '192.0.2.44',
        'encrypted_credentials' => ['card_key' => 'PRIVATE-SERVICE-CARD', 'password' => 'PRIVATE-SERVICE-PASSWORD']]);
    $foreign = Instance::factory()->active()->create(['display_name' => 'FOREIGN-SERVICE-ONLY', 'product_id' => $product->id]);
    Instance::factory()->suspended()->create(['user_id' => $owner->id, 'display_name' => 'OTHER-STATE-SERVICE', 'product_id' => $product->id]);
    $response = $this->actingAs($owner)->get('/console/instances?q=Searchable&status=Active')->assertOk()->assertSee('我的服务')
        ->assertSee('共 23 条')->assertDontSee('PRIVATE-SERVICE')->assertDontSee($foreign->display_name)->assertDontSee('OTHER-STATE-SERVICE');
    $xpath = customerServiceUiXPath($response->getContent());
    expect($response->viewData('instances')->total())->toBe(23)
        ->and($response->viewData('instances')->pluck('id')->all())->toBe($instances->slice(-20)->reverse()->pluck('id')->all())
        ->and($xpath->query('//form[@method="get"][@action="/console/instances"]//input[@name="q"][@value="Searchable"]'))->toHaveCount(1)
        ->and($xpath->query('//select[@name="status"]/option[@value="Active"][@selected]'))->toHaveCount(1);
    foreach ($response->viewData('instances') as $instance) {
        expect(array_key_exists('encrypted_credentials', $instance->getAttributes()))->toBeFalse();
    }
    parse_str(parse_url($response->viewData('instances')->url(2), PHP_URL_QUERY), $pageQuery);
    expect($pageQuery)->toBe(['q' => 'Searchable', 'status' => 'Active', 'page' => '2']);
    $this->get('/console/instances?q=192.0.2.44&status=Active&page=2')->assertOk()->assertDontSee('PRIVATE-SERVICE');
    $this->get('/console/instances?q=Missing')->assertOk()->assertSee('暂无符合条件的服务。');
});

it('shows service facts delivery and machine entry points while preserving lazy post protocols', function (): void {
    $instance = customerServiceUiInstance(['display_name' => '<b>Owned cloud</b>', 'primary_ip' => '192.0.2.9',
        'encrypted_credentials' => ['username' => 'root', 'password' => '<OWNER-SERVICE-PASSWORD>', 'port' => 22, 'os' => 'Ubuntu'],
        'active_config' => ['upstream_panel' => ['information' => true, 'machine_status' => true, 'charts' => ['cpu']]], 'last_synced_at' => now()->subHour()]);
    $before = $instance->fresh()->getRawOriginal();
    $invoice = Invoice::factory()->paid()->create(['user_id' => $instance->user_id, 'instance_id' => $instance->id]);
    Invoice::factory()->create(['instance_id' => $instance->id, 'invoice_no' => 'FOREIGN-RELATED-INVOICE']);
    $response = $this->actingAs($instance->user)->get('/console/instances/'.$instance->id)->assertOk()
        ->assertSee('基本信息')->assertSee('机器与控制面板')->assertSee('交付信息')->assertSee('续费与服务操作')->assertSee('关联账单')
        ->assertSee('<b>Owned cloud</b>')->assertDontSee('<b>Owned cloud</b>', false)
        ->assertSee('<OWNER-SERVICE-PASSWORD>')->assertDontSee('<OWNER-SERVICE-PASSWORD>', false)
        ->assertSee('192.0.2.9')->assertSee($invoice->invoice_no)->assertDontSee('FOREIGN-RELATED-INVOICE')
        ->assertHeader('Cache-Control', 'no-store, private');
    $xpath = customerServiceUiXPath($response->getContent());
    expect($xpath->query('//form[@method="post"][@action="/console/instances/'.$instance->id.'/machine"][@data-machine-controls-read]//input[@name="_token"]'))->toHaveCount(1)
        ->and($xpath->query('//form[@data-machine-controls-read]//p[@role="status"][@hidden]'))->toHaveCount(1)
        ->and($xpath->query('//div[@data-power-status]//form[@method="post"][@action="/console/instances/'.$instance->id.'/power-status"]'))->toHaveCount(1)
        ->and($xpath->query('//*[@data-power-status-result][@role="status"][@aria-live="polite"]'))->toHaveCount(1)
        ->and($xpath->query('//form[@method="post"][@action="/console/instances/'.$instance->id.'/panel-login"][@target="_blank"][@rel="noopener noreferrer"]//input[@name="_token"]'))->toHaveCount(1)
        ->and($xpath->query('//a[@href="/console/instances/'.$instance->id.'/telemetry"]'))->toHaveCount(1)
        ->and($xpath->query('//script[@src="/js/machine-controls.js"][@defer]'))->toHaveCount(1)
        ->and($xpath->query('//script[@src="/js/power-status.js"][@defer]'))->toHaveCount(1)
        ->and($instance->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('fulfillment_tasks', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('upstream_operations', 0);
});

it('keeps delivered secrets visible only in an Active owner detail and retains lifecycle eligibility', function (InstanceStatus $status, string $kind): void {
    $credentials = $kind === 'card' ? ['card_key' => 'PRIVATE-ACTIVE-DELIVERY', 'face_value' => '25.00', 'redeem_url' => 'https://redeem.example.test']
        : ['password' => 'PRIVATE-ACTIVE-DELIVERY'];
    $instance = customerServiceUiInstance(['status' => $status, 'encrypted_credentials' => $credentials]);
    $response = $this->actingAs($instance->user)->get('/console/instances/'.$instance->id)->assertOk();
    if ($status === InstanceStatus::ACTIVE) {
        $response->assertSee('PRIVATE-ACTIVE-DELIVERY');
    } else {
        $response->assertDontSee('PRIVATE-ACTIVE-DELIVERY')->assertSee('尚未完成交付');
    }
    $xpath = customerServiceUiXPath($response->getContent());
    expect($xpath->query('//form[@action="/console/instances/'.$instance->id.'/operations"]'))->toHaveCount($status->isBillable() ? 4 : 0)
        ->and($xpath->query('//form[@action="/console/instances/'.$instance->id.'/panel-login"]'))->toHaveCount($status === InstanceStatus::ACTIVE && $kind === 'host' ? 1 : 0);
    $this->actingAs(User::factory()->create())->get('/console/instances/'.$instance->id)->assertForbidden()->assertDontSee('PRIVATE-ACTIVE-DELIVERY');
})->with(InstanceStatus::cases())->with(['card', 'host']);

it('keeps one time archive and delayed delivery explanations without offering recurring service operations', function (bool $delivered): void {
    $instance = Instance::factory()->active()->create(['billing_cycle' => BillingCycle::ONETIME,
        'encrypted_credentials' => $delivered ? ['card_key' => '<ONE-TIME-OWNER-CARD>'] : null]);
    $response = $this->actingAs($instance->user)->get('/console/instances/'.$instance->id)->assertOk()->assertSee('归档一次性交付权益');
    if ($delivered) {
        $response->assertSee('<ONE-TIME-OWNER-CARD>');
    } else {
        $response->assertSee('开通信息同步中，请稍后刷新。');
    }
    $xpath = customerServiceUiXPath($response->getContent());
    expect($xpath->query('//a[@href="/console/instances/'.$instance->id.'/archive"]'))->toHaveCount(1)
        ->and($xpath->query('//form[@action="/console/instances/'.$instance->id.'/operations"]'))->toHaveCount(0);
})->with([true, false]);

it('preserves all lifecycle request confirmation payment and current configuration fields', function (): void {
    $instance = customerServiceUiInstance(['active_config' => ['upstream_configoption' => [50 => 502]]]);
    $group = ProductOptionGroup::create(['product_id' => $instance->product_id, 'upstream_group_id' => 50, 'name' => 'CPU',
        'type' => 'select', 'sync_source' => 'upstream']);
    ProductOptionItem::create(['option_group_id' => $group->id, 'upstream_sub_id' => 501, 'label' => 'One core']);
    ProductOptionItem::create(['option_group_id' => $group->id, 'upstream_sub_id' => 502, 'label' => 'Two cores']);
    $response = $this->actingAs($instance->user)->get('/console/instances/'.$instance->id)->assertOk();
    $xpath = customerServiceUiXPath($response->getContent());
    foreach (['renew', 'upgrade', 'downgrade', 'terminate'] as $action) {
        $form = customerServiceUiOperation($instance->id, $action);
        expect($xpath->query($form.'[@method="post"]//input[@name="_token"]'))->toHaveCount(1)
            ->and(Str::isUlid($xpath->query($form.'//input[@name="request_id"]')->item(0)->getAttribute('value')))->toBeTrue()
            ->and($xpath->query($form.'//input[@name="confirm"][@value="1"][@required][not(@checked)]'))->toHaveCount(1);
        if (in_array($action, ['renew', 'upgrade'], true)) {
            expect($xpath->query($form.'//input[@name="use_credit"][@value="1"][not(@checked)]'))->toHaveCount(1)
                ->and($xpath->query($form.'//select[@name="gateway"]/option[@value="epay_wechat" or @value="epay_alipay"]'))->toHaveCount(2);
        } else {
            expect($xpath->query($form.'//input[@name="reason"][@maxlength="1000"][not(@required)]'))->toHaveCount(1);
        }
        if (in_array($action, ['upgrade', 'downgrade'], true)) {
            expect($xpath->query($form.'//select[@name="options[configoption][50]"]/option[@value="502"][@selected]'))->toHaveCount(1);
        }
    }
    expect($xpath->query(customerServiceUiOperation($instance->id, 'renew').'//input[@name="coupon_code"][@maxlength="64"]'))->toHaveCount(1);
});

it('preserves resource mapping and future price review explanations in the relevant operations', function (string $flag): void {
    $instance = customerServiceUiInstance(['active_config' => [$flag => true]]);
    $response = $this->actingAs($instance->user)->get('/console/instances/'.$instance->id)->assertOk();
    $xpath = customerServiceUiXPath($response->getContent());
    if ($flag === 'mapping_requires_review') {
        $response->assertSee('热切换后的配置映射待核对，升级与续费降价暂不可用。');
        expect($xpath->query('//a[@href="/console/instances/'.$instance->id.'/mapping-price"]'))->toHaveCount(0);
    } else {
        $response->assertSee('未来价格基线待本人确认，升级与续费降价暂不可用。');
        expect($xpath->query('//a[@href="/console/instances/'.$instance->id.'/mapping-price"]'))->toHaveCount(2);
    }
})->with(['mapping_requires_review', 'price_requires_review']);

it('reopens only the failed lifecycle operation preserves its valid request id and requires new payment confirmation', function (string $action): void {
    $instance = customerServiceUiInstance();
    $url = '/console/instances/'.$instance->id;
    $requestId = (string) Str::ulid();
    $this->actingAs($instance->user)->from($url)->post($url.'/operations', [
        'ui_form' => 'service-operation-'.$instance->id.'-'.$action, 'action' => $action, 'request_id' => $requestId,
        'reason' => 'Unsaved service request', 'coupon_code' => 'UNSAVED-COUPON', 'gateway' => 'epay_alipay',
        'use_credit' => '1', 'confirm' => '0', 'options' => ['password' => 'NEVER-FLASH-SERVICE-PASSWORD'],
    ])->assertRedirect($url)->assertSessionHasErrors('confirm')->assertSessionMissing('_old_input.options');
    $response = $this->get($url)->assertOk()->assertDontSee('NEVER-FLASH-SERVICE-PASSWORD');
    $xpath = customerServiceUiXPath($response->getContent());
    $form = customerServiceUiOperation($instance->id, $action);
    expect($xpath->query($form.'/ancestor::details[@open]'))->toHaveCount(1)
        ->and($xpath->query($form.'//input[@name="request_id"][@value="'.$requestId.'"]'))->toHaveCount(1)
        ->and($xpath->query('//input[@name="confirm"][@checked]'))->toHaveCount(0)
        ->and($xpath->query('//input[@name="use_credit"][@checked]'))->toHaveCount(0);
    foreach (array_diff(['renew', 'upgrade', 'downgrade', 'terminate'], [$action]) as $otherAction) {
        $other = customerServiceUiOperation($instance->id, $otherAction);
        expect($xpath->query($other.'//input[@name="request_id"][@value="'.$requestId.'"]'))->toHaveCount(0)
            ->and($xpath->query($other.'//input[@name="reason"][@value="Unsaved service request"]'))->toHaveCount(0);
        if ($otherAction !== 'renew') {
            expect($xpath->query($other.'/ancestor::details[@open]'))->toHaveCount(0);
        }
    }
    if (in_array($action, ['renew', 'upgrade'], true)) {
        expect($xpath->query($form.'//select[@name="gateway"]/option[@value="epay_alipay"][@selected]'))->toHaveCount(1);
    } else {
        expect($xpath->query($form.'//input[@name="reason"][@value="Unsaved service request"]'))->toHaveCount(1);
    }
    if ($action === 'renew') {
        expect($xpath->query($form.'//input[@name="coupon_code"][@value="UNSAVED-COUPON"]'))->toHaveCount(1);
    }
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('fulfillment_tasks', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
})->with(['renew', 'upgrade', 'downgrade', 'terminate']);

it('safely resets malformed old fields and never fills another instance operation', function (bool $anotherInstance): void {
    $instance = customerServiceUiInstance();
    $target = $anotherInstance ? customerServiceUiInstance(['user_id' => $instance->user_id]) : $instance;
    $url = '/console/instances/'.$instance->id;
    $this->actingAs($instance->user)->from($url)->post($url.'/operations', [
        'ui_form' => 'service-operation-'.$instance->id.'-terminate', 'action' => 'terminate',
        'request_id' => ['invalid'], 'reason' => $anotherInstance ? 'Only original instance' : ['invalid'], 'confirm' => '0',
    ])->assertSessionHasErrors('request_id');
    $response = $this->get('/console/instances/'.$target->id)->assertOk();
    $xpath = customerServiceUiXPath($response->getContent());
    $form = customerServiceUiOperation($target->id, 'terminate');
    expect(Str::isUlid($xpath->query($form.'//input[@name="request_id"]')->item(0)->getAttribute('value')))->toBeTrue()
        ->and($xpath->query($form.'//input[@name="reason"][@value=""]'))->toHaveCount(1)
        ->and($xpath->query($form.'/ancestor::details[@open]'))->toHaveCount($anotherInstance ? 0 : 1)
        ->and($xpath->query('//input[@name="confirm"][@checked]'))->toHaveCount(0);
})->with([false, true]);

it('renders only allowlisted historical order fields and masks their passwords inside the information card', function (): void {
    $instance = customerServiceUiInstance(['active_config' => ['upstream_pid' => '17']]);
    foreach ([101 => 'text', 102 => 'password'] as $id => $type) {
        ProductCustomField::create(['product_id' => $instance->product_id, 'upstream_field_id' => $id, 'name' => 'Order field '.$id,
            'field_type' => $type, 'sync_source' => 'upstream', 'show_on_detail' => true]);
    }
    Invoice::factory()->paid()->create(['user_id' => $instance->user_id, 'product_id' => $instance->product_id, 'instance_id' => $instance->id,
        'line_items' => ['purchase' => ['driver_type' => DriverType::ZJMF_API->value, 'upstream_api_id' => $instance->upstream_api_id,
            'upstream_pid' => '17', 'encrypted_params' => Crypt::encryptString(json_encode(['password' => 'PRIVATE-ORDER-PASSWORD',
                'options' => ['customfield' => [101 => '<b>Owned domain</b>', 102 => 'PRIVATE-ORDER-FIELD', 999 => 'UNLISTED-FIELD']]], JSON_THROW_ON_ERROR))]]]);
    $this->actingAs($instance->user)->get('/console/instances/'.$instance->id)->assertOk()->assertSee('下单资料')
        ->assertSee('下单时填写的资料，不代表上游当前配置。')->assertSee('<b>Owned domain</b>')->assertDontSee('<b>Owned domain</b>', false)
        ->assertSee('已填写（内容已隐藏）')->assertDontSee('PRIVATE-ORDER')->assertDontSee('UNLISTED-FIELD');
});
