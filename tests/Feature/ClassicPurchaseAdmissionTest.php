<?php

declare(strict_types=1);

use App\Actions\CreateComboInvoiceAction;
use App\Actions\CreatePurchaseFulfillmentAction;
use App\Actions\ExecuteFulfillmentTaskAction;
use App\Actions\QuoteLocalProductAction;
use App\Contracts\FulfillmentDriver;
use App\Contracts\LiveQuotable;
use App\Data\QuoteResult;
use App\Enums\BillingCycle;
use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Models\FulfillmentTask;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Services\DriverManager;
use App\Services\SettingService;
use App\Support\ClassicPurchaseAdmission;
use App\Support\PurchaseRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Queue::fake();
    Http::preventStrayRequests();
});

function admissionClassicProduct(array $catalog = ['product_publisher_uid' => 0]): Product
{
    $prices = ['monthly' => ['price' => '10.00', 'setup_fee' => '0.00', 'enabled' => true],
        'ontrial' => ['price' => '1.00', 'setup_fee' => '0.00', 'enabled' => true]];

    return Product::factory()->create(['driver_type' => DriverType::ZJMF_API,
        'upstream_api_id' => UpstreamApi::factory()->create()->id, 'upstream_pid' => '17',
        'upstream_base_prices' => $prices, 'local_prices' => $prices,
        'provisioning_rules' => ['classic_catalog' => $catalog + ['version' => 1, 'dependencies' => []]]]);
}

it('blocks unsupported classic sales before estimates upstream requests or financial effects', function (array $catalog, BillingCycle $cycle, string $field): void {
    $product = admissionClassicProduct($catalog);
    $user = User::factory()->create(['credit' => '100.00']);
    foreach ([
        fn () => app(QuoteLocalProductAction::class)->execute($product, $cycle->value, []),
        fn () => app(CreateComboInvoiceAction::class)->execute($user, $product, $cycle, [], true,
            input: ['request_id' => (string) Str::ulid()]),
    ] as $operation) {
        try {
            $operation();
            $this->fail('An unsupported classic purchase must fail before payment.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey($field);
        }
    }
    expect($user->fresh()->credit)->toBe('100.00')->and($user->fresh()->frozen_credit)->toBe('0.00');
    foreach (['invoices', 'financial_transactions', 'coupon_usages', 'payment_attempts', 'instances', 'fulfillment_tasks', 'upstream_operations'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    Http::assertNothingSent();
    Queue::assertNothingPushed();
})->with([
    'missing publisher' => [[], BillingCycle::MONTHLY, 'product_id'],
    'null publisher' => [['product_publisher_uid' => null], BillingCycle::MONTHLY, 'product_id'],
    'string publisher' => [['product_publisher_uid' => '0'], BillingCycle::MONTHLY, 'product_id'],
    'negative publisher' => [['product_publisher_uid' => -1], BillingCycle::MONTHLY, 'product_id'],
    'boolean publisher' => [['product_publisher_uid' => false], BillingCycle::MONTHLY, 'product_id'],
    'fractional publisher' => [['product_publisher_uid' => 0.5], BillingCycle::MONTHLY, 'product_id'],
    'developer application' => [['product_publisher_uid' => 12], BillingCycle::MONTHLY, 'product_id'],
    'trial without identity conditions' => [['product_publisher_uid' => 0, 'pay_type' => ['pay_ontrial_condition' => [], 'pay_ontrial_num' => 888]], BillingCycle::ONTRIAL, 'billing_cycle'],
    'trial with identity conditions' => [['product_publisher_uid' => 0, 'pay_type' => ['pay_ontrial_condition' => ['realname']]], BillingCycle::ONTRIAL, 'billing_cycle'],
]);

it('admits verified ordinary classic products without classifying names or server groups', function (): void {
    $product = admissionClassicProduct();
    $product->update(['name' => 'Developer CDN API cloud application']);
    $quote = app(QuoteLocalProductAction::class)->execute($product, 'monthly', ['pairs' => [], 'configoption' => []]);
    expect($quote->downstreamPrice)->toBe('10.00')
        ->and(app(ClassicPurchaseAdmission::class)->reason($product, 'monthly'))->toBeNull();
    $local = Product::factory()->create(['provisioning_rules' => null]);
    expect(app(ClassicPurchaseAdmission::class)->reason($local, 'ontrial'))->toBeNull();
    Http::assertNothingSent();
});

it('rejects direct HTTP trial and developer submissions even when the form is bypassed', function (int $publisher, string $cycle, string $field): void {
    $product = admissionClassicProduct(['product_publisher_uid' => $publisher]);
    $user = User::factory()->create(['credit' => '100.00']);
    $payload = ['product_id' => $product->id, 'request_id' => (string) Str::ulid(),
        'billing_cycle' => $cycle, 'use_credit' => true, 'options' => []];
    $this->actingAs($user)->postJson('/console/products/'.$product->id.'/quote', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->postJson('/checkout', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
    expect($user->fresh()->credit)->toBe('100.00')->and($user->fresh()->frozen_credit)->toBe('0.00');
    Http::assertNothingSent();
})->with([[0, 'ontrial', 'billing_cycle'], [9, 'monthly', 'product_id']]);

it('rechecks purchase admission in the financial transaction after upstream quotation', function (): void {
    $product = admissionClassicProduct();
    $user = User::factory()->create(['credit' => '100.00']);
    $driver = Mockery::mock(FulfillmentDriver::class.', '.LiveQuotable::class);
    $driver->shouldReceive('validateParams')->once()->andReturn([]);
    $driver->shouldReceive('quotePurchase')->once()->andReturnUsing(function () use ($product): QuoteResult {
        $product->update(['provisioning_rules' => ['classic_catalog' => ['product_publisher_uid' => 8]]]);

        return new QuoteResult('8.00', '10.00');
    });
    $manager = Mockery::mock(DriverManager::class);
    $manager->shouldReceive('fulfillment')->once()->andReturn($driver);
    $action = new CreateComboInvoiceAction($manager, app(SettingService::class));
    expect(fn () => $action->execute($user, $product, BillingCycle::MONTHLY, [], true,
        input: ['request_id' => (string) Str::ulid()]))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('coupon_usages', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
    expect($user->fresh()->credit)->toBe('100.00')->and($user->fresh()->frozen_credit)->toBe('0.00');
});

it('returns historical trial requests before current sale admission in both Action and HTTP', function (InvoiceStatus $status): void {
    $product = admissionClassicProduct([]);
    $user = User::factory()->create(['credit' => '97.00', 'frozen_credit' => '3.00']);
    $input = ['request_id' => (string) Str::ulid()];
    $snapshot = app(PurchaseRequest::class)->snapshot($product->id, BillingCycle::ONTRIAL, [], true, 'epay_wechat', $input);
    $invoice = Invoice::factory()->create(['user_id' => $user->id, 'product_id' => $product->id,
        'purchase_request_id' => $input['request_id'], 'status' => $status,
        'line_items' => ['purchase' => ['encrypted_request' => Crypt::encryptString($snapshot)]]]);
    $before = $invoice->fresh()->getRawOriginal();
    $again = app(CreateComboInvoiceAction::class)->execute($user, $product, BillingCycle::ONTRIAL, [], true, 'epay_wechat', $input);
    expect($again->getRawOriginal())->toBe($before);
    $this->actingAs($user)->postJson('/checkout', $input + ['product_id' => $product->id,
        'billing_cycle' => 'ontrial', 'use_credit' => true, 'gateway' => 'epay_wechat'])
        ->assertCreated()->assertJsonPath('invoice_id', $invoice->id);
    expect($user->fresh()->credit)->toBe('97.00')->and($user->fresh()->frozen_credit)->toBe('3.00');
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('fulfillment_tasks', 0);
    Http::assertNothingSent();
})->with([InvoiceStatus::UNPAID, InvoiceStatus::PAID, InvoiceStatus::EXPIRED]);

it('continues paid trial fulfillment despite the new sale boundary and missing legacy publisher metadata', function (): void {
    $product = admissionClassicProduct([]);
    $user = User::factory()->create();
    $params = ['upstream_pid' => '17', 'expected_cost' => '1.00', 'options' => []];
    $invoice = Invoice::factory()->paid()->create(['user_id' => $user->id, 'product_id' => $product->id,
        'total_amount' => '1.00', 'gateway_amount' => '1.00', 'upstream_expected_cost' => '1.00',
        'line_items' => ['purchase' => ['billing_cycle' => 'ontrial', 'upstream_pid' => '17',
            'encrypted_params' => Crypt::encryptString(json_encode($params, JSON_THROW_ON_ERROR))]]]);
    DB::transaction(fn () => app(CreatePurchaseFulfillmentAction::class)->execute($invoice));
    Http::fake(function ($request) {
        return Http::response(match (parse_url($request->url(), PHP_URL_PATH)) {
            '/zjmf_api_login' => ['status' => 200, 'jwt' => 'historical-trial-jwt'],
            '/user_info' => ['status' => 200, 'user' => ['currency' => 1]],
            '/cart/clear', '/cart/add_to_shop' => ['status' => 200],
            '/cart/get_product_config' => ['status' => 200, 'data' => ['products' => ['password' => ['show' => false]]]],
            '/cart/settle' => ['status' => 200, 'data' => ['invoiceid' => 42]],
            '/use_credit_page' => ['status' => 200, 'data' => ['total' => '1.00', 'currency' => ['code' => 'CNY']]],
            '/apply_credit' => ['status' => 1001, 'data' => ['hostid' => [999]]],
            '/host/header' => ['status' => 200, 'data' => ['host_data' => ['domainstatus' => 'Active']]],
            default => throw new RuntimeException('Unexpected historical trial request: '.$request->url()),
        });
    });
    $task = FulfillmentTask::sole();
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS)
        ->and($task->instance->fresh()->status)->toBe(InstanceStatus::ACTIVE)
        ->and($task->instance->fresh()->billing_cycle)->toBe(BillingCycle::ONTRIAL)
        ->and($invoice->fresh()->total_amount)->toBe('1.00');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/cart/add_to_shop') && $request['billingcycle'] === 'ontrial');
});
