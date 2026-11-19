<?php

declare(strict_types=1);

use App\Actions\GenerateProductProvisioningDefaultsAction;
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
    Queue::fake();
    Http::preventStrayRequests();
});

function generatedProvisioningFormFixture(): array
{
    $user = User::factory()->create(['credit' => '100.00']);
    $prices = ['monthly' => ['price' => '10.00', 'setup_fee' => '0.00', 'enabled' => true]];
    $product = Product::factory()->create([
        'driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => UpstreamApi::factory()->create()->id,
        'upstream_pid' => '17', 'markup_percent' => '0.00',
        'local_prices' => $prices, 'upstream_base_prices' => $prices,
        'provisioning_rules' => [
            'hostname_mode' => 'custom', 'hostname_regex' => '^vm-[a-z0-9]{10}$',
            'password_min_len' => 12, 'password_max_len' => 16,
            'classic_catalog' => [
                'product_publisher_uid' => 0,
                'dependencies' => [],
                'host' => json_encode(['show' => 1, 'prefix' => 'vm-',
                    'rule' => ['len_num' => 10, 'upper' => 0, 'lower' => 1, 'num' => 1]]),
                'password' => json_encode(['show' => 1,
                    'rule' => ['len_num' => 14, 'upper' => 1, 'lower' => 1, 'num' => 1, 'special' => 1]]),
            ],
        ],
    ]);
    $group = ProductOptionGroup::create(['product_id' => $product->id, 'upstream_group_id' => 50,
        'name' => 'Region', 'type' => 'select', 'sync_source' => 'upstream', 'upstream_meta' => ['option_type' => 1]]);
    $optionPrices = ['monthly' => ['price' => '0.00', 'setup_fee' => '0.00', 'enabled' => true]];
    foreach ([501, 502] as $id) {
        ProductOptionItem::create(['option_group_id' => $group->id, 'upstream_sub_id' => $id,
            'label' => 'Region '.$id, 'local_prices' => $optionPrices, 'upstream_base_prices' => $optionPrices]);
    }
    ProductCustomField::create(['product_id' => $product->id, 'upstream_field_id' => 80,
        'name' => 'Private delivery field', 'field_type' => 'password', 'is_required' => true,
        'show_on_order' => true, 'sync_source' => 'upstream']);

    return [$user, $product];
}

function generatedProvisioningFormField(string $html, string $name): DOMElement
{
    $document = new DOMDocument;
    $document->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $nodes = (new DOMXPath($document))->query('//form[@id="classic-configuration"]//*[@name="'.$name.'"]');
    $field = $nodes === false ? null : $nodes->item(0);
    if (! $field instanceof DOMElement) {
        throw new RuntimeException('Missing configuration form field: '.$name);
    }

    return $field;
}

function generatedProvisioningFormValue(string $html, string $name): string
{
    $field = generatedProvisioningFormField($html, $name);
    if ($field->tagName !== 'select') {
        return $field->getAttribute('value');
    }
    foreach ($field->getElementsByTagName('option') as $option) {
        if ($option->hasAttribute('selected')) {
            return $option->getAttribute('value');
        }
    }

    throw new RuntimeException('Missing explicit selected option: '.$name);
}

function generatedProvisioningFormDefaults(string $html): array
{
    $hostname = generatedProvisioningFormValue($html, 'hostname');
    $password = generatedProvisioningFormValue($html, 'password');
    expect($hostname)->toMatch('/^vm-[a-z0-9]{10}$/')
        ->and(strlen($password))->toBe(14)
        ->and($password)->toMatch('/[A-Z]/')->toMatch('/[a-z]/')->toMatch('/[0-9]/')->toMatch('/[^A-Za-z0-9]/');

    return ['hostname' => $hostname, 'password' => $password];
}

function generatedProvisioningFormNoFinancialWrites(User $user): void
{
    expect($user->fresh()->credit)->toBe('100.00')->and($user->fresh()->frozen_credit)->toBe('0.00');
    foreach (['invoices', 'financial_transactions', 'payment_attempts', 'instances', 'fulfillment_tasks', 'coupon_usages'] as $table) {
        test()->assertDatabaseCount($table, 0);
    }
    Queue::assertNothingPushed();
}

function generatedProvisioningFormUpstream(Product $product): void
{
    Http::fake(function ($request) use ($product) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match ($path) {
            '/zjmf_api_login' => Http::response(['status' => 200, 'jwt' => 'generated-form-test-jwt']),
            '/cart/get_product_config' => Http::response(['status' => 200, 'data' => ['products' => [
                'password' => $product->provisioning_rules['classic_catalog']['password'],
                'host' => $product->provisioning_rules['classic_catalog']['host'],
            ]]]),
            '/user_info' => Http::response(['status' => 200, 'user' => ['currency' => 1]]),
            '/cart/stock_control' => Http::response(['status' => 200, 'data' => ['product' => [
                'hidden' => 0, 'stock_control' => 1, 'qty' => 10,
            ]]]),
            '/cart/get_total' => Http::response(['status' => 200, 'products' => [
                'total' => '12.00', 'setupfee_total' => '0.00',
            ], 'currency' => ['code' => 'CNY']]),
            default => throw new RuntimeException('Unexpected generated form test request: '.$path),
        };
    });
}

it('prefills independent rule-compliant credentials without upstream reads or financial writes', function (): void {
    [$user, $product] = generatedProvisioningFormFixture();
    $path = '/console/products/'.$product->id.'/configure';
    $this->actingAs($user)->withSession(['_old_input' => ['password' => 'OldPrivatePassword']]);
    $first = $this->get($path)->assertOk()->assertDontSee('OldPrivatePassword');
    $second = $this->get($path)->assertOk();
    $defaults = generatedProvisioningFormDefaults($first->getContent());
    $next = generatedProvisioningFormDefaults($second->getContent());
    expect($next['hostname'])->not->toBe($defaults['hostname'])
        ->and($next['password'])->not->toBe($defaults['password'])
        ->and(generatedProvisioningFormField($first->getContent(), 'password')->getAttribute('type'))->toBe('password')
        ->and(generatedProvisioningFormField($first->getContent(), 'password')->getAttribute('autocomplete'))->toBe('new-password')
        ->and(generatedProvisioningFormField($first->getContent(), 'hostname')->getAttribute('autocomplete'))->toBe('off')
        ->and(generatedProvisioningFormField($first->getContent(), 'password')->hasAttribute('required'))->toBeTrue()
        ->and(generatedProvisioningFormField($first->getContent(), 'hostname')->hasAttribute('required'))->toBeTrue()
        ->and($first->headers->get('Cache-Control'))->toContain('private', 'no-store');
    Http::assertNothingSent();
    generatedProvisioningFormNoFinancialWrites($user);
});

it('prefills classic defaults on configuration and HTML estimates when the upstream hostname rule is empty', function (): void {
    [$user, $product] = generatedProvisioningFormFixture();
    $rules = $product->provisioning_rules;
    unset($rules['hostname_mode'], $rules['hostname_regex']);
    $rules['classic_catalog']['host'] = '';
    $product->update(['provisioning_rules' => $rules]);
    $this->actingAs($user);

    $configured = $this->get('/console/products/'.$product->id.'/configure')->assertOk();
    $estimated = $this->post('/console/products/'.$product->id.'/quote', [
        'billing_cycle' => 'monthly', 'options' => ['configoption' => [50 => 501]],
    ])->assertOk();

    foreach ([$configured, $estimated] as $response) {
        expect(generatedProvisioningFormValue($response->getContent(), 'hostname'))->toMatch('/^S[A-Za-z0-9]{12}$/')
            ->and(strlen(generatedProvisioningFormValue($response->getContent(), 'password')))->toBe(14)
            ->and($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
        $response->assertDontSee('上游开通规则尚未同步或不可读');
    }
    Http::assertNothingSent();
    generatedProvisioningFormNoFinancialWrites($user);
});

it('regenerates HTML quote defaults while retaining the purchase key and pricing selection', function (): void {
    [$user, $product] = generatedProvisioningFormFixture();
    $requestId = (string) Str::ulid();
    $response = $this->actingAs($user)->post('/console/products/'.$product->id.'/quote', [
        'request_id' => $requestId, 'billing_cycle' => 'monthly',
        'hostname' => 'submitted-hostname', 'password' => 'SubmittedPrivatePassword',
        'options' => ['configoption' => [50 => 502], 'customfield' => [80 => 'private-delivery-value']],
    ])->assertOk()->assertDontSee('SubmittedPrivatePassword')->assertDontSee('private-delivery-value')
        ->assertSessionMissing('_old_input.password')->assertSessionMissing('_old_input.options.customfield');
    $html = $response->getContent();
    generatedProvisioningFormDefaults($html);
    expect(generatedProvisioningFormValue($html, 'request_id'))->toBe($requestId)
        ->and(generatedProvisioningFormValue($html, 'billing_cycle'))->toBe('monthly')
        ->and(generatedProvisioningFormValue($html, 'options[configoption][50]'))->toBe('502')
        ->and(generatedProvisioningFormValue($html, 'options[customfield][80]'))->toBe('')
        ->and(generatedProvisioningFormValue($html, 'quote_token'))->not->toBe('');
    Http::assertNothingSent();
    generatedProvisioningFormNoFinancialWrites($user);
});

it('keeps JSON estimates free of generated credentials and never invokes the generator', function (): void {
    [$user, $product] = generatedProvisioningFormFixture();
    $this->mock(GenerateProductProvisioningDefaultsAction::class)->shouldNotReceive('execute');
    $response = $this->actingAs($user)->postJson('/console/products/'.$product->id.'/quote', [
        'billing_cycle' => 'monthly', 'options' => ['configoption' => [50 => 501]],
        'hostname' => 'private-hostname', 'password' => 'private-password',
    ])->assertOk()->assertExactJsonStructure(['price', 'setup_fee', 'currency', 'quote_token', 'estimate'])
        ->assertJsonPath('price', '10.00')->assertJsonPath('estimate', true)
        ->assertDontSee('private-hostname')->assertDontSee('private-password');
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    Http::assertNothingSent();
    generatedProvisioningFormNoFinancialWrites($user);
});

it('refreshes the password on HTML price confirmation and preserves the original purchase intent', function (): void {
    [$user, $product] = generatedProvisioningFormFixture();
    $this->actingAs($user);
    $configured = $this->get('/console/products/'.$product->id.'/configure')->assertOk();
    $defaults = generatedProvisioningFormDefaults($configured->getContent());
    $requestId = generatedProvisioningFormValue($configured->getContent(), 'request_id');
    $quote = $this->postJson('/console/products/'.$product->id.'/quote', [
        'billing_cycle' => 'monthly', 'options' => ['configoption' => [50 => 502]],
    ])->assertOk();
    Http::assertNothingSent();
    generatedProvisioningFormUpstream($product);
    $response = $this->post('/checkout', $defaults + [
        'product_id' => $product->id, 'request_id' => $requestId, 'billing_cycle' => 'monthly',
        'options' => ['configoption' => [50 => 502], 'customfield' => [80 => 'private-delivery-value']],
        'use_credit' => true, 'gateway' => 'epay_alipay', 'quote_token' => $quote->json('quote_token'),
    ])->assertStatus(409)->assertSee('确认新价格并创建账单')
        ->assertDontSee($defaults['password'])->assertDontSee('private-delivery-value')
        ->assertSessionMissing('_old_input.password')->assertSessionMissing('_old_input.options.customfield');
    $html = $response->getContent();
    $next = generatedProvisioningFormDefaults($html);
    expect($next['hostname'])->toBe($defaults['hostname'])
        ->and($next['password'])->not->toBe($defaults['password'])
        ->and(generatedProvisioningFormValue($html, 'request_id'))->toBe($requestId)
        ->and(generatedProvisioningFormValue($html, 'billing_cycle'))->toBe('monthly')
        ->and(generatedProvisioningFormValue($html, 'options[configoption][50]'))->toBe('502')
        ->and(generatedProvisioningFormValue($html, 'options[customfield][80]'))->toBe('')
        ->and(generatedProvisioningFormValue($html, 'gateway'))->toBe('epay_alipay')
        ->and(generatedProvisioningFormField($html, 'use_credit')->hasAttribute('checked'))->toBeTrue()
        ->and(generatedProvisioningFormValue($html, 'quote_token'))->not->toBe($quote->json('quote_token'))
        ->and($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    generatedProvisioningFormNoFinancialWrites($user);
});

it('does not generate or return credentials in JSON price confirmation', function (): void {
    [$user, $product] = generatedProvisioningFormFixture();
    $this->mock(GenerateProductProvisioningDefaultsAction::class)->shouldNotReceive('execute');
    generatedProvisioningFormUpstream($product);
    $this->actingAs($user)->postJson('/checkout', [
        'product_id' => $product->id, 'request_id' => (string) Str::ulid(), 'billing_cycle' => 'monthly',
        'hostname' => 'vm-a1b2c3d4e5', 'password' => 'Aa1234567890@#',
        'options' => ['configoption' => [50 => 501], 'customfield' => [80 => 'private-delivery-value']],
    ])->assertStatus(409)->assertExactJsonStructure([
        'price_changed', 'message', 'quote' => ['price', 'setup_fee', 'currency', 'quote_token'],
    ])->assertJsonPath('price_changed', true)->assertJsonPath('quote.price', '12.00')
        ->assertDontSee('vm-a1b2c3d4e5')->assertDontSee('Aa1234567890@#')->assertDontSee('private-delivery-value');
    generatedProvisioningFormNoFinancialWrites($user);
});

it('shows a failed generation field clearly and leaves it available for manual entry', function (): void {
    [$user, $product] = generatedProvisioningFormFixture();
    $rules = $product->provisioning_rules;
    $rules['classic_catalog']['host'] = ['show' => 1, 'rule' => []];
    $product->update(['provisioning_rules' => $rules]);
    $response = $this->actingAs($user)->get('/console/products/'.$product->id.'/configure')->assertOk()
        ->assertSee('上游主机名规则不完整，无法自动生成，请同步商品后重试。');
    $html = $response->getContent();
    expect(generatedProvisioningFormValue($html, 'hostname'))->toBe('')
        ->and(generatedProvisioningFormField($html, 'hostname')->hasAttribute('readonly'))->toBeFalse()
        ->and(generatedProvisioningFormField($html, 'hostname')->hasAttribute('disabled'))->toBeFalse()
        ->and(strlen(generatedProvisioningFormValue($html, 'password')))->toBe(14);
    Http::assertNothingSent();
    generatedProvisioningFormNoFinancialWrites($user);
});

it('keeps optional credentials optional on local redemption configuration forms', function (): void {
    $user = User::factory()->create(['credit' => '100.00']);
    $product = Product::factory()->create(['local_prices' => [
        'onetime' => ['price' => '10.00', 'setup_fee' => '0.00', 'enabled' => true],
    ]]);
    $response = $this->actingAs($user)->get('/console/products/'.$product->id.'/configure')->assertOk();
    foreach (['hostname', 'password'] as $field) {
        expect(generatedProvisioningFormField($response->getContent(), $field)->hasAttribute('required'))->toBeFalse();
    }
    Http::assertNothingSent();
    generatedProvisioningFormNoFinancialWrites($user);
});

it('authenticates configuration and quotation before generating any credentials', function (): void {
    [, $product] = generatedProvisioningFormFixture();
    $this->mock(GenerateProductProvisioningDefaultsAction::class)->shouldNotReceive('execute');
    $this->get('/console/products/'.$product->id.'/configure')->assertRedirect('/login');
    $this->postJson('/console/products/'.$product->id.'/quote', ['billing_cycle' => 'monthly'])->assertUnauthorized();
    Http::assertNothingSent();
    $this->assertDatabaseCount('invoices', 0);
});
