<?php

declare(strict_types=1);

use App\Actions\AssignInventoryRoleAction;
use App\Actions\AssignUpstreamRoleAction;
use App\Actions\ConfigureDowngradeBoundsAction;
use App\Actions\CreateComboInvoiceAction;
use App\Actions\DispatchStalePendingTasksAction;
use App\Actions\DowngradeCalculatorAction;
use App\Actions\ExecuteFulfillmentTaskAction;
use App\Actions\HandlePaymentCallbackAction;
use App\Actions\HotSwapInstanceDriverAction;
use App\Actions\ProcessInstanceLifecycleAction;
use App\Actions\RequestInstanceOperationAction;
use App\Actions\ResolveProductOptionsAction;
use App\Actions\RetryFulfillmentTaskAction;
use App\Actions\SendLifecycleNotificationAction;
use App\Actions\SyncClassicCatalogAction;
use App\Data\VerifiedPaymentResult;
use App\Enums\BillingCycle;
use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Jobs\SendNotificationJob;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductCustomField;
use App\Models\ProductOptionGroup;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Services\DriverManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
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
    $this->travelTo(now()->utc()->startOfHour());
});

function expandedClassic(): array
{
    $account = UpstreamApi::factory()->create();
    $user = User::factory()->create(['credit' => '100.00']);
    $product = Product::factory()->create(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id, 'upstream_pid' => '17', 'markup_percent' => '20.00',
        'upstream_base_prices' => ['monthly' => ['price' => '8.00', 'enabled' => true]]]);
    $instance = Instance::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'driver_type' => DriverType::ZJMF_API,
        'upstream_api_id' => $account->id, 'upstream_instance_id' => '99', 'downstream_token' => str_repeat('a', 32), 'status' => InstanceStatus::ACTIVE,
        'billing_cycle' => BillingCycle::MONTHLY, 'next_due_at' => now()->addDay(), 'recurring_amount' => '9.60',
        'active_config' => ['billing_cycle' => 'monthly', 'currency' => 'CNY', 'upstream_pid' => 17, 'options' => [], 'upstream_configoption' => []]]);
    $state = (object) ['due' => $instance->next_due_at->toDateTimeString(), 'status' => 'Active', 'cost' => '8.00', 'paid' => false,
        'createLost' => false, 'payLost' => false, 'drift' => false, 'deleted' => false, 'version' => 1, 'options' => [50 => 501], 'calls' => [], 'hook' => null];
    $level = DB::transactionLevel();
    Http::preventStrayRequests();
    Http::fake(function ($request) use ($state, $instance, $level) {
        expect(DB::transactionLevel())->toBe($level);
        $path = parse_url($request->url(), PHP_URL_PATH);
        $state->calls[$path] = ($state->calls[$path] ?? 0) + 1;
        if ($state->hook !== null && ($response = ($state->hook)($path, $request)) !== null) {
            return $response;
        }
        $body = match ($path) {
            '/zjmf_api_login' => ['status' => 200, 'jwt' => 'expanded-jwt'],
            '/cart/get_product_config' => ['status' => 200, 'data' => expandedCatalog($state->version)],
            '/api/product/proinfo' => ['status' => 200, 'data' => ['info' => [['id' => 17, 'location_version' => $state->version, 'stock_control' => 1, 'qty' => 20]]]],
            '/user_info' => ['status' => 200, 'data' => ['user' => ['currency' => 1]]],
            '/cart/stock_control' => ['status' => 200, 'data' => ['product' => ['hidden' => 0, 'stock_control' => 1, 'qty' => 20]]],
            '/cart/get_total' => ['status' => 200, 'products' => ['total' => $state->cost, 'setupfee_total' => '0.00'], 'currency' => ['code' => 'CNY']],
            '/host/header' => ['status' => 200, 'data' => ['host_data' => ['domainstatus' => $state->deleted ? 'Deleted' : $state->status,
                'nextduedate' => $state->due, 'productid' => 17, 'billingcycle' => $instance->fresh()->billing_cycle->value, 'password' => 'PRIVATE-HOST-SECRET'], 'currency' => ['code' => 'CNY']]],
            '/host/renewpage' => ['status' => 200, 'data' => ['host' => ['amount' => $state->cost, 'billingcycle' => $instance->fresh()->billing_cycle->value], 'currency' => ['code' => 'CNY']]],
            '/host/renew' => $state->createLost ? ['status' => 400, '_transport_failure' => true] : ['status' => 200, 'data' => ['invoiceid' => 765]],
            '/use_credit_page' => ['status' => 200, 'data' => ['total' => $state->drift ? '99.00' : $state->cost, 'currency' => ['code' => 'CNY']]],
            '/upgrade/upgrade_config_post' => ['status' => 200],
            '/upgrade/upgrade_config_page' => ['status' => 200, 'data' => ['total' => $state->cost, 'currency' => ['code' => 'CNY']]],
            '/upgrade/checkout_config_upgrade' => ['status' => 200, 'data' => ['invoiceid' => 766]],
            '/upgrade/index/99' => ['status' => 200, 'data' => ['host' => [['oid' => 50, 'subid' => $state->options[50], 'option_type' => 1, 'qty' => 1]]]],
            '/host/cancel' => ['status' => 200],
            '/host/setdownstream' => ['status' => 200],
            '/provision/default' => ['status' => 200],
            '/apply_credit' => ['status' => $state->payLost ? 400 : 1001],
            default => throw new RuntimeException('Unexpected expanded classic request: '.$path),
        };
        if ($path === '/apply_credit') {
            $state->paid = true;
            $state->due = Carbon::parse($state->due)->addMonthNoOverflow()->toDateTimeString();
            $state->options[50] = 502;
        }
        if ($path === '/provision/default') {
            $state->status = $request['func'] === 'suspend' ? 'Suspended' : 'Active';
        }

        return Http::response($body);
    });

    return [$user, $product, $instance, $state];
}

function expandedCatalog(int $version = 1): array
{
    return ['products' => ['id' => 17, 'name' => 'Expanded Cloud', 'location_version' => $version, 'stock_control' => 1, 'qty' => 20,
        'password' => ['show' => 0], 'host' => ['show' => 0]],
        'product_pricings' => [['code' => 'CNY', 'monthly' => '8.00', 'msetupfee' => '0.00', 'hour' => '1.00', 'hsetupfee' => '0.00', 'day' => '2.00', 'dsetupfee' => '0.00']],
        'config_groups' => [['id' => 10, 'options' => [['id' => 50, 'option_name' => 'cpu|CPU', 'option_type' => 1, 'upgrade' => 1,
            'sub' => [['id' => 501, 'option_name' => 'small|Small', 'pricings' => [['code' => 'CNY', 'monthly' => '1.00', 'msetupfee' => '0.00']]],
                ['id' => 502, 'option_name' => 'large|Large', 'pricings' => [['code' => 'CNY', 'monthly' => '3.00', 'msetupfee' => '0.00']]]]]]]],
        'customfields' => [['id' => 80, 'fieldname' => 'License', 'fieldtype' => 'password', 'required' => 1, 'regexpr' => '^[A-Z]+$']], 'advanced' => []];
}

function expandedOptions(): array
{
    return ['configoption' => [50 => 501], 'customfield' => [80 => 'PRIVATE']];
}

function expandedWorker(FulfillmentTask $task): void
{
    $task->refresh();
    if ($task->available_at?->isFuture()) {
        $task->update(['available_at' => now()]);
    }
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
}

it('returns pure catalog snapshots and synchronizes stable option IDs without touching local rows or instance bindings', function (): void {
    [$user,$product,$instance,$state] = expandedClassic();
    $before = $instance->fresh()->getRawOriginal();
    $local = ProductOptionGroup::create(['product_id' => $product->id, 'name' => 'Local', 'type' => 'select', 'sync_source' => 'local']);
    $snapshot = app(DriverManager::class)->classicAccount($product->upstream_api_id)->syncProductConfig($product);
    expect($snapshot->version)->toBe(1)->and(ProductOptionGroup::count())->toBe(1);
    $sync = app(SyncClassicCatalogAction::class)->execute($product, 'Import full configuration');
    $group = ProductOptionGroup::where('upstream_group_id', 50)->sole();
    $ids = $group->items->pluck('id')->all();
    $state->version = 2;
    app(SyncClassicCatalogAction::class)->execute($product->fresh(), 'Version update');
    expect($sync->optionsUpserted)->toBe(2)->and($group->fresh()->items->pluck('id')->all())->toBe($ids)
        ->and($local->fresh()->is_hidden)->toBeFalse()->and($instance->fresh()->getRawOriginal())->toBe($before)
        ->and($product->fresh()->upstream_base_prices['monthly']['price'])->toBe('8.00');
});

it('rejects unknown or missing option selections and required fields before wallet mutation', function (array $options): void {
    [$user,$product] = expandedClassic();
    app(SyncClassicCatalogAction::class)->execute($product, 'Import options');
    expect(fn () => app(CreateComboInvoiceAction::class)->execute($user, $product->fresh(), BillingCycle::MONTHLY, $options, true, input: ['request_id' => (string) Str::ulid()]))->toThrow(ValidationException::class);
    expect($user->fresh()->credit)->toBe('100.00')->and(Invoice::count())->toBe(0);
})->with([[[]], [['configoption' => [50 => 999], 'customfield' => [80 => 'PRIVATE']]], [['configoption' => [50 => 501]]]]);

it('syncs period and setup option prices separately without repricing existing services', function (): void {
    [$user, $product, $instance, $state] = expandedClassic();
    $before = $instance->fresh()->getRawOriginal();
    $state->hook = function ($path) {
        if ($path !== '/cart/get_product_config') {
            return null;
        }
        $catalog = expandedCatalog();
        $catalog['product_pricings'][0]['msetupfee'] = '2.00';
        $catalog['product_pricings'][0]['hour'] = '-1.00';
        $catalog['config_groups'][0]['options'][0]['sub'][0]['pricings'][0]['msetupfee'] = '3.00';

        return Http::response(['status' => 200, 'data' => $catalog]);
    };
    app(SyncClassicCatalogAction::class)->execute($product, 'Verify C19 sync');
    $product->refresh();
    $item = ProductOptionGroup::where('upstream_group_id', 50)->sole()->items()->where('upstream_sub_id', 501)->sole();
    expect($product->upstream_base_prices['monthly'])->toBe(['price' => '8.00', 'setup_fee' => '2.00', 'enabled' => true])
        ->and($product->local_prices['monthly'])->toBe(['price' => '9.60', 'setup_fee' => '2.40', 'enabled' => true])
        ->and($product->upstream_base_prices)->not->toHaveKey('hour')
        ->and($item->upstream_base_prices['monthly'])->toBe(['price' => '1.00', 'setup_fee' => '3.00', 'enabled' => true])
        ->and($item->local_prices['monthly'])->toBe(['price' => '1.20', 'setup_fee' => '3.60', 'enabled' => true])
        ->and($instance->fresh()->getRawOriginal())->toBe($before);
});

it('preserves paired option snapshots and encrypted custom values in the paid purchase', function (): void {
    [$user,$product] = expandedClassic();
    app(SyncClassicCatalogAction::class)->execute($product, 'Map options');
    $invoice = app(CreateComboInvoiceAction::class)->execute($user, $product->fresh(), BillingCycle::MONTHLY, expandedOptions(), true, input: ['request_id' => (string) Str::ulid()]);
    $created = Instance::findOrFail($invoice->instance_id);
    expect($created->active_config['options'][0]['upstream_group_id'])->toBe(50)->and($created->active_config['options'][0]['upstream_sub_id'])->toBe(501)
        ->and($created->active_config['options'][0]['unit_price'])->toBe('1.20');
    expect(DB::table('invoices')->where('id', $invoice->id)->value('line_items'))->not->toContain('PRIVATE');
});

it('deduplicates an hourly renewal boundary and permits the next hour in the same calendar month', function (): void {
    [$user,$product,$instance,$state] = expandedClassic();
    $instance->update(['billing_cycle' => BillingCycle::HOUR]);
    $first = app(CreateComboInvoiceAction::class)->renewal($user, $instance, true);
    $same = app(CreateComboInvoiceAction::class)->renewal($user, $instance->fresh(), true);
    expect($same->id)->toBe($first->id)->and($user->fresh()->credit)->toBe('90.40')->and(Instance::count())->toBe(1)->and(FulfillmentTask::count())->toBe(1);
    $state->due = Carbon::parse($state->due)->addHour()->toDateTimeString();
    FulfillmentTask::sole()->update(['status' => FulfillmentTaskStatus::SUCCESS]);
    $next = app(CreateComboInvoiceAction::class)->renewal($user, $instance->fresh(), true);
    expect($next->billing_period_key)->not->toBe($first->billing_period_key)->and(Invoice::count())->toBe(2);
});

it('settles a combo renewal callback once and extends the existing service through its leased task', function (): void {
    [$user,$product,$instance,$state] = expandedClassic();
    $user->forceFill(['credit' => '3.00'])->save();
    $invoice = app(CreateComboInvoiceAction::class)->renewal($user, $instance, true, 'epay_alipay');
    expect($invoice->credit_applied)->toBe('3.00')->and($invoice->gateway_amount)->toBe('6.60');
    $callback = new VerifiedPaymentResult('epay_alipay', 'renewal-trade', $invoice->invoice_no, '6.60');
    app(HandlePaymentCallbackAction::class)->execute($callback);
    app(HandlePaymentCallbackAction::class)->execute($callback);
    $task = FulfillmentTask::sole();
    expandedWorker($task);
    expandedWorker($task);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS)->and(Instance::count())->toBe(1)
        ->and($instance->fresh()->next_due_at->toDateTimeString())->toBe($state->due)->and($user->fresh()->frozen_credit)->toBe('0.00')
        ->and($state->calls['/apply_credit'])->toBe(1)->and($state->calls['/host/renew'])->toBe(1);
});

it('blocks changed upstream renewal cost before payment while preserving the paid downstream invoice', function (): void {
    [$user,$product,$instance,$state] = expandedClassic();
    $invoice = app(CreateComboInvoiceAction::class)->renewal($user, $instance, true);
    $state->drift = true;
    expandedWorker(FulfillmentTask::sole());
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::PAID)->and($state->calls['/apply_credit'] ?? 0)->toBe(0)
        ->and(FulfillmentTask::sole()->status)->toBe(FulfillmentTaskStatus::FAILED)->and($instance->fresh()->next_due_at->toDateTimeString())->toBe($state->due);
});

it('retains an uncertain renewal invoice creation and refuses replay even after explicit retry', function (): void {
    [$user,$product,$instance,$state] = expandedClassic();
    app(CreateComboInvoiceAction::class)->renewal($user, $instance, true);
    $state->createLost = true;
    $task = FulfillmentTask::sole();
    expandedWorker($task);
    app(RetryFulfillmentTaskAction::class)->execute($task->fresh(), 'Recheck existing upstream result');
    expandedWorker($task);
    expect($state->calls['/host/renew'])->toBe(1)->and($state->calls['/apply_credit'] ?? 0)->toBe(0)->and($task->fresh()->status)->toBe(FulfillmentTaskStatus::FAILED);
});

it('retains a crashed operation creation intent across automatic lease recovery without recreating or paying', function (string $action): void {
    [$user, $product, $instance, $state] = expandedClassic();
    if ($action === 'renew') {
        $invoice = app(CreateComboInvoiceAction::class)->renewal($user, $instance, true);
        $endpoint = '/host/renew';
    } else {
        app(SyncClassicCatalogAction::class)->execute($product, 'C21 test configuration');
        $mapped = app(ResolveProductOptionsAction::class)->execute($product->fresh(), 'monthly', expandedOptions());
        $instance->update(['active_config' => ['options' => $mapped['pairs'], 'upstream_configoption' => [50 => 501]]]);
        $invoice = app(CreateComboInvoiceAction::class)->upgrade($user, $instance->fresh(), ['configoption' => [50 => 502]], (string) Str::ulid(), true);
        $endpoint = '/upgrade/checkout_config_upgrade';
    }
    $balance = $user->fresh()->credit;
    $state->hook = function ($path) use ($endpoint) {
        if ($path === $endpoint) {
            throw new RuntimeException('C21 interrupted operation creation.');
        }

        return null;
    };
    $task = FulfillmentTask::sole();
    expect(fn () => expandedWorker($task))->toThrow(RuntimeException::class, 'C21 interrupted operation creation.');
    expect($task->fresh()->attempts)->toBe(1)->and($task->fresh()->payload['operation']['state'])->toBe('create_requested');
    $this->travel(211)->seconds();
    app(DispatchStalePendingTasksAction::class)->execute();
    expect($task->fresh()->attempts)->toBe(1)->and($task->fresh()->status)->toBe(FulfillmentTaskStatus::PENDING);
    $this->travel(61)->seconds();
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($state->calls[$endpoint])->toBe(1)->and($state->calls['/apply_credit'] ?? 0)->toBe(0)
        ->and($task->fresh()->attempts)->toBe(2)->and($task->fresh()->status)->toBe(FulfillmentTaskStatus::FAILED)
        ->and($task->fresh()->payload['operation']['state'])->toBe('create_requested')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID)->and($user->fresh()->credit)->toBe($balance);
})->with(['renew', 'upgrade']);

it('reconciles a lost operation payment response through due state without a second debit', function (): void {
    [$user,$product,$instance,$state] = expandedClassic();
    app(CreateComboInvoiceAction::class)->renewal($user, $instance, true);
    $state->payLost = true;
    $task = FulfillmentTask::sole();
    expandedWorker($task);
    expandedWorker($task);
    expect($state->calls['/apply_credit'])->toBe(1)->and($task->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS);
});

it('discards a renewal delivery result after the worker lease expires during upstream payment', function (): void {
    [$user,$product,$instance,$state] = expandedClassic();
    app(CreateComboInvoiceAction::class)->renewal($user, $instance, true);
    $before = $instance->fresh()->next_due_at->toDateTimeString();
    $state->hook = function ($path) {
        if ($path === '/apply_credit') {
            FulfillmentTask::sole()->update(['lease_expires_at' => now()->subSecond()]);
        }

        return null;
    };
    expandedWorker(FulfillmentTask::sole());
    expect($instance->fresh()->next_due_at->toDateTimeString())->toBe($before)->and(FulfillmentTask::sole()->payload['operation']['state'])->toBe('paid');
});

it('quotes upgrades through the prepared page and confirms the delivered configuration before local activation', function (): void {
    [$user,$product,$instance,$state] = expandedClassic();
    app(SyncClassicCatalogAction::class)->execute($product, 'Map current and future options');
    $selected = app(ResolveProductOptionsAction::class)->execute($product->fresh(), 'monthly', expandedOptions());
    $instance->update(['active_config' => ['options' => $selected['pairs'], 'upstream_configoption' => [50 => 501]]]);
    $options = ['configoption' => [50 => 502], 'customfield' => [80 => 'PRIVATE']];
    $requestId = (string) Str::ulid();
    $invoice = app(CreateComboInvoiceAction::class)->upgrade($user, $instance->fresh(), $options, $requestId, true);
    $same = app(CreateComboInvoiceAction::class)->upgrade($user, $instance->fresh(), $options, $requestId, true);
    expect($same->id)->toBe($invoice->id)->and($state->calls['/upgrade/upgrade_config_page'])->toBe(1);
    expandedWorker(FulfillmentTask::sole());
    expect(FulfillmentTask::sole()->status)->toBe(FulfillmentTaskStatus::SUCCESS)->and($instance->fresh()->active_config['upstream_configoption'][50])->toBe(502)
        ->and($instance->fresh()->recurring_amount)->toBe('12.00')->and($state->calls['/upgrade/checkout_config_upgrade'])->toBe(1);
});

it('keeps an accepted termination pending until an authenticated Deleted snapshot arrives', function (): void {
    [$user,$product,$instance,$state] = expandedClassic();
    $task = app(RequestInstanceOperationAction::class)->execute($instance, 'terminate', 'User confirms service deletion', (string) Str::ulid(), $user, 'client_action');
    expandedWorker($task);
    expandedWorker($task);
    expect($instance->fresh()->status)->toBe(InstanceStatus::ACTIVE)->and($state->calls['/host/cancel'])->toBe(1)->and($task->fresh()->status)->toBe(FulfillmentTaskStatus::PENDING);
    $state->deleted = true;
    expandedWorker($task);
    expect($instance->fresh()->status)->toBe(InstanceStatus::DELETED)->and($task->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS);
});

it('suspends overdue renewable hosts once and never auto-terminates upstream resources', function (): void {
    [$user,$product,$instance,$state] = expandedClassic();
    $state->due = now()->subDay()->toDateTimeString();
    $instance->update(['next_due_at' => $state->due]);
    $once = Instance::factory()->create(['billing_cycle' => BillingCycle::ONETIME, 'status' => InstanceStatus::ACTIVE, 'next_due_at' => now()->subDays(30)]);
    app(ProcessInstanceLifecycleAction::class)->execute();
    app(ProcessInstanceLifecycleAction::class)->execute();
    $task = FulfillmentTask::sole();
    expect($task->action)->toBe('suspend');
    expandedWorker($task);
    expect($instance->fresh()->status)->toBe(InstanceStatus::SUSPENDED)->and($instance->fresh()->active_config['overdue_suspension'])->toBeTrue()
        ->and($once->fresh()->status)->toBe(InstanceStatus::ACTIVE)->and($state->calls['/host/cancel'] ?? 0)->toBe(0);
});

it('never restores a manually suspended host automatically when its due date is in the future', function (): void {
    [$user,$product,$instance,$state] = expandedClassic();
    $state->status = 'Suspended';
    $instance->update(['status' => InstanceStatus::SUSPENDED]);
    app(ProcessInstanceLifecycleAction::class)->execute();
    expect(FulfillmentTask::where('action', 'unsuspend')->count())->toBe(0)->and($state->calls['/provision/default'] ?? 0)->toBe(0);
});

it('reduces only future renewal prices inside approved downgrade bounds without sending an upstream change or refund', function (): void {
    [$user,$product,$instance,$state] = expandedClassic();
    app(SyncClassicCatalogAction::class)->execute($product, 'Map options');
    $resolve = app(ResolveProductOptionsAction::class);
    $large = $resolve->execute($product->fresh(), 'monthly', ['configoption' => [50 => 502], 'customfield' => [80 => 'PRIVATE']]);
    $small = $resolve->execute($product->fresh(), 'monthly', expandedOptions());
    $instance->update(['active_config' => ['options' => $large['pairs'], 'upstream_configoption' => [50 => 502]], 'recurring_amount' => '12.00']);
    ProductOptionGroup::where('upstream_group_id', 50)->sole()->update(['downgrade_bounds' => ['allow_downgrade' => true, 'allowed_item_ids' => [$small['pairs'][0]['local_item_id']]]]);
    $price = app(DowngradeCalculatorAction::class)->execute($instance->fresh(), expandedOptions(), $user, 'Reduce future bill only');
    expect($price)->toBe('9.60')->and($instance->fresh()->active_config['upstream_configoption'][50])->toBe(502)->and($instance->fresh()->is_price_locked)->toBeTrue()
        ->and($state->calls['/upgrade/upgrade_config_post'] ?? 0)->toBe(0)->and($user->fresh()->credit)->toBe('100.00');
    $this->assertDatabaseCount('financial_transactions', 0);
});

it('requires hot-swap authority and confirmation and atomically retains old binding history and stales old controls', function (): void {
    [$user,$product,$instance,$state] = expandedClassic();
    $actor = User::factory()->create();
    app(AssignUpstreamRoleAction::class)->execute($actor, 'upstream-migrator', 'Migration authority');
    $target = UpstreamApi::factory()->create();
    $oldTask = app(RequestInstanceOperationAction::class)->execute($instance, 'suspend', 'Old queued suspension', 'old-control');
    $action = app(HotSwapInstanceDriverAction::class);
    expect(fn () => $action->execute($instance, $target->id, '199', '17', 'keep_orphan', 'Operator migrates binding', $actor, false))->toThrow(DomainException::class);
    $action->execute($instance, $target->id, '199', '17', 'keep_orphan', 'Operator migrates binding', $actor, true);
    expect($instance->fresh()->upstream_api_id)->toBe($target->id)->and($oldTask->fresh()->status)->toBe(FulfillmentTaskStatus::STALE)
        ->and($instance->fresh()->downstream_token)->not->toBe(str_repeat('a', 32))->and($product->fresh()->upstream_api_id)->not->toBe($target->id);
    $this->assertDatabaseHas('instance_upstream_bindings', ['instance_id' => $instance->id, 'upstream_disposition' => 'kept_orphan']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'instance.hot_swap', 'actor_id' => $actor->id, 'reason' => 'Operator migrates binding']);
});

it('denies upstream administration to inventory operators and does not expose account secrets in read-only pages', function (): void {
    [$user,$product] = expandedClassic();
    $inventory = User::factory()->create();
    app(AssignInventoryRoleAction::class)->execute($inventory, 'inventory-operator', 'Inventory only');
    $this->actingAs($inventory)->get('/admin/upstreams')->assertForbidden();
    $viewer = User::factory()->create();
    app(AssignUpstreamRoleAction::class)->execute($viewer, 'upstream-viewer', 'Read-only');
    $this->actingAs($viewer)->get('/admin/upstreams')->assertOk()->assertDontSee('保存加密连接')->assertDontSee($product->upstreamApi->encrypted_secret);
    $this->post('/admin/upstreams/configure', [])->assertForbidden();
    $this->get('/admin/upstream-instances')->assertOk()->assertDontSee('PRIVATE-HOST-SECRET');
});

it('renders full purchase options and provides server-authoritative live quotations without accepting a client amount', function (): void {
    [$user,$product] = expandedClassic();
    app(SyncClassicCatalogAction::class)->execute($product, 'Expose full options');
    $this->actingAs($user)->get('/console/products/'.$product->id.'/configure')->assertOk()->assertSee('CPU')->assertSee('License')->assertSee('options[configoption][50]', false);
    $this->postJson('/console/products/'.$product->id.'/quote', ['billing_cycle' => 'monthly', 'options' => expandedOptions(), 'price' => '0.01'])
        ->assertOk()->assertJsonPath('price', '9.60')->assertJsonMissingPath('upstreamBaseCost');
    $this->assertDatabaseCount('invoices', 0);
});

it('does not advertise a free cycle for a paid upstream catalog', function (): void {
    [$user, $product] = expandedClassic();
    app(SyncClassicCatalogAction::class)->execute($product, 'Check cycle availability');
    expect($product->fresh()->upstream_base_prices)->not->toHaveKey('free');
});

it('returns the same renewal request after fulfillment advances the upstream due boundary', function (): void {
    [$user, $product, $instance, $state] = expandedClassic();
    $request = (string) Str::ulid();
    $first = app(CreateComboInvoiceAction::class)->renewal($user, $instance, true, requestId: $request);
    expandedWorker(FulfillmentTask::sole());
    $same = app(CreateComboInvoiceAction::class)->renewal($user, $instance->fresh(), true, requestId: $request);
    expect($same->id)->toBe($first->id)->and(Invoice::count())->toBe(1)->and($user->fresh()->credit)->toBe('90.40');
});

it('freezes the renewal duration and does not reuse terminal invoices under a fresh key', function (): void {
    [$user, $product, $instance, $state] = expandedClassic();
    $first = app(CreateComboInvoiceAction::class)->renewal($user, $instance, true);
    $product->update(['provisioning_rules' => ['classic_catalog' => ['pay_type' => ['pay_monthly_cycle' => 12]]]]);
    expandedWorker(FulfillmentTask::sole());
    expect(FulfillmentTask::sole()->status)->toBe(FulfillmentTaskStatus::SUCCESS);
    $next = app(CreateComboInvoiceAction::class)->renewal($user, $instance->fresh());
    $next->update(['status' => InvoiceStatus::CANCELLED]);
    $same = app(CreateComboInvoiceAction::class)->renewal($user, $instance->fresh(), true);
    expect($same->id)->toBe($next->id)->and($same->status)->toBe(InvoiceStatus::CANCELLED)->and(Invoice::count())->toBe(2);
});

it('allows overdue suspension while its renewal invoice remains unpaid', function (): void {
    [$user, $product, $instance, $state] = expandedClassic();
    $invoice = app(CreateComboInvoiceAction::class)->renewal($user, $instance);
    $state->due = now()->subMinute()->toDateTimeString();
    app(ProcessInstanceLifecycleAction::class)->execute();
    expect(FulfillmentTask::sole()->action)->toBe('suspend')->and($invoice->fresh()->status)->toBe(InvoiceStatus::UNPAID);
    expandedWorker(FulfillmentTask::sole());
    expect($instance->fresh()->status)->toBe(InstanceStatus::SUSPENDED)->and($user->fresh()->credit)->toBe('100.00');
});

it('restores only an accepted system overdue suspension with the same authenticated reason', function (): void {
    [$user, $product, $instance, $state] = expandedClassic();
    $state->due = now()->subMinute()->toDateTimeString();
    $state->hook = function ($path) use ($state) {
        if ($path === '/host/header') {
            return Http::response(['status' => 200, 'data' => ['host_data' => ['domainstatus' => $state->status, 'nextduedate' => $state->due, 'suspendreason' => 'Service overdue']]]);
        }

        return null;
    };
    app(ProcessInstanceLifecycleAction::class)->execute();
    expandedWorker(FulfillmentTask::sole());
    $state->due = now()->addMonth()->toDateTimeString();
    app(ProcessInstanceLifecycleAction::class)->execute();
    $resume = FulfillmentTask::where('action', 'unsuspend')->sole();
    expandedWorker($resume);
    expect($resume->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS)->and($instance->fresh()->status)->toBe(InstanceStatus::ACTIVE)
        ->and($state->calls['/provision/default'])->toBe(2)->and($instance->fresh()->active_config)->not->toHaveKey('overdue_suspension');
});

it('never claims ownership of a suspension that reached upstream before its control request', function (): void {
    [$user, $product, $instance, $state] = expandedClassic();
    $state->due = now()->subMinute()->toDateTimeString();
    app(ProcessInstanceLifecycleAction::class)->execute();
    $state->status = 'Suspended';
    expandedWorker(FulfillmentTask::sole());
    expect($instance->fresh()->active_config)->not->toHaveKey('overdue_suspension')->and($state->calls['/provision/default'] ?? 0)->toBe(0);
});

it('blocks upstream payment if the authorized currency or current binding changes', function (string $change): void {
    [$user, $product, $instance, $state] = expandedClassic();
    app(CreateComboInvoiceAction::class)->renewal($user, $instance, true);
    $state->hook = function ($path) use ($change, $instance, $product) {
        if ($path !== '/use_credit_page') {
            return null;
        }
        if ($change === 'token') {
            $instance->update(['downstream_token' => str_repeat('b', 32)]);
        }
        if ($change === 'endpoint') {
            $product->upstreamApi->update(['base_url' => 'https://changed.example.test']);
        }
        if ($change === 'currency') {
            return Http::response(['status' => 200, 'data' => ['total' => '8.00', 'currency' => ['code' => 'USD']]]);
        }

        return null;
    };
    expandedWorker(FulfillmentTask::sole());
    expect($state->calls['/apply_credit'] ?? 0)->toBe(0)->and(FulfillmentTask::sole()->status)->toBe(FulfillmentTaskStatus::FAILED);
})->with(['token', 'endpoint', 'currency']);

it('does not redeliver an operation that was refunded before its worker starts', function (): void {
    [$user, $product, $instance, $state] = expandedClassic();
    $invoice = app(CreateComboInvoiceAction::class)->renewal($user, $instance, true);
    $invoice->update(['status' => InvoiceStatus::REFUNDED]);
    expandedWorker(FulfillmentTask::sole());
    expect($state->calls['/host/renew'] ?? 0)->toBe(0)->and($state->calls['/apply_credit'] ?? 0)->toBe(0);
});

it('validates operator downgrade bounds and protects quoted renewal invoices from later price changes', function (): void {
    [$user, $product, $instance] = expandedClassic();
    app(SyncClassicCatalogAction::class)->execute($product, 'Map options');
    $group = ProductOptionGroup::where('upstream_group_id', 50)->sole();
    $operator = User::factory()->create();
    app(AssignUpstreamRoleAction::class)->execute($operator, 'upstream-operator', 'Catalog authority');
    $action = app(ConfigureDowngradeBoundsAction::class);
    expect(fn () => $action->execute($group, true, null, [999999], $operator, 'Invalid foreign item'))->toThrow(DomainException::class);
    $item = $group->items->first()->id;
    $this->actingAs($operator)->post('/admin/upstream-options/'.$group->id.'/bounds', ['allow' => 1, 'items' => [$item], 'reason' => 'Approve price decrease'])
        ->assertRedirect('/admin/upstreams');
    expect($group->fresh()->downgrade_bounds['allowed_item_ids'])->toBe([$item]);
    $pairs = app(ResolveProductOptionsAction::class)->execute($product->fresh(), 'monthly', ['configoption' => [50 => 502]], false)['pairs'];
    $instance->update(['active_config' => ['options' => $pairs, 'upstream_configoption' => [50 => 502]], 'recurring_amount' => '12.00']);
    app(CreateComboInvoiceAction::class)->renewal($user, $instance->fresh());
    expect(fn () => app(DowngradeCalculatorAction::class)->execute($instance->fresh(), expandedOptions(), $user, 'Change while invoice open'))->toThrow(DomainException::class);
    expect($instance->fresh()->recurring_amount)->toBe('12.00');
    $this->assertDatabaseHas('audit_logs', ['action' => 'product.downgrade_bounds', 'actor_id' => $operator->id]);
});

it('keeps notification channel failures independent and records account email fallback', function (): void {
    [$user, $product, $instance] = expandedClassic();
    DB::table('user_notification_bindings')->insert([
        ['user_id' => $user->id, 'channel' => 'telegram', 'target' => 'verified-chat', 'verified_at' => now()],
        ['user_id' => $user->id, 'channel' => 'sms', 'target' => 'verified-phone', 'verified_at' => now()],
        ['user_id' => $user->id, 'channel' => 'email', 'target' => 'unverified@example.test', 'verified_at' => null],
    ]);
    $invoice = app(CreateComboInvoiceAction::class)->renewal($user, $instance);
    $queued = Queue::pushed(SendNotificationJob::class);
    expect($queued)->toHaveCount(3);
    foreach ($queued as $job) {
        $job->handle(app(SendLifecycleNotificationAction::class));
    }
    $this->assertDatabaseHas('notification_logs', ['channel' => 'telegram', 'status' => 'failed']);
    $this->assertDatabaseHas('notification_logs', ['channel' => 'sms', 'status' => 'simulated']);
    $this->assertDatabaseHas('notification_logs', ['channel' => 'email', 'target' => $user->email, 'status' => 'simulated', 'event_key' => 'renewal.invoice.'.$invoice->id.'.fallback']);
    $this->assertDatabaseMissing('notification_logs', ['target' => 'unverified@example.test']);
});

it('blocks another upstream renewal when the due boundary advanced before execution', function (): void {
    [$user, $product, $instance, $state] = expandedClassic();
    app(CreateComboInvoiceAction::class)->renewal($user, $instance, true);
    $state->due = Carbon::parse($state->due)->addMonthNoOverflow()->toDateTimeString();
    expandedWorker(FulfillmentTask::sole());
    expect($state->calls['/host/renew'] ?? 0)->toBe(0)->and($state->calls['/apply_credit'] ?? 0)->toBe(0)
        ->and(FulfillmentTask::sole()->status)->toBe(FulfillmentTaskStatus::FAILED);
});

it('hides removed upstream options and fields while preserving historical identifiers', function (): void {
    [$user, $product, $instance, $state] = expandedClassic();
    app(SyncClassicCatalogAction::class)->execute($product, 'Initial catalog');
    $group = ProductOptionGroup::where('upstream_group_id', 50)->sole();
    $state->hook = function ($path) {
        if ($path !== '/cart/get_product_config') {
            return null;
        }
        $catalog = expandedCatalog(2);
        $catalog['config_groups'] = [];
        $catalog['customfields'] = [];

        return Http::response(['status' => 200, 'data' => $catalog]);
    };
    app(SyncClassicCatalogAction::class)->execute($product->fresh(), 'Hide removed options');
    expect($group->fresh()->is_hidden)->toBeTrue()->and($group->fresh()->items)->toHaveCount(2);
    expect(ProductCustomField::where('upstream_field_id', 80)->sole()->is_hidden)->toBeTrue();
    expect(fn () => app(ResolveProductOptionsAction::class)->execute($product->fresh(), 'monthly', expandedOptions()))->toThrow(ValidationException::class);
});

it('accepts disabled toggles and bounded quantities while enforcing linked configuration rules', function (): void {
    [$user, $product, $instance, $state] = expandedClassic();
    $state->hook = function ($path) {
        if ($path !== '/cart/get_product_config') {
            return null;
        }
        $catalog = expandedCatalog();
        $catalog['customfields'] = [];
        $catalog['config_groups'][0]['options'] = [
            ['id' => 51, 'option_type' => 3, 'option_name' => 'Backup', 'sub' => [['id' => 510, 'option_name' => 'On', 'pricings' => [['code' => 'CNY', 'monthly' => '2.00', 'msetupfee' => '0.00']]]]],
            ['id' => 52, 'option_type' => 4, 'option_name' => 'Disk', 'qty_minimum' => 2, 'qty_maximum' => 10, 'qty_step' => 2,
                'sub' => [['id' => 520, 'option_name' => 'GB', 'qty_minimum' => 2, 'qty_maximum' => 10, 'pricings' => [['code' => 'CNY', 'monthly' => '1.00', 'msetupfee' => '0.00']]]]],
        ];
        $catalog['advanced'] = [
            ['id' => 1, 'type' => 'condition', 'config_id' => 51, 'relation' => 'seq', 'sub_id' => [510 => ['qty_minimum' => 0, 'qty_maximum' => 0]]],
            ['id' => 2, 'type' => 'result', 'relation_id' => 1, 'config_id' => 52, 'relation' => 'seq', 'sub_id' => [520 => ['qty_minimum' => 6, 'qty_maximum' => 10]]],
        ];

        return Http::response(['status' => 200, 'data' => $catalog]);
    };
    app(SyncClassicCatalogAction::class)->execute($product, 'Map toggles and quantities');
    $resolver = app(ResolveProductOptionsAction::class);
    $valid = $resolver->execute($product->fresh(), 'monthly', ['configoption' => [51 => 0, 52 => 2]]);
    expect($valid['pairs'][0]['qty'])->toBe(0)->and($valid['pairs'][1]['qty'])->toBe(2);
    expect(fn () => $resolver->execute($product->fresh(), 'monthly', ['configoption' => [51 => 0, 52 => 3]]))->toThrow(ValidationException::class);
    expect(fn () => $resolver->execute($product->fresh(), 'monthly', ['configoption' => [51 => 510, 52 => 2]]))->toThrow(ValidationException::class);
    expect($resolver->execute($product->fresh(), 'monthly', ['configoption' => [51 => 510, 52 => 6]])['pairs'][1]['qty'])->toBe(6);
});

it('does not replay an ambiguous upgrade checkout even after operator retry', function (): void {
    [$user, $product, $instance, $state] = expandedClassic();
    app(SyncClassicCatalogAction::class)->execute($product, 'Map upgrade');
    $mapped = app(ResolveProductOptionsAction::class)->execute($product->fresh(), 'monthly', expandedOptions());
    $instance->update(['active_config' => ['options' => $mapped['pairs'], 'upstream_configoption' => [50 => 501]]]);
    app(CreateComboInvoiceAction::class)->upgrade($user, $instance->fresh(), ['configoption' => [50 => 502]], (string) Str::ulid(), true);
    $state->hook = fn ($path) => $path === '/upgrade/checkout_config_upgrade' ? Http::response(['status' => 400, '_transport_failure' => true]) : null;
    $task = FulfillmentTask::sole();
    expandedWorker($task);
    $operator = User::factory()->create();
    app(AssignUpstreamRoleAction::class)->execute($operator, 'upstream-lifecycle', 'Reconciliation authority');
    $this->actingAs($operator)->post('/admin/upstream-tasks/'.$task->id.'/retry', ['reason' => 'Reconcile original intent', 'confirm' => '1'])->assertRedirect('/admin/upstream-instances');
    expandedWorker($task);
    expect($state->calls['/upgrade/checkout_config_upgrade'])->toBe(1)->and($state->calls['/apply_credit'] ?? 0)->toBe(0)
        ->and($task->fresh()->status)->toBe(FulfillmentTaskStatus::FAILED);
});

it('blocks hot swap until an unresolved paid operation is reconciled', function (): void {
    [$user, $product, $instance] = expandedClassic();
    app(CreateComboInvoiceAction::class)->renewal($user, $instance, true);
    $operator = User::factory()->create();
    app(AssignUpstreamRoleAction::class)->execute($operator, 'upstream-migrator', 'Migration');
    $target = UpstreamApi::factory()->create();
    expect(fn () => app(HotSwapInstanceDriverAction::class)->execute($instance->fresh(), $target->id, '199', '17', 'keep_orphan', 'Cannot hide paid intent', $operator, true))->toThrow(DomainException::class);
    expect($instance->fresh()->upstream_api_id)->toBe($product->upstream_api_id)->and(FulfillmentTask::sole()->status)->toBe(FulfillmentTaskStatus::PENDING);
    $this->assertDatabaseCount('instance_upstream_bindings', 0);
});

it('refuses to bind two local services to the same upstream host atomically', function (): void {
    [$user, $product, $instance] = expandedClassic();
    $operator = User::factory()->create();
    app(AssignUpstreamRoleAction::class)->execute($operator, 'upstream-migrator', 'Migration');
    $target = UpstreamApi::factory()->create();
    Instance::factory()->create(['upstream_api_id' => $target->id, 'upstream_instance_id' => '199']);
    expect(fn () => app(HotSwapInstanceDriverAction::class)->execute($instance->fresh(), $target->id, '199', '17', 'keep_orphan', 'Duplicate target', $operator, true))->toThrow(QueryException::class);
    expect($instance->fresh()->upstream_api_id)->toBe($product->upstream_api_id);
    $this->assertDatabaseCount('instance_upstream_bindings', 0);
    $this->assertDatabaseCount('fulfillment_tasks', 0);
});

it('loads explicit Livewire assets in the admin panel without injecting scripts into the public CMS', function (): void {
    expandedClassic();
    $viewer = User::factory()->create();
    app(AssignUpstreamRoleAction::class)->execute($viewer, 'upstream-viewer', 'Inspect panel assets');
    $this->actingAs($viewer)->get('/admin/upstreams')->assertOk()->assertSee('/livewire-', false);
    $this->get('/help')->assertOk()->assertDontSee('<script', false);
});
