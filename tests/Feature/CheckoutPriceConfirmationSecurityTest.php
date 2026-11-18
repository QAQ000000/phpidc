<?php

declare(strict_types=1);

use App\Enums\DriverType;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductCustomField;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionItem;
use App\Models\UpstreamApi;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
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

function checkoutConfirmationFixture(): array
{
    $user = User::factory()->create(['credit' => '100.00']);
    $prices = ['monthly' => ['price' => '10.00', 'setup_fee' => '0.00', 'enabled' => true],
        'annually' => ['price' => '100.00', 'setup_fee' => '0.00', 'enabled' => true]];
    $product = Product::factory()->create(['driver_type' => DriverType::ZJMF_API,
        'upstream_api_id' => UpstreamApi::factory()->create()->id, 'upstream_pid' => '17',
        'markup_percent' => '0.00', 'local_prices' => $prices, 'upstream_base_prices' => $prices,
        'provisioning_rules' => ['classic_catalog' => ['dependencies' => []]]]);
    $group = ProductOptionGroup::create(['product_id' => $product->id, 'upstream_group_id' => 50,
        'name' => 'Region', 'type' => 'select', 'sync_source' => 'upstream', 'upstream_meta' => ['option_type' => 1]]);
    $options = ['monthly' => ['price' => '0.00', 'setup_fee' => '0.00', 'enabled' => true],
        'annually' => ['price' => '0.00', 'setup_fee' => '0.00', 'enabled' => true]];
    foreach ([501, 502] as $id) {
        ProductOptionItem::create(['option_group_id' => $group->id, 'upstream_sub_id' => $id,
            'label' => 'Region '.$id, 'local_prices' => $options, 'upstream_base_prices' => $options]);
    }
    $state = (object) ['total' => '10.00', 'setup' => '0.00', 'hidden' => 0, 'qty' => 10,
        'currency' => 'CNY', 'paths' => [], 'quotes' => []];
    $level = DB::transactionLevel();
    Http::fake(function ($request) use ($state, $level) {
        expect(DB::transactionLevel())->toBe($level);
        $path = parse_url($request->url(), PHP_URL_PATH);
        $state->paths[] = $path;
        if ($path === '/cart/get_total') {
            $state->quotes[] = ['options' => $request['configoption'], 'customfield' => $request['customfield']];

            return Http::response(['status' => 200, 'products' => ['total' => $state->total, 'setupfee_total' => $state->setup],
                'currency' => ['code' => $state->currency]]);
        }

        return match ($path) {
            '/zjmf_api_login' => Http::response(['status' => 200, 'jwt' => 'confirmation-test-jwt']),
            '/cart/get_product_config' => Http::response(['status' => 200, 'data' => ['products' => [
                'password' => ['show' => false], 'host' => ['show' => false],
            ]]]),
            '/user_info' => Http::response(['status' => 200, 'user' => ['currency' => 1]]),
            '/cart/stock_control' => Http::response(['status' => 200, 'data' => ['product' => [
                'hidden' => $state->hidden, 'stock_control' => 1, 'qty' => $state->qty,
            ]]]),
            default => throw new RuntimeException('Unexpected confirmation test request: '.$path),
        };
    });

    return [$user, $product, $state, ['product_id' => $product->id, 'request_id' => (string) Str::ulid(),
        'billing_cycle' => 'monthly', 'options' => ['configoption' => [50 => 501]],
        'use_credit' => true, 'gateway' => 'epay_wechat']];
}

function checkoutConfirmationNoFinancialWrites(User $user): void
{
    expect($user->fresh()->credit)->toBe('100.00')->and($user->fresh()->frozen_credit)->toBe('0.00');
    foreach (['invoices', 'financial_transactions', 'payment_attempts', 'instances', 'fulfillment_tasks', 'coupon_usages'] as $table) {
        test()->assertDatabaseCount($table, 0);
    }
    Queue::assertNothingPushed();
}

function checkoutConfirmationCustomField(Product $product, string $type = 'text'): ProductCustomField
{
    return ProductCustomField::create(['product_id' => $product->id, 'upstream_field_id' => 80,
        'name' => 'Private delivery detail', 'field_type' => $type, 'is_required' => true,
        'show_on_order' => true, 'sync_source' => 'upstream']);
}

it('estimates locally without validating private delivery fields or issuing any upstream request', function (): void {
    [$user, $product, $state, $payload] = checkoutConfirmationFixture();
    checkoutConfirmationCustomField($product);
    $response = $this->actingAs($user)->postJson('/console/products/'.$product->id.'/quote', $payload)
        ->assertOk()->assertJsonPath('estimate', true)->assertJsonPath('price', '10.00')
        ->assertJsonPath('setup_fee', '0.00')->assertJsonPath('currency', 'CNY');
    expect($response->json('quote_token'))->toBeString()->not->toBe('')
        ->and($response->headers->get('Cache-Control'))->toContain('private', 'no-store')
        ->and($state->paths)->toBe([]);
    Http::assertNothingSent();
    checkoutConfirmationNoFinancialWrites($user);
});

it('requires explicit confirmation when no estimate token was supplied even if the price is unchanged', function (): void {
    [$user, , $state, $payload] = checkoutConfirmationFixture();
    $response = $this->actingAs($user)->postJson('/checkout', $payload)->assertStatus(409)
        ->assertJsonPath('price_changed', true)->assertJsonPath('quote.price', '10.00')
        ->assertJsonPath('quote.setup_fee', '0.00')->assertJsonPath('quote.currency', 'CNY');
    expect($response->json('quote.quote_token'))->toBeString()->not->toBe('')
        ->and(count($state->quotes))->toBe(1);
    checkoutConfirmationNoFinancialWrites($user);
});

it('rechecks every confirmation and cannot charge either an obsolete estimate or a subsequently changed price', function (): void {
    [$user, $product, $state, $payload] = checkoutConfirmationFixture();
    $token = $this->actingAs($user)->postJson('/console/products/'.$product->id.'/quote', $payload)->assertOk()->json('quote_token');
    $state->total = '13.00';
    $state->setup = '1.00';
    $first = $this->postJson('/checkout', $payload + ['quote_token' => $token])->assertStatus(409)
        ->assertJsonPath('quote.price', '12.00')->assertJsonPath('quote.setup_fee', '1.00');
    checkoutConfirmationNoFinancialWrites($user);
    $state->total = '17.00';
    $state->setup = '2.00';
    $second = $this->postJson('/checkout', $payload + ['quote_token' => $first->json('quote.quote_token')])->assertStatus(409)
        ->assertJsonPath('quote.price', '15.00')->assertJsonPath('quote.setup_fee', '2.00');
    checkoutConfirmationNoFinancialWrites($user);
    $this->postJson('/checkout', $payload + ['quote_token' => $second->json('quote.quote_token')])->assertCreated()
        ->assertJsonPath('total_amount', '17.00')->assertJsonPath('credit_applied', '17.00')->assertJsonPath('gateway_amount', '0.00');
    expect($user->fresh()->credit)->toBe('83.00')->and($user->fresh()->frozen_credit)->toBe('0.00')
        ->and(count($state->quotes))->toBe(3)
        ->and(count(array_filter($state->paths, fn ($path) => $path === '/cart/stock_control')))->toBe(3);
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseCount('financial_transactions', 2);
    $this->assertDatabaseCount('instances', 1);
    $this->assertDatabaseCount('fulfillment_tasks', 1);
    $this->assertDatabaseCount('payment_attempts', 0);
});

it('uses current server amounts and ignores client injected prices when the confirmed quote matches', function (): void {
    [$user, $product, $state, $payload] = checkoutConfirmationFixture();
    $token = $this->actingAs($user)->postJson('/console/products/'.$product->id.'/quote', $payload)->assertOk()->json('quote_token');
    Http::assertNothingSent();
    $this->postJson('/checkout', $payload + ['quote_token' => $token, 'price' => '0.01', 'setup_fee' => '0.00',
        'total_amount' => '0.01', 'credit_applied' => '0.01', 'currency' => 'USD'])->assertCreated()
        ->assertJsonPath('total_amount', '10.00')->assertJsonPath('credit_applied', '10.00')->assertJsonPath('gateway_amount', '0.00');
    expect($user->fresh()->credit)->toBe('90.00')->and($user->fresh()->frozen_credit)->toBe('0.00')
        ->and($state->quotes)->toBe([['options' => [50 => 501], 'customfield' => []]])
        ->and(Invoice::sole()->upstream_expected_cost)->toBe('10.00');
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseCount('financial_transactions', 2);
    $this->assertDatabaseCount('fulfillment_tasks', 1);
});

it('rejects invalid or differently scoped quotation tokens before contacting the upstream', function (string $change): void {
    [$user, $product, $state, $payload] = checkoutConfirmationFixture();
    $token = $this->actingAs($user)->postJson('/console/products/'.$product->id.'/quote', $payload)->assertOk()->json('quote_token');
    if ($change === 'tampered') {
        $token = 'invalid-'.$token;
    } elseif ($change === 'wrong payload shape') {
        $token = Crypt::encryptString('[]');
    } elseif ($change === 'user') {
        $this->actingAs(User::factory()->create(['credit' => '100.00']));
    } elseif ($change === 'product') {
        $other = $product->replicate();
        $other->slug = 'other-'.Str::ulid();
        $other->upstream_pid = '18';
        $other->save();
        $payload['product_id'] = $other->id;
        $payload['options'] = [];
    } elseif ($change === 'cycle') {
        $payload['billing_cycle'] = 'annually';
    } elseif ($change === 'configuration') {
        $payload['options']['configoption'][50] = 502;
    } elseif ($change === 'account') {
        $product->update(['upstream_api_id' => UpstreamApi::factory()->create()->id]);
    } elseif ($change === 'upstream product') {
        $product->update(['upstream_pid' => '18']);
    } elseif ($change === 'expired') {
        $this->travel(5)->minutes();
    }
    $this->postJson('/checkout', $payload + ['quote_token' => $token])->assertUnprocessable()->assertJsonValidationErrors('quote_token');
    expect($state->paths)->toBe([]);
    Http::assertNothingSent();
    checkoutConfirmationNoFinancialWrites($user);
})->with(['tampered', 'wrong payload shape', 'user', 'product', 'cycle', 'configuration', 'account', 'upstream product', 'expired']);

it('revalidates stock and currency after the customer explicitly confirms a fresh price', function (string $change, string $error): void {
    [$user, , $state, $payload] = checkoutConfirmationFixture();
    $first = $this->actingAs($user)->postJson('/checkout', $payload)->assertStatus(409);
    $state->{$change} = match ($change) {
        'hidden' => 1,
        'qty' => 0,
        'currency' => 'USD',
    };
    $this->postJson('/checkout', $payload + ['quote_token' => $first->json('quote.quote_token')])
        ->assertUnprocessable()->assertJsonValidationErrors($error);
    expect(count(array_filter($state->paths, fn ($path) => $path === '/cart/stock_control')))->toBe(2);
    checkoutConfirmationNoFinancialWrites($user);
})->with(['hidden' => ['hidden', 'product_id'], 'sold out' => ['qty', 'product_id'], 'currency' => ['currency', 'currency']]);

it('allows delivery fields and request identifiers to be completed after the local price preview', function (): void {
    [$user, $product, $state, $payload] = checkoutConfirmationFixture();
    checkoutConfirmationCustomField($product);
    $token = $this->actingAs($user)->postJson('/console/products/'.$product->id.'/quote', $payload)->assertOk()->json('quote_token');
    $payload['request_id'] = (string) Str::ulid();
    $payload['options']['customfield'] = [80 => 'private-delivery-value'];
    $payload['password'] = 'PrivatePass12';
    $this->postJson('/checkout', $payload + ['quote_token' => $token])->assertCreated()->assertJsonPath('total_amount', '10.00');
    expect($state->quotes)->toBe([['options' => [50 => 501], 'customfield' => [80 => 'private-delivery-value']]])
        ->and(Invoice::sole()->purchase_request_id)->toBe($payload['request_id'])
        ->and(DB::table('invoices')->value('line_items'))->not->toContain('private-delivery-value', 'PrivatePass12');
});

it('still validates required custom fields on checkout before upstream work or financial writes', function (): void {
    [$user, $product, , $payload] = checkoutConfirmationFixture();
    checkoutConfirmationCustomField($product);
    $token = $this->actingAs($user)->postJson('/console/products/'.$product->id.'/quote', $payload)->assertOk()->json('quote_token');
    $this->postJson('/checkout', $payload + ['quote_token' => $token])->assertUnprocessable()->assertJsonValidationErrors('options');
    Http::assertNothingSent();
    checkoutConfirmationNoFinancialWrites($user);
});

it('replays an existing invoice before expired tokens and changed catalog rules without quoting or charging again', function (): void {
    [$user, $product, $state, $payload] = checkoutConfirmationFixture();
    $token = $this->actingAs($user)->postJson('/console/products/'.$product->id.'/quote', $payload)->assertOk()->json('quote_token');
    $first = $this->postJson('/checkout', $payload + ['quote_token' => $token])->assertCreated();
    $original = Invoice::sole()->getRawOriginal();
    $paths = $state->paths;
    $this->travel(6)->minutes();
    $product->update(['is_hidden' => true, 'currency' => 'USD', 'provisioning_rules' => ['hostname_mode' => 'custom']]);
    foreach ([$token, 'invalid-token', null] as $replayedToken) {
        $this->postJson('/checkout', $payload + ['quote_token' => $replayedToken])->assertCreated()
            ->assertJsonPath('invoice_id', $first->json('invoice_id'))->assertJsonPath('total_amount', '10.00');
    }
    expect($state->paths)->toBe($paths)->and(Invoice::sole()->getRawOriginal())->toBe($original)
        ->and($user->fresh()->credit)->toBe('90.00')->and($user->fresh()->frozen_credit)->toBe('0.00');
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseCount('financial_transactions', 2);
    $this->assertDatabaseCount('instances', 1);
    $this->assertDatabaseCount('fulfillment_tasks', 1);
});

it('renders an explicit HTML price confirmation without echoing or flashing private delivery inputs', function (): void {
    [$user, $product, , $payload] = checkoutConfirmationFixture();
    checkoutConfirmationCustomField($product);
    $payload['password'] = 'PrivatePass12';
    $payload['options']['customfield'] = [80 => 'private-delivery-value'];
    $response = $this->actingAs($user)->post('/checkout', $payload)->assertStatus(409)
        ->assertSee('确认新价格并创建账单')->assertSee('name="quote_token"', false)
        ->assertSee('value="'.$payload['request_id'].'"', false)
        ->assertDontSee('PrivatePass12')->assertDontSee('private-delivery-value')
        ->assertSessionMissing('_old_input.password')->assertSessionMissing('_old_input.options.customfield');
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    checkoutConfirmationNoFinancialWrites($user);
});
