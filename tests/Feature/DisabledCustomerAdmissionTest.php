<?php

declare(strict_types=1);

use App\Actions\ConfigureEpayAction;
use App\Actions\ConfigureWalletTopupAction;
use App\Actions\CreateComboInvoiceAction;
use App\Actions\CreateWalletTopupInvoiceAction;
use App\Actions\ExecuteFulfillmentTaskAction;
use App\Actions\ExpireUnpaidInvoicesAction;
use App\Actions\HandlePaymentCallbackAction;
use App\Actions\InitiatePaymentAction;
use App\Actions\ProcessInstanceLifecycleAction;
use App\Actions\QuoteRenewalRecoveryAction;
use App\Data\ProvisionResult;
use App\Data\VerifiedPaymentResult;
use App\Drivers\SelfHosted\RedemptionCodeDriver;
use App\Enums\BillingCycle;
use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Models\FinancialTransaction;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\PaymentCallback;
use App\Models\Product;
use App\Models\RedemptionCode;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Services\SettingService;
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
    $this->travelTo(now()->startOfSecond());
    app(ConfigureEpayAction::class)->execute('https://pay.example.test', 'c2-isolated-merchant', 'c2-isolated-key', 'Isolated C2 payment configuration');
    DB::table('system_settings')->updateOrInsert(['group' => 'wallet', 'key' => 'topup_enabled'], [
        'value' => '1', 'is_encrypted' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(SettingService::class)->forget('wallet.topup_enabled');
});

function disabledAdmissionProduct(bool $classic = false): Product
{
    return Product::factory()->create($classic ? [
        'driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => UpstreamApi::factory()->create()->id,
        'upstream_pid' => '17', 'markup_percent' => '0.00',
        'provisioning_rules' => ['classic_catalog' => ['product_publisher_uid' => 0]],
    ] : ['local_prices' => ['onetime' => ['price' => '10.00', 'enabled' => true]]]);
}

function disabledAdmissionInstance(User $user): Instance
{
    $product = disabledAdmissionProduct(true);

    return Instance::factory()->active()->create([
        'user_id' => $user->id, 'product_id' => $product->id, 'driver_type' => DriverType::ZJMF_API,
        'upstream_api_id' => $product->upstream_api_id, 'upstream_instance_id' => '99',
        'next_due_at' => now()->addDay(), 'billing_cycle' => BillingCycle::MONTHLY,
        'recurring_amount' => '10.00', 'active_config' => [],
    ]);
}

/** These fixtures exercise real Actions/drivers, with all counterparties simulated. */
function disabledAdmissionSupplier(?Closure $hook = null): object
{
    $state = (object) ['due' => now()->addDay()->toIso8601String(), 'calls' => [], 'hook' => $hook];
    $level = DB::transactionLevel();
    Http::fake(function ($request) use ($state, $level) {
        expect(DB::transactionLevel())->toBe($level);
        $path = parse_url($request->url(), PHP_URL_PATH);
        $state->calls[] = $path;
        if ($state->hook !== null) {
            ($state->hook)($path);
        }

        return Http::response(match ($path) {
            '/zjmf_api_login' => ['status' => 200, 'jwt' => 'c2-isolated-classic-jwt'],
            '/cart/get_product_config' => ['status' => 200, 'data' => ['products' => ['password' => json_encode([
                'show' => 1, 'rule' => ['upper' => 1, 'lower' => 1, 'num' => 1, 'special' => 1],
            ])]]],
            '/user_info' => ['status' => 200, 'data' => ['user' => ['currency' => 1]]],
            '/cart/stock_control' => ['status' => 200, 'data' => ['product' => ['hidden' => 0, 'stock_control' => 1, 'qty' => 10]]],
            '/cart/get_total' => ['status' => 200, 'products' => ['total' => '10.00', 'setupfee_total' => '0.00'], 'currency' => ['id' => 1, 'code' => 'CNY']],
            '/host/header' => ['status' => 200, 'data' => ['host_data' => ['domainstatus' => 'Active', 'nextduedate' => $state->due]]],
            '/host/renewpage' => ['status' => 200, 'data' => ['host' => ['amount' => '10.00', 'billingcycle' => 'monthly'], 'currency' => ['code' => 'CNY']]],
            default => throw new LogicException('Unexpected C2 isolated supplier request: '.$path),
        });
    });

    return $state;
}

function disableAdmissionUser(User $user): void
{
    // Keep the caller model stale: Actions must use current persisted state.
    DB::table('users')->where('id', $user->id)->update(['disabled_at' => now()]);
}

function expectDisabledAdmission(Closure $action): void
{
    expect($action)->toThrow(ValidationException::class, '账户当前无法发起新业务');
}

it('blocks disabled customer purchases before supplier access or financial mutation', function (bool $classic): void {
    $user = User::factory()->create(['credit' => '100.00']);
    $product = disabledAdmissionProduct($classic);
    disableAdmissionUser($user);
    expect($user->isAccessEnabled())->toBeTrue()->and($user->fresh()->isAccessEnabled())->toBeFalse();

    expectDisabledAdmission(fn () => app(CreateComboInvoiceAction::class)->execute($user, $product,
        $classic ? BillingCycle::MONTHLY : BillingCycle::ONETIME, [], true, 'epay_wechat', ['request_id' => (string) Str::ulid()]));

    expect($user->fresh()->credit)->toBe('100.00')->and($user->fresh()->frozen_credit)->toBe('0.00');
    foreach (['invoices', 'financial_transactions', 'coupon_usages', 'instances', 'fulfillment_tasks', 'payment_attempts'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    Http::assertNothingSent();
})->with(['local' => false, 'classic' => true]);

it('retains an exact purchase replay after disablement without repeating wallet or fulfillment writes', function (): void {
    $user = User::factory()->create(['credit' => '3.00']);
    $product = disabledAdmissionProduct();
    $input = ['request_id' => (string) Str::ulid()];
    $action = app(CreateComboInvoiceAction::class);
    $invoice = $action->execute($user, $product, BillingCycle::ONETIME, [], true, 'epay_wechat', $input);
    $before = $invoice->fresh()->getRawOriginal();
    disableAdmissionUser($user);

    expect($action->execute($user, $product, BillingCycle::ONETIME, [], true, 'epay_wechat', $input)->getRawOriginal())->toBe($before);
    expect($user->fresh()->credit)->toBe('0.00')->and($user->fresh()->frozen_credit)->toBe('3.00');
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseCount('financial_transactions', 1);
    $this->assertDatabaseCount('fulfillment_tasks', 0);
    Http::assertNothingSent();
});

it('rechecks a disablement during live purchase quotation under the user lock', function (): void {
    $user = User::factory()->create(['credit' => '100.00']);
    $product = disabledAdmissionProduct(true);
    $state = disabledAdmissionSupplier(function ($path) use ($user): void {
        if ($path === '/cart/get_total') {
            disableAdmissionUser($user);
        }
    });
    expectDisabledAdmission(fn () => app(CreateComboInvoiceAction::class)->execute($user, $product, BillingCycle::MONTHLY, [], true,
        input: ['request_id' => (string) Str::ulid(), 'hostname' => 'c2-isolated-host', 'password' => 'StrongIsolated12!']));

    expect($state->calls)->toContain('/cart/get_total')->and($user->fresh()->disabled_at)->not->toBeNull()
        ->and($user->fresh()->credit)->toBe('100.00')->and($user->fresh()->frozen_credit)->toBe('0.00');
    foreach (['invoices', 'financial_transactions', 'coupon_usages', 'fulfillment_tasks'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
});

it('blocks new topups while preserving exact existing topup reads after disablement', function (): void {
    $user = User::factory()->create(['credit' => '3.00']);
    $input = ['request_id' => (string) Str::ulid(), 'amount' => '10.00', 'gateway' => 'epay_wechat'];
    $action = app(CreateWalletTopupInvoiceAction::class);
    $invoice = $action->execute($user, $input);
    $before = $invoice->fresh()->getRawOriginal();
    disableAdmissionUser($user);
    expect($action->execute($user, $input)->getRawOriginal())->toBe($before);
    expectDisabledAdmission(fn () => $action->execute($user, array_replace($input, ['request_id' => (string) Str::ulid()])));
    expect($user->fresh()->credit)->toBe('3.00');
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseCount('financial_transactions', 0);
    Http::assertNothingSent();
});

it('checks topup access again after configuration locking', function (): void {
    $user = User::factory()->create(['credit' => '3.00']);
    $configuration = Mockery::mock(ConfigureWalletTopupAction::class)->makePartial();
    $configuration->shouldReceive('lockConfiguration')->once()->andReturnUsing(function () use ($user): void {
        disableAdmissionUser($user);
    });
    $this->app->instance(ConfigureWalletTopupAction::class, $configuration);

    expectDisabledAdmission(fn () => app(CreateWalletTopupInvoiceAction::class)->execute($user,
        ['request_id' => (string) Str::ulid(), 'amount' => '10.00', 'gateway' => 'epay_wechat']));
    expect($user->fresh()->credit)->toBe('3.00');
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
    Http::assertNothingSent();
});

it('blocks disabled human renewal and upgrade before pre-sync', function (string $operation): void {
    $user = User::factory()->create(['credit' => '100.00']);
    $instance = disabledAdmissionInstance($user);
    disableAdmissionUser($user);
    $action = app(CreateComboInvoiceAction::class);
    expectDisabledAdmission(fn () => $operation === 'renew'
        ? $action->renewal($user, $instance, true, 'epay_wechat', (string) Str::ulid())
        : $action->upgrade($user, $instance, [], (string) Str::ulid(), true));
    expect($user->fresh()->credit)->toBe('100.00');
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
    Http::assertNothingSent();
})->with(['renew', 'upgrade']);

it('stops a renewal before the next supplier call when the owner is disabled during sync', function (): void {
    $user = User::factory()->create(['credit' => '100.00']);
    $instance = disabledAdmissionInstance($user);
    $state = disabledAdmissionSupplier(function ($path) use ($user): void {
        if ($path === '/host/header') {
            disableAdmissionUser($user);
        }
    });
    expectDisabledAdmission(fn () => app(CreateComboInvoiceAction::class)->renewal($user, $instance, true));
    expect($state->calls)->toContain('/host/header')->not->toContain('/host/renewpage');
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
});

it('rechecks a renewal quotation before creating an invoice and freezing the wallet', function (): void {
    $user = User::factory()->create(['credit' => '100.00']);
    $instance = disabledAdmissionInstance($user);
    disabledAdmissionSupplier(function ($path) use ($user): void {
        if ($path === '/host/renewpage') {
            disableAdmissionUser($user);
        }
    });
    expectDisabledAdmission(fn () => app(CreateComboInvoiceAction::class)->renewal($user, $instance, true));
    expect($user->fresh()->credit)->toBe('100.00')->and($user->fresh()->frozen_credit)->toBe('0.00');
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('fulfillment_tasks', 0);
});

it('retains an exact human renewal request replay while disabled without supplier calls', function (): void {
    $user = User::factory()->create(['credit' => '3.00']);
    $instance = disabledAdmissionInstance($user);
    $state = disabledAdmissionSupplier();
    $request = (string) Str::ulid();
    $action = app(CreateComboInvoiceAction::class);
    $invoice = $action->renewal($user, $instance, true, 'epay_wechat', $request);
    $before = $invoice->fresh()->getRawOriginal();
    $calls = $state->calls;
    disableAdmissionUser($user);
    expect($action->renewal($user, $instance, true, 'epay_wechat', $request)->getRawOriginal())->toBe($before);
    expect($state->calls)->toBe($calls)->and($user->fresh()->credit)->toBe('0.00')->and($user->fresh()->frozen_credit)->toBe('3.00');
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseCount('financial_transactions', 1);
});

it('keeps trusted automatic lifecycle invoicing and notification events for disabled customers', function (): void {
    $user = User::factory()->create(['credit' => '3.00']);
    $instance = disabledAdmissionInstance($user);
    disabledAdmissionSupplier();
    disableAdmissionUser($user);

    expect(app(ProcessInstanceLifecycleAction::class)->process($instance))->toBeTrue();
    $invoice = Invoice::sole();
    expect($invoice->line_items['automatic_renewal']['selection_pending'])->toBeTrue()
        ->and($invoice->status)->toBe(InvoiceStatus::UNPAID)->and($invoice->credit_applied)->toBe('0.00')
        ->and($invoice->gateway_amount)->toBe('10.00')->and($user->fresh()->credit)->toBe('3.00');
    $this->assertDatabaseHas('notification_events', ['user_id' => $user->id, 'event_key' => 'invoice:'.$invoice->id.':renewal-created']);
    $this->assertDatabaseCount('financial_transactions', 0);
});

it('rejects automatic renewal wallet allocation when disablement occurs during its pre-sync', function (): void {
    $user = User::factory()->create(['credit' => '3.00']);
    $instance = disabledAdmissionInstance($user);
    $state = disabledAdmissionSupplier();
    $action = app(CreateComboInvoiceAction::class);
    $invoice = $action->automaticRenewal($user, $instance);
    $before = $invoice->fresh()->getRawOriginal();
    $state->hook = function ($path) use ($user): void {
        if ($path === '/host/header') {
            disableAdmissionUser($user);
        }
    };
    expectDisabledAdmission(fn () => $action->renewal($user, $instance, true, 'epay_wechat', (string) Str::ulid()));
    expect($invoice->fresh()->getRawOriginal())->toBe($before)
        ->and($user->fresh()->credit)->toBe('3.00')->and($user->fresh()->frozen_credit)->toBe('0.00');
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('fulfillment_tasks', 0);
});

it('preserves completed automatic renewal allocation replay for a disabled customer', function (): void {
    $user = User::factory()->create(['credit' => '3.00']);
    $instance = disabledAdmissionInstance($user);
    $state = disabledAdmissionSupplier();
    $action = app(CreateComboInvoiceAction::class);
    $action->automaticRenewal($user, $instance);
    $request = (string) Str::ulid();
    $invoice = $action->renewal($user, $instance, true, 'epay_wechat', $request);
    $before = $invoice->fresh()->getRawOriginal();
    $calls = $state->calls;
    disableAdmissionUser($user);
    expect($action->renewal($user, $instance, true, 'epay_wechat', $request)->getRawOriginal())->toBe($before);
    expect($state->calls)->toBe($calls)->and($user->fresh()->credit)->toBe('0.00')->and($user->fresh()->frozen_credit)->toBe('3.00');
    $this->assertDatabaseCount('financial_transactions', 1);
});

it('blocks new recovery quotes and confirmations while preserving an existing recovery replay', function (): void {
    $user = User::factory()->create(['credit' => '3.00']);
    $instance = disabledAdmissionInstance($user);
    $state = disabledAdmissionSupplier();
    $action = app(CreateComboInvoiceAction::class);
    $root = $action->renewal($user, $instance);
    $root->update(['expires_at' => now()->subSecond()]);
    app(ExpireUnpaidInvoicesAction::class)->execute();
    $quote = app(QuoteRenewalRecoveryAction::class)->execute($user, $root, true, 'epay_wechat', null);
    $request = (string) Str::ulid();
    disableAdmissionUser($user);
    $calls = $state->calls;
    expectDisabledAdmission(fn () => app(QuoteRenewalRecoveryAction::class)->execute($user, $root, true, 'epay_wechat', null));
    expectDisabledAdmission(fn () => $action->recoverRenewal($user, $root, $request, $quote['quote_token'], true, 'epay_wechat', null, 'Isolated recovery'));
    expect($state->calls)->toBe($calls);
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseCount('financial_transactions', 0);

    DB::table('users')->where('id', $user->id)->update(['disabled_at' => null]);
    $invoice = $action->recoverRenewal($user, $root, $request, $quote['quote_token'], true, 'epay_wechat', null, 'Isolated recovery');
    $before = $invoice->fresh()->getRawOriginal();
    $calls = $state->calls;
    disableAdmissionUser($user);
    expect($action->recoverRenewal($user, $root, $request, $quote['quote_token'], true, 'epay_wechat', null, 'Isolated recovery')->getRawOriginal())->toBe($before);
    expect($state->calls)->toBe($calls)->and($user->fresh()->credit)->toBe('0.00')->and($user->fresh()->frozen_credit)->toBe('3.00');
    $this->assertDatabaseCount('invoices', 2);
    $this->assertDatabaseCount('financial_transactions', 1);
});

it('rechecks recovery quotation access after supplier I/O before issuing a token', function (): void {
    $user = User::factory()->create(['credit' => '3.00']);
    $instance = disabledAdmissionInstance($user);
    $state = disabledAdmissionSupplier();
    $root = app(CreateComboInvoiceAction::class)->renewal($user, $instance);
    $root->update(['expires_at' => now()->subSecond()]);
    app(ExpireUnpaidInvoicesAction::class)->execute();
    $state->hook = function ($path) use ($user): void {
        if ($path === '/host/renewpage') {
            disableAdmissionUser($user);
        }
    };
    expectDisabledAdmission(fn () => app(QuoteRenewalRecoveryAction::class)->execute($user, $root, true, 'epay_wechat', null));
    expect($user->fresh()->credit)->toBe('3.00')->and($root->fresh()->status)->toBe(InvoiceStatus::EXPIRED);
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseCount('financial_transactions', 0);
});

it('blocks new payment attempts for disabled customers before gateway access', function (): void {
    $user = User::factory()->create();
    $invoice = app(CreateWalletTopupInvoiceAction::class)->execute($user,
        ['request_id' => (string) Str::ulid(), 'amount' => '10.00', 'gateway' => 'epay_wechat']);
    disableAdmissionUser($user);
    expectDisabledAdmission(fn () => app(InitiatePaymentAction::class)->execute($invoice, $user));
    $this->assertDatabaseCount('payment_attempts', 0);
    Http::assertNothingSent();
});

it('checks payment access again under the user lock after configuration selection', function (): void {
    $user = User::factory()->create();
    $invoice = app(CreateWalletTopupInvoiceAction::class)->execute($user,
        ['request_id' => (string) Str::ulid(), 'amount' => '10.00', 'gateway' => 'epay_wechat']);
    $configuration = Mockery::mock(ConfigureEpayAction::class)->makePartial();
    $configuration->shouldReceive('lockConfiguration')->once()->andReturnUsing(function () use ($user): void {
        disableAdmissionUser($user);
    });
    $this->app->instance(ConfigureEpayAction::class, $configuration);
    expectDisabledAdmission(fn () => app(InitiatePaymentAction::class)->execute($invoice, $user));
    $this->assertDatabaseCount('payment_attempts', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
    Http::assertNothingSent();
});

it('finishes a committed gateway failure after disablement instead of leaving its attempt created', function (): void {
    $user = User::factory()->create();
    $invoice = app(CreateWalletTopupInvoiceAction::class)->execute($user,
        ['request_id' => (string) Str::ulid(), 'amount' => '10.00', 'gateway' => 'epay_wechat']);
    $level = DB::transactionLevel();
    Http::fake(function () use ($user, $level) {
        expect(DB::transactionLevel())->toBe($level)->and(PaymentAttempt::sole()->status)->toBe('created');
        disableAdmissionUser($user);

        return Http::response(['code' => 0], 200);
    });
    expect(app(InitiatePaymentAction::class)->execute($invoice, $user)->success)->toBeFalse()
        ->and(PaymentAttempt::sole()->status)->toBe('failed')->and($user->fresh()->disabled_at)->not->toBeNull();
    $this->assertDatabaseCount('payment_attempts', 1);
    $this->assertDatabaseCount('financial_transactions', 0);
});

it('settles historical payments and fulfills paid orders for the original disabled owner', function (): void {
    $user = User::factory()->create(['credit' => '3.00']);
    $product = disabledAdmissionProduct();
    $code = RedemptionCode::factory()->create(['product_id' => $product->id]);
    $invoice = app(CreateComboInvoiceAction::class)->execute($user, $product, BillingCycle::ONETIME, [], true, 'epay_wechat',
        ['request_id' => (string) Str::ulid()]);
    Http::fake(['https://pay.example.test/mapi.php' => Http::response(['code' => 1, 'payurl' => 'https://pay.example.test/isolated-checkout'])]);
    $attempt = app(InitiatePaymentAction::class)->execute($invoice, $user);
    disableAdmissionUser($user);
    $callback = new VerifiedPaymentResult('epay_wechat', 'C2-PAID-BEFORE-DISABLE', $attempt->outTradeNo, '7.00');
    app(HandlePaymentCallbackAction::class)->execute($callback);
    app(HandlePaymentCallbackAction::class)->execute($callback);
    $task = FulfillmentTask::sole();
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::PAID)->and(PaymentAttempt::sole()->status)->toBe('paid')
        ->and(PaymentCallback::sole()->invoice_id)->toBe($invoice->id)
        ->and($task->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS)
        ->and($task->instance->fresh()->status)->toBe(InstanceStatus::ACTIVE)->and($task->instance->user_id)->toBe($user->id)
        ->and($code->fresh()->status)->toBe('sold')->and($user->fresh()->credit)->toBe('0.00')->and($user->fresh()->frozen_credit)->toBe('0.00')
        ->and($user->fresh()->disabled_at)->not->toBeNull();
    $this->assertDatabaseCount('payment_callbacks', 1);
    $this->assertDatabaseCount('financial_transactions', 3);
    expect(FinancialTransaction::where('reference_key', 'settle:'.$invoice->id)->count())->toBe(1);
    $this->assertDatabaseHas('notification_events', ['user_id' => $user->id, 'event_key' => 'fulfillment:'.$task->id.':delivered']);
});

it('commits an already-authorized fulfillment result if disablement happens during delivery', function (): void {
    $user = User::factory()->create(['credit' => '100.00']);
    $product = disabledAdmissionProduct();
    $code = RedemptionCode::factory()->create(['product_id' => $product->id]);
    $invoice = app(CreateComboInvoiceAction::class)->execute($user, $product, BillingCycle::ONETIME, [], true,
        input: ['request_id' => (string) Str::ulid()]);
    $driver = Mockery::mock(RedemptionCodeDriver::class)->makePartial();
    $driver->shouldReceive('provision')->once()->andReturnUsing(function () use ($user, $code): ProvisionResult {
        disableAdmissionUser($user);

        return ProvisionResult::ok(credentials: ['card_key' => $code->code]);
    });
    $this->app->instance(RedemptionCodeDriver::class, $driver);
    $task = FulfillmentTask::sole();
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS)
        ->and($task->instance->fresh()->status)->toBe(InstanceStatus::ACTIVE)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and($code->fresh()->status)->toBe('sold')->and($code->fresh()->instance_id)->toBe($invoice->instance_id)
        ->and($user->fresh()->disabled_at)->not->toBeNull()->and($user->fresh()->credit)->toBe('90.00');
    $this->assertDatabaseCount('fulfillment_tasks', 1);
    $this->assertDatabaseCount('financial_transactions', 2);
    Http::assertNothingSent();
});
