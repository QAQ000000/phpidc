<?php

declare(strict_types=1);

use App\Actions\CreateComboInvoiceAction;
use App\Actions\ExecuteFulfillmentTaskAction;
use App\Actions\HandlePaymentCallbackAction;
use App\Data\VerifiedPaymentResult;
use App\Enums\BillingCycle;
use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Models\FulfillmentTask;
use App\Models\Product;
use App\Models\UpstreamApi;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

function classicPurchaseProduct(): Product
{
    $account = UpstreamApi::factory()->create();

    return Product::factory()->create(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id,
        'upstream_pid' => '17', 'markup_percent' => '20.00',
        'upstream_base_prices' => ['monthly' => ['price' => '8.00', 'setup_fee' => '0.00', 'enabled' => true]]]);
}

function fakeClassicPurchase(?Closure $override = null): void
{
    $level = DB::transactionLevel();
    Http::preventStrayRequests();
    Http::fake(function ($request) use ($override, $level) {
        expect(DB::transactionLevel())->toBe($level);
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($override !== null && ($response = $override($path, $request)) !== null) {
            return $response;
        }
        $body = match ($path) {
            '/zjmf_api_login' => ['status' => 200, 'jwt' => 'test-jwt'],
            '/cart/get_product_config' => ['status' => 200, 'data' => ['products' => ['password' => json_encode(['show' => 1,
                'rule' => ['upper' => 1, 'lower' => 1, 'num' => 1, 'special' => 1]])]]],
            '/user_info' => ['status' => 200, 'data' => ['user' => ['currency' => 1]]],
            '/cart/stock_control' => ['status' => 200, 'data' => ['product' => ['hidden' => 0, 'stock_control' => 1, 'qty' => 10]]],
            '/cart/get_total' => ['status' => 200, 'products' => ['total' => '8.00'], 'currency' => ['id' => 1, 'code' => 'CNY']],
            '/cart/clear', '/cart/add_to_shop' => ['status' => 200],
            '/cart/settle' => ['status' => 200, 'data' => ['invoiceid' => 42, 'hostid' => [999]]],
            '/use_credit_page' => ['status' => 200, 'data' => ['total' => '8.00', 'currency' => ['code' => 'CNY']]],
            '/apply_credit' => ['status' => 1001, 'data' => ['hostid' => [999]]],
            '/host/header' => ['status' => 200, 'data' => ['host_data' => ['domainstatus' => 'Active']]],
            default => throw new RuntimeException('Unexpected request: '.$path),
        };

        return Http::response($body);
    });
}

it('pays the upstream invoice cost even when the customer paid entirely from their wallet', function (): void {
    Queue::fake();
    fakeClassicPurchase();
    $product = classicPurchaseProduct();
    $user = User::factory()->create(['credit' => '100.00']);
    $invoice = app(CreateComboInvoiceAction::class)->execute($user, $product, BillingCycle::MONTHLY, [], true,
        input: ['hostname' => 'buyer-host', 'password' => 'StrongPassword12!']);
    expect($invoice->gateway_amount)->toBe('0.00')->and($invoice->total_amount)->toBe('9.60')
        ->and($invoice->upstream_expected_cost)->toBe('8.00')->and($user->fresh()->credit)->toBe('90.40');
    $task = FulfillmentTask::sole();
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS)
        ->and($task->instance->fresh()->status)->toBe(InstanceStatus::ACTIVE)
        ->and($task->instance->fresh()->upstream_instance_id)->toBe('999');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/cart/add_to_shop') && $r['currencyid'] === 1 && $r['currecncyid'] === 1);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/apply_credit') && $r['invoiceid'] === 42 && $r['use_credit'] === 1);
    $this->assertDatabaseCount('redemption_codes', 0);
    foreach (['invoices' => 'line_items', 'fulfillment_tasks' => 'payload', 'upstream_operations' => 'request_payload'] as $table => $column) {
        expect(DB::table($table)->pluck($column)->implode(' '))->not->toContain('StrongPassword12!');
    }
});

it('rejects upstream password rules before any wallet or invoice mutation', function (): void {
    fakeClassicPurchase();
    $user = User::factory()->create(['credit' => '100.00']);
    expect(fn () => app(CreateComboInvoiceAction::class)->execute($user, classicPurchaseProduct(), BillingCycle::MONTHLY, [], true,
        input: ['password' => 'weak']))->toThrow(ValidationException::class);
    expect($user->fresh()->credit)->toBe('100.00');
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
});

it('refuses checkout when upstream password rules cannot be obtained', function (): void {
    fakeClassicPurchase(fn ($path) => $path === '/cart/get_product_config' ? Http::response(['status' => 200, 'data' => ['products' => []]]) : null);
    $user = User::factory()->create(['credit' => '100.00']);
    expect(fn () => app(CreateComboInvoiceAction::class)->execute($user, classicPurchaseProduct(), BillingCycle::MONTHLY, [], true,
        input: ['password' => 'StrongPassword12!']))->toThrow(ValidationException::class);
    expect($user->fresh()->credit)->toBe('100.00');
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
});

it('rejects upstream cycles that the local product does not offer', function (): void {
    fakeClassicPurchase();
    $product = classicPurchaseProduct();
    $product->update(['upstream_base_prices' => ['monthly' => ['price' => '8.00', 'setup_fee' => '0.00', 'enabled' => false]]]);
    $user = User::factory()->create(['credit' => '100.00']);
    expect(fn () => app(CreateComboInvoiceAction::class)->execute($user, $product, BillingCycle::MONTHLY, [], true,
        input: ['password' => 'StrongPassword12!']))->toThrow(ValidationException::class);
    expect($user->fresh()->credit)->toBe('100.00');
    $this->assertDatabaseCount('invoices', 0);
    Http::assertNotSent(fn ($r) => parse_url($r->url(), PHP_URL_PATH) === '/cart/get_total');
});

it('preserves upstream selections without publishing private custom fields or claiming local option mappings', function (): void {
    Queue::fake();
    fakeClassicPurchase();
    app(CreateComboInvoiceAction::class)->execute(User::factory()->create(['credit' => '100.00']), classicPurchaseProduct(),
        BillingCycle::MONTHLY, ['configoption' => [50 => 501], 'customfield' => [10 => 'private-custom-value']], true,
        input: ['password' => 'StrongPassword12!']);
    $task = FulfillmentTask::sole();
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS)
        ->and($task->instance->fresh()->active_config['options'])->toBe([])
        ->and($task->instance->fresh()->active_config['upstream_configoption'])->toBe([50 => 501]);
    Http::assertSent(fn ($r) => parse_url($r->url(), PHP_URL_PATH) === '/cart/settle'
        && $r['cart_data']['configoptions'][50] === 501 && $r['cart_data']['customfield'][10] === 'private-custom-value');
    foreach (['invoices' => 'line_items', 'fulfillment_tasks' => 'payload', 'instances' => 'active_config', 'upstream_operations' => 'request_payload'] as $table => $column) {
        expect(DB::table($table)->pluck($column)->implode(' '))->not->toContain('private-custom-value');
    }
});

it('refuses to pay an upstream invoice whose cost drifted after customer checkout', function (): void {
    Queue::fake();
    fakeClassicPurchase(fn ($path) => $path === '/use_credit_page' ? Http::response(['status' => 200,
        'data' => ['total' => '9.00', 'currency' => ['code' => 'CNY']]]) : null);
    app(CreateComboInvoiceAction::class)->execute(User::factory()->create(['credit' => '100.00']), classicPurchaseProduct(),
        BillingCycle::MONTHLY, [], true, input: ['password' => 'StrongPassword12!']);
    $task = FulfillmentTask::sole();
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::FAILED);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/apply_credit'));
});

it('recovers a lost settlement response through P2 without submitting the cart a second time', function (): void {
    Queue::fake();
    $settled = false;
    fakeClassicPurchase(function ($path) use (&$settled) {
        if ($path === '/cart/clear' && $settled) {
            return Http::response(['status' => 200, 'invoiceid' => 42, 'hostid' => 999]);
        }
        if ($path === '/cart/settle') {
            $settled = true;

            return Http::response('Lost response', 502);
        }

        return null;
    });
    app(CreateComboInvoiceAction::class)->execute(User::factory()->create(['credit' => '100.00']), classicPurchaseProduct(),
        BillingCycle::MONTHLY, [], true, input: ['password' => 'StrongPassword12!']);
    $task = FulfillmentTask::sole();
    $action = app(ExecuteFulfillmentTaskAction::class);
    $action->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::PENDING);
    $this->travel(61)->seconds();
    $action->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS);
    $paths = Http::recorded()->map(fn ($pair) => parse_url($pair[0]->url(), PHP_URL_PATH));
    expect($paths->filter(fn ($path) => $path === '/cart/add_to_shop')->count())->toBe(1)
        ->and($paths->filter(fn ($path) => $path === '/cart/settle')->count())->toBe(1)
        ->and($paths->filter(fn ($path) => $path === '/apply_credit')->count())->toBe(1);
});

it('keeps a gateway-paid purchase snapshot encrypted until the same upstream cost is settled', function (): void {
    Queue::fake();
    fakeClassicPurchase();
    $user = User::factory()->create(['credit' => '3.00']);
    $invoice = app(CreateComboInvoiceAction::class)->execute($user, classicPurchaseProduct(), BillingCycle::MONTHLY, [], true,
        gateway: 'epay_wechat', input: ['password' => 'StrongPassword12!']);
    expect($invoice->gateway_amount)->toBe('6.60')->and($user->fresh()->frozen_credit)->toBe('3.00');
    $this->assertDatabaseCount('instances', 0);
    app(HandlePaymentCallbackAction::class)->execute(new VerifiedPaymentResult('epay_wechat', 'CLASSIC-PAID', $invoice->invoice_no, '6.60'));
    $task = FulfillmentTask::sole();
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS)
        ->and($user->fresh()->frozen_credit)->toBe('0.00')->and($invoice->fresh()->upstream_expected_cost)->toBe('8.00');
});

it('discards an upstream result after the worker loses its lease and preserves recovery evidence', function (): void {
    Queue::fake();
    fakeClassicPurchase(function ($path) {
        if ($path === '/apply_credit') {
            DB::table('fulfillment_tasks')->update(['locked_by' => 'replacement-worker']);

            return Http::response(['status' => 1001, 'data' => ['hostid' => [999]]]);
        }

        return null;
    });
    app(CreateComboInvoiceAction::class)->execute(User::factory()->create(['credit' => '100.00']), classicPurchaseProduct(),
        BillingCycle::MONTHLY, [], true, input: ['password' => 'StrongPassword12!']);
    $task = FulfillmentTask::sole();
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->instance->fresh()->status)->toBe(InstanceStatus::PENDING)
        ->and($task->instance->fresh()->upstream_instance_id)->toBeNull()
        ->and($task->fresh()->locked_by)->toBe('replacement-worker');
    $this->assertDatabaseHas('upstream_operations', ['path' => '/apply_credit', 'outcome' => 'ok']);
});

it('adopts the P2 host after a lost payment response without paying upstream twice', function (): void {
    Queue::fake();
    $paid = false;
    fakeClassicPurchase(function ($path) use (&$paid) {
        if ($path === '/apply_credit') {
            $paid = true;

            return Http::response('Lost payment response', 502);
        }
        if ($path === '/cart/clear' && $paid) {
            return Http::response(['status' => 400, 'domainstatus' => 'Active', 'hostid' => 999]);
        }

        return null;
    });
    app(CreateComboInvoiceAction::class)->execute(User::factory()->create(['credit' => '100.00']), classicPurchaseProduct(),
        BillingCycle::MONTHLY, [], true, input: ['password' => 'StrongPassword12!']);
    $task = FulfillmentTask::sole();
    $action = app(ExecuteFulfillmentTaskAction::class);
    $action->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::PENDING);
    $this->assertDatabaseHas('upstream_operations', ['path' => '/apply_credit', 'outcome' => 'ambiguous']);
    $this->travel(61)->seconds();
    $action->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS);
    expect(Http::recorded()->filter(fn ($pair) => parse_url($pair[0]->url(), PHP_URL_PATH) === '/apply_credit')->count())->toBe(1);
});

it('alerts and stops automatic retry when an explicitly enabled credit-limit fallback cannot roll back', function (): void {
    Queue::fake();
    Log::spy();
    $product = classicPurchaseProduct();
    UpstreamApi::whereKey($product->upstream_api_id)->update(['config' => ['allow_credit_limit' => true]]);
    fakeClassicPurchase(fn ($path) => in_array($path, ['/apply_credit', '/apply_credit_limit'], true) ? Http::response(['status' => 400]) : null);
    app(CreateComboInvoiceAction::class)->execute(User::factory()->create(['credit' => '100.00']), $product,
        BillingCycle::MONTHLY, [], true, input: ['password' => 'StrongPassword12!']);
    $task = FulfillmentTask::sole();
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::FAILED)
        ->and($task->fresh()->error_message)->toContain('rollback failed');
    Log::shouldHaveReceived('critical')->once();
    $this->assertDatabaseHas('upstream_operations', ['path' => '/apply_credit_limit', 'outcome' => 'failed']);
    Http::assertSent(fn ($r) => parse_url($r->url(), PHP_URL_PATH) === '/apply_credit' && $r['use_credit'] === 0);
});

it('keeps an asynchronously created upstream host pending until an authenticated probe reports Active', function (): void {
    Queue::fake();
    fakeClassicPurchase(fn ($path) => $path === '/host/header' ? Http::response(['status' => 200, 'data' => ['host_data' => ['domainstatus' => 'Pending']]]) : null);
    app(CreateComboInvoiceAction::class)->execute(User::factory()->create(['credit' => '100.00']), classicPurchaseProduct(),
        BillingCycle::MONTHLY, [], true, input: ['password' => 'StrongPassword12!']);
    $task = FulfillmentTask::sole();
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::PENDING)
        ->and($task->instance->fresh()->status)->toBe(InstanceStatus::PENDING)
        ->and($task->instance->fresh()->encrypted_credentials)->toBeNull();
});
