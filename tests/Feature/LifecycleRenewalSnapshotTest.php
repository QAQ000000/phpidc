<?php

declare(strict_types=1);

use App\Actions\CreateComboInvoiceAction;
use App\Actions\ProcessInstanceLifecycleAction;
use App\Enums\BillingCycle;
use App\Enums\DriverType;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Services\DriverManager;
use App\Services\SettingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Queue::fake();
    Http::preventStrayRequests();
    $this->travelTo(now()->startOfSecond());
});

function lifecycleSnapshotFixture(?Closure $duringQuote = null): array
{
    $user = User::factory()->create(['credit' => '100.00']);
    $account = UpstreamApi::factory()->create();
    $product = Product::factory()->create(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id, 'markup_percent' => '0.00']);
    $instance = Instance::factory()->active()->create(['user_id' => $user->id, 'product_id' => $product->id,
        'driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id, 'upstream_instance_id' => '99',
        'billing_cycle' => BillingCycle::MONTHLY, 'next_due_at' => now()->addDay(), 'recurring_amount' => '10.00', 'active_config' => []]);
    $level = DB::transactionLevel();
    Http::fake(function ($request) use ($instance, $account, $level, $duringQuote) {
        expect(DB::transactionLevel())->toBe($level);
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/host/renewpage' && $duringQuote !== null) {
            $duringQuote($instance, $account);
        }

        return Http::response(match ($path) {
            '/zjmf_api_login' => ['status' => 200, 'jwt' => 'isolated-lifecycle-snapshot'],
            '/host/header' => ['status' => 200, 'data' => ['host_data' => ['domainstatus' => 'Active', 'nextduedate' => $instance->next_due_at->toIso8601String()]]],
            '/host/renewpage' => ['status' => 200, 'data' => ['host' => ['amount' => '10.00', 'billingcycle' => 'monthly'], 'currency' => ['code' => 'CNY']]],
            default => throw new LogicException('Unexpected isolated lifecycle request: '.$path),
        });
    });

    return [$user, $instance, $account];
}

it('creates one automatic renewal using one synchronization and preserves wallet and event identities', function (): void {
    [$user, $instance] = lifecycleSnapshotFixture();
    expect(app(ProcessInstanceLifecycleAction::class)->process($instance))->toBeTrue();
    $invoice = Invoice::sole();
    expect($invoice->status)->toBe(InvoiceStatus::UNPAID)->and($invoice->total_amount)->toBe('10.00')
        ->and($invoice->credit_applied)->toBe('0.00')->and($invoice->gateway_amount)->toBe('10.00')
        ->and($invoice->billing_period_key)->toBe($instance->id.':renew:'.$instance->next_due_at->utc()->format('Y-m-d\TH:i:s\Z'))
        ->and($invoice->line_items['automatic_renewal']['selection_pending'])->toBeTrue()
        ->and($user->fresh()->credit)->toBe('100.00')->and($user->fresh()->frozen_credit)->toBe('0.00');
    expect(Http::recorded(fn ($request) => parse_url($request->url(), PHP_URL_PATH) === '/host/header'))->toHaveCount(1);
    expect(Http::recorded(fn ($request) => parse_url($request->url(), PHP_URL_PATH) === '/host/renewpage'))->toHaveCount(1);
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('fulfillment_tasks', 0);
    $this->assertDatabaseHas('notification_events', ['event_key' => 'invoice:'.$invoice->id.':renewal-created']);

    expect(app(ProcessInstanceLifecycleAction::class)->process($instance->fresh()))->toBeTrue();
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseCount('notification_events', 1);
    expect(Http::recorded(fn ($request) => parse_url($request->url(), PHP_URL_PATH) === '/host/header'))->toHaveCount(2);
    expect(Http::recorded(fn ($request) => parse_url($request->url(), PHP_URL_PATH) === '/host/renewpage'))->toHaveCount(1);
});

it('keeps a fresh synchronization for manual and independent automatic renewal calls', function (bool $automatic): void {
    [$user, $instance] = lifecycleSnapshotFixture();
    $invoice = $automatic ? app(CreateComboInvoiceAction::class)->automaticRenewal($user, $instance)
        : app(CreateComboInvoiceAction::class)->renewal($user, $instance);
    expect($invoice->total_amount)->toBe('10.00');
    expect(Http::recorded(fn ($request) => parse_url($request->url(), PHP_URL_PATH) === '/host/header'))->toHaveCount(1);
    $this->assertDatabaseCount('financial_transactions', 0);
})->with([true, false]);

it('rejects changes between lifecycle synchronization and its internal invoice handoff', function (string $change): void {
    [$user, $instance] = lifecycleSnapshotFixture();
    $creator = new CreateComboInvoiceAction(app(DriverManager::class), app(SettingService::class));
    $intercept = Mockery::mock(CreateComboInvoiceAction::class);
    $intercept->shouldReceive('automaticRenewalFromLifecycle')->once()->andReturnUsing(function ($owner, $synced, $binding, $accountFingerprint) use ($creator, $change) {
        $changed = $synced->fresh();
        match ($change) {
            'due' => $changed->update(['next_due_at' => now()->addDays(2)]),
            'status' => $changed->update(['status' => InstanceStatus::SUSPENDED]),
            'host' => $changed->update(['upstream_instance_id' => 'different-host']),
        };

        return $creator->automaticRenewalFromLifecycle($owner, $synced, $binding, $accountFingerprint);
    });
    $this->app->instance(CreateComboInvoiceAction::class, $intercept);
    expect(app(ProcessInstanceLifecycleAction::class)->process($instance))->toBeFalse()
        ->and($user->fresh()->credit)->toBe('100.00')->and($user->fresh()->frozen_credit)->toBe('0.00');
    Http::assertNotSent(fn ($request) => parse_url($request->url(), PHP_URL_PATH) === '/host/renewpage');
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('fulfillment_tasks', 0);
})->with(['due', 'status', 'host']);

it('rejects a lifecycle snapshot changed during renewal quotation before any invoice or money write', function (string $change): void {
    [$user, $instance] = lifecycleSnapshotFixture(function (Instance $service, UpstreamApi $account) use ($change): void {
        match ($change) {
            'due' => $service->update(['next_due_at' => now()->addDays(2)]),
            'status' => $service->update(['status' => InstanceStatus::SUSPENDED]),
            'host' => $service->update(['upstream_instance_id' => 'different-host']),
            'token' => $service->update(['downstream_token' => 'changed-binding-token']),
            'cycle' => $service->update(['billing_cycle' => BillingCycle::ANNUALLY]),
            'cancellation' => $service->update(['active_config' => ['cancellation' => ['requested' => true]]]),
            'account disabled' => $account->update(['is_active' => false]),
            'account secret' => $account->update(['encrypted_secret' => 'changed-secret']),
            'account endpoint' => $account->update(['base_url' => 'https://changed.example.test']),
            'account config' => $account->update(['config' => ['changed' => true]]),
        };
    });
    expect(app(ProcessInstanceLifecycleAction::class)->process($instance))->toBeFalse()
        ->and($user->fresh()->credit)->toBe('100.00')->and($user->fresh()->frozen_credit)->toBe('0.00');
    expect(Http::recorded(fn ($request) => parse_url($request->url(), PHP_URL_PATH) === '/host/header'))->toHaveCount(1);
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('fulfillment_tasks', 0);
    $this->assertDatabaseCount('notification_events', 0);
})->with(['due', 'status', 'host', 'token', 'cycle', 'cancellation', 'account disabled', 'account secret', 'account endpoint', 'account config']);
