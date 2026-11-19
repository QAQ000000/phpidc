<?php

declare(strict_types=1);

use App\Actions\ConfigureDowngradeBoundsAction;
use App\Actions\CreateComboInvoiceAction;
use App\Actions\DowngradeCalculatorAction;
use App\Actions\ExecuteFulfillmentTaskAction;
use App\Actions\ImportClassicProductAction;
use App\Actions\QuoteLocalProductAction;
use App\Actions\ResolveProductOptionsAction;
use App\Actions\UpgradeCalculatorAction;
use App\Enums\BillingCycle;
use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\InstanceMappingReview;
use App\Models\InstanceUpstreamBinding;
use App\Models\Invoice;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Services\DriverManager;
use App\Services\InstanceMappingEvidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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

/** A synthetic catalog with two independent roots and a three-level active branch. */
function linkagePurchaseCatalog(): array
{
    $sub = fn (int $id, int $parent, int $root, string $level, string $price, string $setup): array => [
        'id' => $id, 'option_name' => 'Choice '.$id, 'linkage_pid' => $parent, 'linkage_top_pid' => $root, 'linkage_level' => $level,
        'pricings' => [['code' => 'CNY', 'monthly' => $price, 'msetupfee' => $setup]],
    ];
    $group = fn (int $id, int $parent, int $root, string $level, array $subs): array => [
        'id' => $id, 'option_type' => 20, 'option_name' => 'Level '.$id, 'upgrade' => 1,
        'linkage_pid' => $parent, 'linkage_top_pid' => $root, 'linkage_level' => $level, 'sub' => $subs,
    ];

    return ['products' => ['id' => 17, 'name' => 'Linked synthetic cloud', 'p_uid' => 0, 'location_version' => 1,
        'stock_control' => 1, 'qty' => 10, 'pay_type' => ['pay_type' => 'recurring', 'pay_monthly_cycle' => 1],
        'password' => ['show' => 1, 'rule' => ['upper' => 1, 'lower' => 1, 'num' => 1, 'special' => 1]]],
        'product_pricings' => [['code' => 'CNY', 'monthly' => '8.00', 'msetupfee' => '2.00']],
        'config_groups' => [['id' => 5, 'options' => [
            $group(10, 0, 0, '0-10', [$sub(101, 0, 0, '0-101', '1.00', '0.50'), $sub(102, 0, 0, '0-102', '10.00', '1.00')]),
            $group(20, 10, 10, '0-10-20', [$sub(201, 101, 101, '0-101-201', '2.00', '0.25')]),
            $group(40, 20, 10, '0-10-20-40', [$sub(401, 201, 101, '0-101-201-401', '3.00', '0.75')]),
            $group(30, 10, 10, '0-10-30', [$sub(301, 102, 102, '0-102-301', '30.00', '10.00')]),
            $group(50, 0, 0, '0-50', [$sub(501, 0, 0, '0-501', '4.00', '1.00')]),
            ['id' => 60, 'option_type' => 1, 'option_name' => 'Ordinary option', 'upgrade' => 1,
                'sub' => [$sub(601, 0, 0, '0-601', '5.00', '0.00'), $sub(602, 0, 0, '0-602', '8.00', '0.00')]],
        ]]], 'advanced' => [], 'customfields' => []];
}

function linkagePurchaseFixture(): array
{
    $account = UpstreamApi::factory()->create();
    $user = User::factory()->create(['credit' => '100.00']);
    $selected = [10 => 101, 20 => 201, 40 => 401, 50 => 501, 60 => 601];
    $state = (object) ['catalog' => linkagePurchaseCatalog(), 'calls' => [], 'selected' => $selected, 'total' => '27.50', 'setup' => '4.50'];
    $level = DB::transactionLevel();
    Http::fake(function ($request) use ($state, $level) {
        expect(DB::transactionLevel())->toBe($level);
        $path = parse_url($request->url(), PHP_URL_PATH);
        $state->calls[] = $path;
        if ($path === '/cart/get_total') {
            expect($request['configoption'])->toEqual($state->selected);
        }
        if ($path === '/cart/add_to_shop') {
            expect($request['configoption'])->toEqual($state->selected);
        }
        if ($path === '/cart/settle') {
            expect($request['cart_data']['configoptions'])->toEqual($state->selected);
        }

        return Http::response(match ($path) {
            '/zjmf_api_login' => ['status' => 200, 'jwt' => 'isolated-linkage-jwt'],
            '/cart/get_product_config' => ['status' => 200, 'data' => $state->catalog],
            '/user_info' => ['status' => 200, 'data' => ['user' => ['currency' => 1]]],
            '/cart/stock_control' => ['status' => 200, 'data' => ['product' => ['hidden' => 0, 'stock_control' => 1, 'qty' => 10]]],
            '/cart/get_total' => ['status' => 200, 'products' => ['total' => $state->total, 'setupfee_total' => $state->setup], 'currency' => ['code' => 'CNY']],
            '/cart/clear', '/cart/add_to_shop' => ['status' => 200],
            '/cart/settle' => ['status' => 200, 'data' => ['invoiceid' => 42, 'hostid' => [999]]],
            '/use_credit_page' => ['status' => 200, 'data' => ['total' => $state->total, 'currency' => ['code' => 'CNY']]],
            '/apply_credit' => ['status' => 1001, 'data' => ['hostid' => [999]]],
            '/host/header' => ['status' => 200, 'data' => ['host_data' => ['domainstatus' => 'Active']]],
            '/host/renewpage' => ['status' => 200, 'data' => ['host' => ['amount' => '23.00', 'billingcycle' => 'monthly'], 'currency' => ['code' => 'CNY']]],
            default => throw new RuntimeException('Unexpected linkage acceptance request: '.$path),
        });
    });
    $product = app(ImportClassicProductAction::class)->execute($account->id, '17', BillingCycle::MONTHLY, 'CNY', '20.00', 'Import synthetic linkage fixture');
    $payload = ['product_id' => $product->id, 'request_id' => (string) Str::ulid(), 'billing_cycle' => 'monthly',
        'options' => ['configoption' => $selected], 'use_credit' => true, 'hostname' => 'linked-cloud', 'password' => 'LinkedPassword12!'];

    return [$user, $product->fresh(), $state, $payload];
}

function linkagePurchaseNoFinancialWrites(User $user): void
{
    expect($user->fresh()->credit)->toBe('100.00')->and($user->fresh()->frozen_credit)->toBe('0.00');
    foreach (['invoices', 'instances', 'fulfillment_tasks', 'financial_transactions', 'payment_attempts'] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
}

it('imports and charges every active linked level through local quotation live confirmation and paid delivery exactly once', function (): void {
    [$user, $product, $state, $payload] = linkagePurchaseFixture();
    expect($product->optionGroups()->count())->toBe(6);
    $calls = count($state->calls);
    $quote = $this->actingAs($user)->postJson('/console/products/'.$product->id.'/quote', $payload)->assertOk()
        ->assertJsonPath('price', '27.60')->assertJsonPath('setup_fee', '5.40')->assertJsonPath('estimate', true);
    expect(count($state->calls))->toBe($calls);
    linkagePurchaseNoFinancialWrites($user);
    $checkout = $payload + ['quote_token' => $quote->json('quote_token')];
    $response = $this->postJson('/checkout', $checkout)->assertCreated()->assertJsonPath('status', 'Paid')
        ->assertJsonPath('total_amount', '33.00')->assertJsonPath('credit_applied', '33.00');
    $invoice = Invoice::sole();
    expect($invoice->id)->toBe($response->json('invoice_id'))->and($invoice->status)->toBe(InvoiceStatus::PAID)
        ->and($invoice->upstream_expected_cost)->toBe('27.50')
        ->and($invoice->line_items['purchase']['options']['configoption'])->toEqual($state->selected)
        ->and($user->fresh()->credit)->toBe('67.00');
    $calls = count($state->calls);
    $this->postJson('/checkout', $checkout)->assertCreated()->assertJsonPath('invoice_id', $invoice->id);
    expect(count($state->calls))->toBe($calls)->and(Invoice::count())->toBe(1)->and(FulfillmentTask::count())->toBe(1);
    $task = FulfillmentTask::sole();
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    $instance = $task->instance->fresh();
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS)->and($instance->status)->toBe(InstanceStatus::ACTIVE)
        ->and($instance->recurring_amount)->toBe('27.60')->and($instance->upstream_instance_id)->toBe('999')
        ->and($instance->active_config['upstream_configoption'])->toEqual($state->selected)
        ->and(collect($instance->active_config['options'])->pluck('upstream_group_id')->all())->toBe([10, 20, 40, 50, 60]);
    expect(collect($state->calls)->filter(fn ($path) => $path === '/cart/settle')->count())->toBe(1)
        ->and(collect($state->calls)->filter(fn ($path) => $path === '/apply_credit')->count())->toBe(1);
    $renewal = app(DriverManager::class)->classicAccount($instance->upstream_api_id)->quoteRenewal($instance, 'monthly');
    expect($renewal->upstreamBaseCost)->toBe('23.00')->and($renewal->downstreamPrice)->toBe('27.60')->and($renewal->setupFee)->toBe('0.00');
});

it('prices the replacement branch without retaining previous descendants', function (): void {
    [, $product] = linkagePurchaseFixture();
    $selected = [10 => 102, 30 => 301, 50 => 501, 60 => 601];
    $resolved = app(ResolveProductOptionsAction::class)->execute($product, 'monthly', ['configoption' => $selected]);
    $quote = app(QuoteLocalProductAction::class)->execute($product, 'monthly', $resolved);
    expect($resolved['configoption'])->toEqual($selected)
        ->and(collect($resolved['pairs'])->pluck('upstream_group_id')->all())->toBe([10, 30, 50, 60])
        ->and($quote->downstreamPrice)->toBe('68.40')->and($quote->setupFee)->toBe('16.80')->and($quote->upstreamBaseCost)->toBe('71.00');
});

it('requires a second price confirmation after a linked live quote drifts before creating a paid order', function (): void {
    [$user, $product, $state, $payload] = linkagePurchaseFixture();
    $quote = $this->actingAs($user)->postJson('/console/products/'.$product->id.'/quote', $payload)->assertOk()
        ->assertJsonPath('price', '27.60')->assertJsonPath('setup_fee', '5.40');
    $checkout = $payload + ['quote_token' => $quote->json('quote_token')];
    $state->total = '28.50';
    $changed = $this->postJson('/checkout', $checkout)->assertConflict()->assertJsonPath('price_changed', true)
        ->assertJsonPath('quote.price', '28.80')->assertJsonPath('quote.setup_fee', '5.40');
    linkagePurchaseNoFinancialWrites($user);
    $checkout['quote_token'] = $changed->json('quote.quote_token');
    $this->postJson('/checkout', $checkout)->assertCreated()->assertJsonPath('status', 'Paid')->assertJsonPath('total_amount', '34.20');
    $invoice = Invoice::sole();
    expect($invoice->upstream_expected_cost)->toBe('28.50')->and($user->fresh()->credit)->toBe('65.80')
        ->and(collect($state->calls)->filter(fn ($path) => $path === '/cart/get_total')->count())->toBe(2);
    $task = FulfillmentTask::sole();
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS)
        ->and($task->instance->fresh()->active_config['upstream_configoption'])->toEqual($state->selected)
        ->and($task->instance->fresh()->recurring_amount)->toBe('28.80');
});

it('rejects malformed linked selections before remote quotes wallet changes invoices or tasks', function (string $mutation): void {
    [$user, $product, $state, $payload] = linkagePurchaseFixture();
    $selected = $payload['options']['configoption'];
    if ($mutation === 'cross root') {
        $selected[10] = 501;
    } elseif ($mutation === 'old branch') {
        $selected[10] = 102;
        $selected[30] = 301;
    } elseif ($mutation === 'inactive branch') {
        $selected[30] = 301;
    } elseif ($mutation === 'missing root') {
        unset($selected[50]);
    } elseif ($mutation === 'missing child') {
        unset($selected[20]);
    } elseif ($mutation === 'unknown group') {
        $selected[999] = 9991;
    } elseif ($mutation === 'unknown sub') {
        $selected[20] = 9991;
    } elseif ($mutation === 'hidden group') {
        $product->optionGroups()->where('upstream_group_id', 20)->update(['is_hidden' => true]);
    } else {
        $product->optionGroups()->where('upstream_group_id', 20)->firstOrFail()->items()->where('upstream_sub_id', 201)->update(['is_hidden' => true]);
    }
    $payload['options']['configoption'] = $selected;
    $calls = count($state->calls);
    $this->actingAs($user)->postJson('/console/products/'.$product->id.'/quote', $payload)->assertUnprocessable()->assertJsonValidationErrors('options');
    $this->postJson('/checkout', $payload)->assertUnprocessable()->assertJsonValidationErrors('options');
    expect(count($state->calls))->toBe($calls);
    linkagePurchaseNoFinancialWrites($user);
})->with(['cross root', 'old branch', 'inactive branch', 'missing root', 'missing child', 'unknown group', 'unknown sub', 'hidden group', 'hidden sub']);

it('independently rejects forged linked price pairs', function (string $mutation): void {
    [$user, $product, $state, $payload] = linkagePurchaseFixture();
    $resolved = app(ResolveProductOptionsAction::class)->execute($product, 'monthly', $payload['options']);
    if ($mutation === 'replace item') {
        $item = $product->optionGroups()->where('upstream_group_id', 10)->firstOrFail()->items()->where('upstream_sub_id', 102)->firstOrFail();
        $index = array_search(10, array_column($resolved['pairs'], 'upstream_group_id'), true);
        $resolved['pairs'][$index]['local_item_id'] = $item->id;
        $resolved['pairs'][$index]['upstream_sub_id'] = 102;
        $resolved['pairs'][$index]['unit_price'] = $item->local_prices['monthly']['price'];
    } elseif ($mutation === 'missing pair') {
        array_pop($resolved['pairs']);
    } elseif ($mutation === 'extra pair') {
        $resolved['pairs'][] = $resolved['pairs'][0];
    } else {
        $group = $product->optionGroups()->where('upstream_group_id', 30)->firstOrFail();
        $item = $group->items()->firstOrFail();
        $resolved['pairs'][] = ['local_group_id' => $group->id, 'local_item_id' => $item->id,
            'upstream_group_id' => 30, 'upstream_sub_id' => 301, 'qty' => 1, 'unit_price' => $item->local_prices['monthly']['price']];
        $resolved['configoption'][30] = 301;
    }
    $calls = count($state->calls);
    expect(fn () => app(QuoteLocalProductAction::class)->execute($product, 'monthly', $resolved))->toThrow(ValidationException::class);
    expect(count($state->calls))->toBe($calls);
    linkagePurchaseNoFinancialWrites($user);
})->with(['replace item', 'missing pair', 'extra pair', 'inactive pair']);

it('does not activate advanced rules for an absent linked condition or require absent linked results', function (string $case): void {
    [, $product, , $payload] = linkagePurchaseFixture();
    $conditionGroup = $case === 'inactive condition' ? 30 : 10;
    $resultGroup = $case === 'inactive condition' ? 60 : 30;
    $conditionSub = $case === 'inactive condition' ? 999 : 101;
    $resultSub = $case === 'inactive condition' ? 602 : 301;
    $rules = $product->provisioning_rules;
    $rules['classic_catalog']['dependencies'] = [
        ['id' => 1, 'type' => 'condition', 'config_id' => $conditionGroup, 'relation' => $case === 'inactive condition' ? 'sneq' : 'seq', 'sub_id' => [$conditionSub => []]],
        ['id' => 2, 'type' => 'result', 'config_id' => $resultGroup, 'relation_id' => 1, 'relation' => 'seq', 'sub_id' => [$resultSub => []]],
    ];
    $product->update(['provisioning_rules' => $rules]);
    expect(app(ResolveProductOptionsAction::class)->execute($product->fresh(), 'monthly', $payload['options'])['configoption'])
        ->toEqual($payload['options']['configoption']);
})->with(['inactive condition', 'inactive result']);

it('still enforces an active linked advanced condition against active ordinary selections', function (string $relation): void {
    [$user, $product, , $payload] = linkagePurchaseFixture();
    $rules = $product->provisioning_rules;
    $rules['classic_catalog']['dependencies'] = [
        ['id' => 1, 'type' => 'condition', 'config_id' => 20, 'relation' => $relation, 'sub_id' => [$relation === 'seq' ? 201 : 299 => []]],
        ['id' => 2, 'type' => 'result', 'config_id' => 60, 'relation_id' => 1, 'relation' => 'seq', 'sub_id' => [602 => []]],
    ];
    $product->update(['provisioning_rules' => $rules]);
    expect(fn () => app(ResolveProductOptionsAction::class)->execute($product->fresh(), 'monthly', $payload['options']))->toThrow(ValidationException::class);
    linkagePurchaseNoFinancialWrites($user);
})->with(['seq', 'sneq']);

it('blocks linked upgrades including ordinary option changes and incomplete previous snapshots before supplier work', function (bool $missingSnapshot): void {
    [$user, $product, $state, $payload] = linkagePurchaseFixture();
    $resolved = app(ResolveProductOptionsAction::class)->execute($product, 'monthly', $payload['options']);
    $instance = Instance::factory()->active()->create(['user_id' => $user->id, 'product_id' => $product->id, 'driver_type' => DriverType::ZJMF_API,
        'upstream_api_id' => $product->upstream_api_id, 'upstream_instance_id' => '999', 'recurring_amount' => '27.60',
        'active_config' => ['upstream_pid' => '17', 'options' => $missingSnapshot ? [] : $resolved['pairs'], 'upstream_configoption' => $resolved['configoption']]]);
    $options = $payload['options'];
    $options['configoption'][60] = 602;
    $before = $instance->fresh()->getRawOriginal();
    $calls = count($state->calls);
    expect(fn () => app(CreateComboInvoiceAction::class)->upgrade($user, $instance, $options, (string) Str::ulid(), true))
        ->toThrow(DomainException::class, 'Hierarchical configurations');
    expect($instance->fresh()->getRawOriginal())->toBe($before)->and(count($state->calls))->toBe($calls);
    expect(Invoice::count())->toBe(0)->and(FulfillmentTask::count())->toBe(0)->and($user->fresh()->credit)->toBe('100.00');
})->with([false, true]);

it('blocks linked billing downgrades and operator enablement without changing prices or bounds', function (): void {
    [$user, $product, $state, $payload] = linkagePurchaseFixture();
    $resolved = app(ResolveProductOptionsAction::class)->execute($product, 'monthly', $payload['options']);
    $instance = Instance::factory()->active()->create(['user_id' => $user->id, 'product_id' => $product->id, 'driver_type' => DriverType::ZJMF_API,
        'upstream_api_id' => $product->upstream_api_id, 'upstream_instance_id' => '999', 'recurring_amount' => '27.60',
        'active_config' => ['upstream_pid' => '17', 'options' => $resolved['pairs'], 'upstream_configoption' => $resolved['configoption']]]);
    $before = $instance->fresh()->getRawOriginal();
    $calls = count($state->calls);
    expect(fn () => app(DowngradeCalculatorAction::class)->execute($instance, $payload['options'], $user, 'Synthetic downgrade'))
        ->toThrow(DomainException::class, 'Hierarchical configurations');
    $group = $product->optionGroups()->where('upstream_group_id', 10)->firstOrFail();
    $boundsBefore = $group->getRawOriginal();
    Gate::define('upstream.import', fn (User $actor): bool => $actor->id === $user->id);
    expect(fn () => app(ConfigureDowngradeBoundsAction::class)->execute($group, true, null, $group->items()->pluck('id')->all(), $user, 'Synthetic bounds'))
        ->toThrow(DomainException::class, 'Only visible supported upstream options');
    expect($instance->fresh()->getRawOriginal())->toBe($before)->and($group->fresh()->getRawOriginal())->toBe($boundsBefore)
        ->and(count($state->calls))->toBe($calls)->and(Invoice::count())->toBe(0)->and(FulfillmentTask::count())->toBe(0);
});

it('ignores unreferenced hidden linked history but still checks hidden groups referenced by snapshots or targets', function (string $reference): void {
    [$user, $product, $state, $payload] = linkagePurchaseFixture();
    $original = app(ResolveProductOptionsAction::class)->execute($product, 'monthly', $payload['options']);
    $linkedPair = $original['pairs'][0];
    $product->optionGroups()->where('upstream_group_id', '!=', 60)->update(['is_hidden' => true]);
    $old = app(ResolveProductOptionsAction::class)->execute($product, 'monthly', ['configoption' => [60 => 601]]);
    $target = app(ResolveProductOptionsAction::class)->execute($product, 'monthly', ['configoption' => [60 => 602]]);
    $config = ['upstream_pid' => '17', 'options' => $old['pairs'], 'upstream_configoption' => $old['configoption']];
    if ($reference === 'old option') {
        $config['options'][] = $linkedPair;
    } elseif ($reference === 'billing snapshot') {
        $config['billing_adjustment']['options'] = [...$old['pairs'], $linkedPair];
    } elseif ($reference === 'target option') {
        $target['pairs'][] = $linkedPair;
    }
    $instance = Instance::factory()->active()->create(['user_id' => $user->id, 'product_id' => $product->id,
        'driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $product->upstream_api_id, 'upstream_instance_id' => '999',
        'active_config' => $config, 'recurring_amount' => '15.60']);
    $calls = count($state->calls);
    if ($reference === 'unreferenced') {
        expect(app(UpgradeCalculatorAction::class)->execute($instance, $target))->toBe('19.20');
        $group = $product->optionGroups()->where('upstream_group_id', 60)->firstOrFail();
        $item = $group->items()->where('upstream_sub_id', 601)->firstOrFail();
        $group->update(['downgrade_bounds' => ['allow_downgrade' => true, 'allowed_item_ids' => [$item->id]]]);
        $instance->update(['recurring_amount' => '19.20', 'active_config' => ['options' => $target['pairs']]]);
        expect(app(DowngradeCalculatorAction::class)->execute($instance, ['configoption' => [60 => 601]], $user, 'Only ordinary options remain'))->toBe('15.60');
    } else {
        expect(fn () => app(UpgradeCalculatorAction::class)->execute($instance, $target))->toThrow(DomainException::class, 'Hierarchical configurations');
        if ($reference !== 'target option') {
            expect(fn () => app(DowngradeCalculatorAction::class)->execute($instance, ['configoption' => [60 => 601]], $user, 'Hidden snapshot reference'))
                ->toThrow(DomainException::class, 'Hierarchical configurations');
        }
    }
    expect(count($state->calls))->toBe($calls)->and(Invoice::count())->toBe(0)->and(FulfillmentTask::count())->toBe(0)
        ->and($user->fresh()->credit)->toBe('100.00');
})->with(['unreferenced', 'old option', 'billing snapshot', 'target option']);

it('keeps an already paid upgrade request replayable before applying new linked admission rules', function (): void {
    [$user, $product, $state] = linkagePurchaseFixture();
    $instance = Instance::factory()->active()->create(['user_id' => $user->id, 'product_id' => $product->id,
        'driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $product->upstream_api_id, 'upstream_instance_id' => '999']);
    $requestId = (string) Str::ulid();
    $invoice = Invoice::factory()->paid()->create(['user_id' => $user->id, 'instance_id' => $instance->id, 'product_id' => $product->id,
        'type' => InvoiceType::UPGRADE, 'line_items' => ['request_id' => $requestId]]);
    $before = $invoice->fresh()->getRawOriginal();
    $calls = count($state->calls);
    expect(app(CreateComboInvoiceAction::class)->upgrade($user, $instance, [], $requestId)->id)->toBe($invoice->id)
        ->and(count($state->calls))->toBe($calls)->and(Invoice::count())->toBe(1)
        ->and($invoice->fresh()->getRawOriginal())->toBe($before)->and($user->fresh()->credit)->toBe('100.00');
});

it('keeps linked configuration mapping review explicitly unsupported even with full direct ID evidence', function (): void {
    [$user, $product, $state] = linkagePurchaseFixture();
    $instance = Instance::factory()->active()->create(['user_id' => $user->id, 'product_id' => $product->id,
        'driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $product->upstream_api_id, 'upstream_instance_id' => '999',
        'active_config' => ['upstream_pid' => '17', 'mapping_requires_review' => true, 'options' => []]]);
    InstanceUpstreamBinding::create(['instance_id' => $instance->id, 'driver_type' => DriverType::ZJMF_API,
        'upstream_api_id' => $product->upstream_api_id, 'upstream_instance_id' => '999', 'bound_at' => now()]);
    $actual = [];
    foreach ($state->catalog['config_groups'][0]['options'] as $option) {
        $actual[] = ['group_id' => $option['id'], 'sub_id' => $option['sub'][0]['id'], 'qty' => 1, 'option_type' => $option['option_type']];
    }
    $before = $instance->fresh()->getRawOriginal();
    expect(fn () => app(InstanceMappingEvidence::class)->snapshot($instance->fresh('upstreamApi'), $product->load('optionGroups.items'),
        ['catalog' => $state->catalog, 'actual_options' => $actual, 'renewal_cost' => '23.00']))
        ->toThrow(ValidationException::class, '存在缺失映射');
    expect($instance->fresh()->getRawOriginal())->toBe($before)->and(InstanceMappingReview::count())->toBe(0)
        ->and(Invoice::count())->toBe(0)->and(FulfillmentTask::count())->toBe(0);
});
