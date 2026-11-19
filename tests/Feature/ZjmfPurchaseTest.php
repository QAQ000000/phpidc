<?php

declare(strict_types=1);

use App\Actions\CreateComboInvoiceAction;
use App\Actions\CreatePurchaseFulfillmentAction;
use App\Actions\DispatchStalePendingTasksAction;
use App\Actions\ExecuteFulfillmentTaskAction;
use App\Actions\HandlePaymentCallbackAction;
use App\Data\VerifiedPaymentResult;
use App\Enums\BillingCycle;
use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Models\FinancialTransaction;
use App\Models\FulfillmentTask;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Services\DriverManager;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function classicPurchaseProduct(): Product
{
    $account = UpstreamApi::factory()->create();

    return Product::factory()->create(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id,
        'upstream_pid' => '17', 'markup_percent' => '20.00',
        'provisioning_rules' => ['classic_catalog' => ['product_publisher_uid' => 0]],
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
            '/cart/get_total' => ['status' => 200, 'products' => ['total' => '8.00', 'setupfee_total' => '0.00'], 'currency' => ['id' => 1, 'code' => 'CNY']],
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
        input: ['request_id' => (string) Str::ulid(), 'hostname' => 'buyer-host', 'password' => 'StrongPassword12!']);
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

it('quotes and provisions with the classic top-level user currency response', function (): void {
    Queue::fake();
    fakeClassicPurchase(fn ($path) => $path === '/user_info'
        ? Http::response(['status' => 200, 'user' => ['currency' => 1]]) : null);
    $user = User::factory()->create(['credit' => '100.00']);
    $invoice = app(CreateComboInvoiceAction::class)->execute($user, classicPurchaseProduct(), BillingCycle::MONTHLY, [], true,
        input: ['request_id' => (string) Str::ulid(), 'hostname' => 'buyer-host', 'password' => 'StrongPassword12!']);
    expect($invoice->upstream_expected_cost)->toBe('8.00')
        ->and($user->fresh()->credit)->toBe('90.40');
    $task = FulfillmentTask::sole();
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS)
        ->and($task->instance->fresh()->upstream_instance_id)->toBe('999');
    Http::assertSent(fn ($r) => parse_url($r->url(), PHP_URL_PATH) === '/cart/get_total' && $r['currencyid'] === 1);
    Http::assertSent(fn ($r) => parse_url($r->url(), PHP_URL_PATH) === '/cart/add_to_shop'
        && $r['currencyid'] === 1 && $r['currecncyid'] === 1);
});

it('charges setup once and excludes it from recurring and renewal prices', function (array $pricing, string $cost, string $period, string $price, string $setupPrice, string $total, string $balance): void {
    Queue::fake();
    fakeClassicPurchase(function ($path) use ($pricing, $cost, $period) {
        return match ($path) {
            '/cart/get_total' => Http::response(['status' => 200, 'products' => $pricing, 'currency' => ['code' => 'CNY']]),
            '/use_credit_page' => Http::response(['status' => 200, 'data' => ['total' => $cost, 'currency' => ['code' => 'CNY']]]),
            '/host/renewpage' => Http::response(['status' => 200, 'data' => ['host' => ['amount' => $period, 'billingcycle' => 'monthly'], 'currency' => ['code' => 'CNY']]]),
            default => null,
        };
    });
    $user = User::factory()->create(['credit' => '100.00']);
    $invoice = app(CreateComboInvoiceAction::class)->execute($user, classicPurchaseProduct(), BillingCycle::MONTHLY, [], true,
        input: ['request_id' => (string) Str::ulid(), 'hostname' => 'buyer-host', 'password' => 'StrongPassword12!']);
    expect($invoice->upstream_expected_cost)->toBe($cost)->and($invoice->total_amount)->toBe($total)
        ->and($invoice->line_items['items'][0]['amount'])->toBe($price)
        ->and($invoice->line_items['items'][1]['kind'])->toBe('setup_fee')
        ->and($invoice->line_items['items'][1]['amount'])->toBe($setupPrice)
        ->and($user->fresh()->credit)->toBe($balance)->and($user->fresh()->frozen_credit)->toBe('0.00');
    $task = FulfillmentTask::sole();
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    $instance = $task->instance->fresh();
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS)->and($instance->recurring_amount)->toBe($price)
        ->and(FinancialTransaction::where('reference_key', 'settle:'.$invoice->id)->sole()->amount)->toBe($total);
    $renewal = app(DriverManager::class)->classicAccount($instance->upstream_api_id)->quoteRenewal($instance, 'monthly');
    expect($renewal->upstreamBaseCost)->toBe($period)->and($renewal->downstreamPrice)->toBe($price)->and($renewal->setupFee)->toBe('0.00');
    expect(Http::recorded()->filter(fn ($pair) => parse_url($pair[0]->url(), PHP_URL_PATH) === '/apply_credit')->count())->toBe(1);
})->with([
    'regular' => [['total' => '11.00', 'setupfee_total' => '3.00', 'type' => false], '11.00', '8.00', '9.60', '3.60', '13.20', '86.80'],
    'discounted' => [['total' => '11.00', 'setupfee_total' => '3.00', 'type' => 1, 'sale_price' => '4.00', 'sale_setupfee_total' => '2.00'], '6.00', '4.00', '4.80', '2.40', '7.20', '92.80'],
]);

it('rejects incomplete or inconsistent setup quotes before touching the wallet', function (array $pricing): void {
    fakeClassicPurchase(fn ($path) => $path === '/cart/get_total'
        ? Http::response(['status' => 200, 'products' => $pricing, 'currency' => ['code' => 'CNY']]) : null);
    $user = User::factory()->create(['credit' => '100.00']);
    expect(fn () => app(CreateComboInvoiceAction::class)->execute($user, classicPurchaseProduct(), BillingCycle::MONTHLY, [], true,
        input: ['request_id' => (string) Str::ulid(), 'password' => 'StrongPassword12!']))->toThrow(ValidationException::class);
    expect($user->fresh()->credit)->toBe('100.00')->and($user->fresh()->frozen_credit)->toBe('0.00');
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
    Http::assertNotSent(fn ($r) => in_array(parse_url($r->url(), PHP_URL_PATH), ['/cart/settle', '/apply_credit'], true));
})->with([
    'missing setup' => [['total' => '8.00']],
    'setup exceeds total' => [['total' => '8.00', 'setupfee_total' => '9.00']],
    'incomplete sale' => [['total' => '8.00', 'setupfee_total' => '0.00', 'type' => 1, 'sale_price' => '4.00']],
]);

it('rejects upstream password rules before any wallet or invoice mutation', function (): void {
    fakeClassicPurchase();
    $user = User::factory()->create(['credit' => '100.00']);
    expect(fn () => app(CreateComboInvoiceAction::class)->execute($user, classicPurchaseProduct(), BillingCycle::MONTHLY, [], true,
        input: ['request_id' => (string) Str::ulid(), 'password' => 'weak']))->toThrow(ValidationException::class);
    expect($user->fresh()->credit)->toBe('100.00');
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
});

it('refuses checkout when upstream password rules cannot be obtained', function (): void {
    fakeClassicPurchase(fn ($path) => $path === '/cart/get_product_config' ? Http::response(['status' => 200, 'data' => ['products' => []]]) : null);
    $user = User::factory()->create(['credit' => '100.00']);
    expect(fn () => app(CreateComboInvoiceAction::class)->execute($user, classicPurchaseProduct(), BillingCycle::MONTHLY, [], true,
        input: ['request_id' => (string) Str::ulid(), 'password' => 'StrongPassword12!']))->toThrow(ValidationException::class);
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
        input: ['request_id' => (string) Str::ulid(), 'password' => 'StrongPassword12!']))->toThrow(ValidationException::class);
    expect($user->fresh()->credit)->toBe('100.00');
    $this->assertDatabaseCount('invoices', 0);
    Http::assertNotSent(fn ($r) => parse_url($r->url(), PHP_URL_PATH) === '/cart/get_total');
});

it('preserves upstream selections without publishing private custom fields or claiming local option mappings', function (): void {
    Queue::fake();
    fakeClassicPurchase();
    // S1 stops new unsynchronized sales; already-paid legacy purchases retain their encrypted delivery inputs.
    $product = classicPurchaseProduct();
    $product->update(['provisioning_rules' => null]);
    $params = ['upstream_pid' => '17', 'expected_cost' => '8.00', 'password' => 'StrongPassword12!',
        'options' => ['configoption' => [50 => 501], 'customfield' => [10 => 'private-custom-value']]];
    $invoice = Invoice::factory()->paid()->create(['user_id' => User::factory()->create(['credit' => '90.40'])->id,
        'product_id' => $product->id, 'total_amount' => '9.60', 'credit_applied' => '9.60', 'gateway_amount' => '0.00',
        'upstream_expected_cost' => '8.00', 'line_items' => ['purchase' => ['billing_cycle' => 'monthly', 'upstream_pid' => '17',
            'options' => ['configoption' => [50 => 501]], 'encrypted_params' => Crypt::encryptString(json_encode($params, JSON_THROW_ON_ERROR))]]]);
    DB::transaction(fn () => app(CreatePurchaseFulfillmentAction::class)->execute($invoice));
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
        BillingCycle::MONTHLY, [], true, input: ['request_id' => (string) Str::ulid(), 'password' => 'StrongPassword12!']);
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
        BillingCycle::MONTHLY, [], true, input: ['request_id' => (string) Str::ulid(), 'password' => 'StrongPassword12!']);
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

it('recovers a crashed classic purchase through P2 after lease recovery without rebuilding its cart', function (): void {
    Queue::fake();
    $settled = false;
    fakeClassicPurchase(function ($path) use (&$settled) {
        if ($path === '/cart/clear' && $settled) {
            return Http::response(['status' => 200, 'invoiceid' => 42, 'hostid' => 999]);
        }
        if ($path === '/cart/settle') {
            $settled = true;
            throw new RuntimeException('C21 interrupted settlement.');
        }

        return null;
    });
    $buyer = User::factory()->create(['credit' => '100.00']);
    $invoice = app(CreateComboInvoiceAction::class)->execute($buyer, classicPurchaseProduct(), BillingCycle::MONTHLY,
        [], true, input: ['request_id' => (string) Str::ulid(), 'password' => 'StrongPassword12!']);
    $task = FulfillmentTask::sole();
    expect(fn () => app(ExecuteFulfillmentTaskAction::class)->execute($task->id))->toThrow(RuntimeException::class, 'C21 interrupted settlement.');
    expect($task->fresh()->attempts)->toBe(1);
    $this->travel(211)->seconds();
    app(DispatchStalePendingTasksAction::class)->execute();
    expect($task->fresh()->attempts)->toBe(1)->and($task->fresh()->status)->toBe(FulfillmentTaskStatus::PENDING);
    $this->travel(61)->seconds();
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->attempts)->toBe(2)->and($task->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS)
        ->and($task->instance->fresh()->upstream_instance_id)->toBe('999')
        ->and($buyer->fresh()->credit)->toBe('90.40')->and($invoice->fresh()->upstream_expected_cost)->toBe('8.00');
    $paths = Http::recorded()->map(fn ($pair) => parse_url($pair[0]->url(), PHP_URL_PATH));
    expect($paths->filter(fn ($path) => $path === '/cart/add_to_shop')->count())->toBe(1)
        ->and($paths->filter(fn ($path) => $path === '/apply_credit')->count())->toBe(1);
    expect(FinancialTransaction::where('reference_key', 'settle:'.$invoice->id)->count())->toBe(1);
});

it('keeps a gateway-paid purchase snapshot encrypted until the same upstream cost is settled', function (): void {
    Queue::fake();
    fakeClassicPurchase();
    $user = User::factory()->create(['credit' => '3.00']);
    $invoice = app(CreateComboInvoiceAction::class)->execute($user, classicPurchaseProduct(), BillingCycle::MONTHLY, [], true,
        gateway: 'epay_wechat', input: ['request_id' => (string) Str::ulid(), 'password' => 'StrongPassword12!']);
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
        BillingCycle::MONTHLY, [], true, input: ['request_id' => (string) Str::ulid(), 'password' => 'StrongPassword12!']);
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
        BillingCycle::MONTHLY, [], true, input: ['request_id' => (string) Str::ulid(), 'password' => 'StrongPassword12!']);
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
        BillingCycle::MONTHLY, [], true, input: ['request_id' => (string) Str::ulid(), 'password' => 'StrongPassword12!']);
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
        BillingCycle::MONTHLY, [], true, input: ['request_id' => (string) Str::ulid(), 'password' => 'StrongPassword12!']);
    $task = FulfillmentTask::sole();
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::PENDING)
        ->and($task->instance->fresh()->status)->toBe(InstanceStatus::PENDING)
        ->and($task->instance->fresh()->encrypted_credentials)->toBeNull();
});
