<?php

declare(strict_types=1);

use App\Actions\DescribeStoreProductAction;
use App\Actions\ExecuteFulfillmentTaskAction;
use App\Actions\GenerateProductProvisioningDefaultsAction;
use App\Actions\ImportClassicProductAction;
use App\Enums\BillingCycle;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\FulfillmentTask;
use App\Models\Invoice;
use App\Models\UpstreamApi;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Http::preventStrayRequests();
    Queue::fake();
});

/** Minimal synthetic values reproduce the three current catalog shapes without copying private products. */
function currentClassicAcceptanceCatalog(int $pid): array
{
    $cycle = $pid === 731 ? 'onetime' : 'monthly';
    $price = fn (string $amount): array => ['code' => 'CNY', $cycle => $amount, $pid === 731 ? 'osetupfee' : 'msetupfee' => '0.00'];
    $sub = fn (int $id, string $label, string $amount): array => ['id' => $id, 'option_name' => $label, 'pricings' => [$price($amount)]];
    $catalog = ['products' => ['id' => $pid, 'name' => 'Current catalog shape '.$pid,
        'type' => match ($pid) {
            391 => 'cdn', 731 => 'software', 14 => 'dcimcloud'
        },
        'p_uid' => 0, 'location_version' => 1, 'stock_control' => 1, 'qty' => $pid === 14 ? 0 : 10,
        'pay_type' => json_encode(['pay_type' => $pid === 731 ? 'onetime' : 'recurring', 'pay_monthly_cycle' => 1,
            'pay_ontrial_status' => $pid === 391 ? 1 : 0, 'pay_ontrial_cycle' => '3', 'pay_ontrial_cycle_type' => 'hour',
            'pay_ontrial_num' => '888', 'pay_ontrial_num_rule' => '0', 'pay_ontrial_condition' => []], JSON_THROW_ON_ERROR),
        'host' => ['show' => $pid === 14 ? 0 : 1, 'prefix' => 'ser', 'rule' => ['upper' => 0, 'lower' => 1, 'num' => 1, 'len_num' => 10]],
        'password' => ['show' => $pid === 731 ? 0 : 1, 'rule' => ['upper' => 1, 'lower' => 1, 'num' => 1, 'special' => 0, 'len_num' => 12]]],
        'product_pricings' => [$price($pid === 391 ? '1.00' : ($pid === 731 ? '0.00' : '2.00'))],
        'config_groups' => [], 'customfields' => [], 'advanced' => []];
    if ($pid === 391) {
        $catalog['product_pricings'][0] += ['ontrial' => '1.00', 'ontrialfee' => '0.00'];
    } elseif ($pid === 731) {
        $catalog['config_groups'] = [['id' => 10, 'options' => [
            ['id' => 40, 'option_name' => 'API amount', 'option_type' => 4, 'qty_minimum' => 1, 'qty_maximum' => 1000, 'qty_stage' => 1,
                'sub' => [$sub(401, 'Amount', '1.00') + ['qty_minimum' => 1, 'qty_maximum' => 1000]]],
        ]]];
    } else {
        $catalog['config_groups'] = [['id' => 10, 'options' => [
            ['id' => 50, 'option_name' => 'Operating system', 'option_type' => 5,
                'sub' => [$sub(501, 'First OS', '0.00'), $sub(502, 'Selected OS', '0.00')]],
            ['id' => 60, 'option_name' => 'Cloud size', 'option_type' => 8,
                'sub' => [$sub(601, 'Small', '0.00'), $sub(602, 'Large', '3.00')]],
            ['id' => 70, 'option_name' => 'Region', 'option_type' => 6,
                'sub' => [$sub(701, 'First region', '0.00'), $sub(702, 'Selected region', '2.00')]],
        ]]];
    }

    return $catalog;
}

function currentClassicAcceptanceFixture(int $pid, bool $availableCloud = false): array
{
    $catalog = currentClassicAcceptanceCatalog($pid);
    // The saved cloud snapshot is sold out. Only this explicit fake branch exercises its purchase protocol.
    if ($pid === 14 && $availableCloud) {
        $catalog['products']['qty'] = 3;
    }
    $selected = match ($pid) {
        391 => [], 731 => [40 => 3], 14 => [50 => 502, 60 => 602, 70 => 702]
    };
    [$cost, $price, $balance] = match ($pid) {
        391 => ['1.00', '1.20', '98.80'], 731 => ['3.00', '3.60', '96.40'], 14 => ['7.00', '8.40', '91.60'],
    };
    $state = (object) ['catalog' => $catalog, 'selected' => $selected, 'calls' => [], 'cost' => $cost, 'price' => $price, 'balance' => $balance];
    $level = DB::transactionLevel();
    Http::fake(function ($request) use ($state, $level) {
        expect(DB::transactionLevel())->toBe($level);
        $path = parse_url($request->url(), PHP_URL_PATH);
        $state->calls[] = $path;
        if (in_array($path, ['/cart/get_total', '/cart/add_to_shop', '/cart/settle'], true)) {
            $body = $path === '/cart/settle' ? $request['cart_data'] : $request->data();
            expect($body[$path === '/cart/settle' ? 'configoptions' : 'configoption'])->toEqual($state->selected)
                ->and($body['customfield'])->toBe([]);
            foreach (['serverid', 'hostid', 'os'] as $unsupported) {
                expect($body)->not->toHaveKey($unsupported)->and($request->data())->not->toHaveKey($unsupported);
            }
            if ($path !== '/cart/get_total') {
                expect($body['host'])->toBeString()->not->toBe('')->and($body['password'])->toBeString()->not->toBe('');
            }
        }

        return Http::response(match ($path) {
            '/zjmf_api_login' => ['status' => 200, 'jwt' => 'synthetic-current-catalog-jwt'],
            '/cart/get_product_config' => ['status' => 200, 'data' => $state->catalog],
            '/user_info' => ['status' => 200, 'user' => ['currency' => 1]],
            '/cart/stock_control' => ['status' => 200, 'data' => ['product' => ['hidden' => 0, 'stock_control' => 1, 'qty' => $state->catalog['products']['qty']]]],
            '/cart/get_total' => ['status' => 200, 'products' => ['total' => $state->cost, 'setupfee_total' => '0.00'], 'currency' => ['code' => 'CNY']],
            '/cart/clear', '/cart/add_to_shop' => ['status' => 200],
            '/cart/settle' => ['status' => 200, 'data' => ['invoiceid' => 42, 'hostid' => [999]]],
            '/use_credit_page' => ['status' => 200, 'data' => ['total' => $state->cost, 'currency' => ['code' => 'CNY']]],
            '/apply_credit' => ['status' => 1001, 'data' => ['hostid' => [999]]],
            '/host/header' => ['status' => 200, 'data' => ['host_data' => ['domainstatus' => 'Active']]],
            default => throw new RuntimeException('Unexpected current catalog request: '.$path),
        });
    });
    $account = UpstreamApi::factory()->create();
    $cycle = $pid === 731 ? BillingCycle::ONETIME : BillingCycle::MONTHLY;
    $product = app(ImportClassicProductAction::class)->execute($account->id, (string) $pid, $cycle, 'CNY', '20.00', 'Import synthetic current catalog shape');
    $defaults = app(GenerateProductProvisioningDefaultsAction::class)->execute($product);
    expect($defaults['errors'])->toBe([])->and($defaults['hostname'])->toBeString()->not->toBe('')->and($defaults['password'])->toBeString()->not->toBe('');
    $user = User::factory()->create(['credit' => '100.00']);
    $payload = ['product_id' => $product->id, 'request_id' => (string) Str::ulid(), 'billing_cycle' => $cycle->value,
        'options' => ['configoption' => $selected, 'customfield' => []], 'use_credit' => true,
        'hostname' => $defaults['hostname'], 'password' => $defaults['password'],
        'serverid' => 12345, 'hostid' => 54321, 'os' => ['id' => 'client-injected-os']];

    return [$user, $product, $state, $payload];
}

function currentClassicAcceptanceNoFinancialWrites(User $user): void
{
    expect($user->fresh()->credit)->toBe('100.00')->and($user->fresh()->frozen_credit)->toBe('0.00');
    foreach (['invoices', 'financial_transactions', 'payment_attempts', 'instances', 'fulfillment_tasks'] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
}

it('preserves current catalog selections through sync local quote live confirmation Paid and the P3 P4 payloads', function (int $pid): void {
    [$user, $product, $state, $payload] = currentClassicAcceptanceFixture($pid, availableCloud: $pid === 14);
    expect($product->provisioning_rules['classic_catalog']['product_publisher_uid'])->toBe(0)
        ->and($product->customFields()->count())->toBe(0);
    $calls = count($state->calls);
    $quote = $this->actingAs($user)->postJson('/console/products/'.$product->id.'/quote', $payload)->assertOk()
        ->assertJsonPath('price', $state->price)->assertJsonPath('setup_fee', '0.00')->assertJsonPath('estimate', true);
    expect(count($state->calls))->toBe($calls);
    currentClassicAcceptanceNoFinancialWrites($user);
    $checkout = $payload + ['quote_token' => $quote->json('quote_token')];
    $response = $this->postJson('/checkout', $checkout)->assertCreated()->assertJsonPath('status', 'Paid')->assertJsonPath('total_amount', $state->price);
    $invoice = Invoice::sole();
    expect($invoice->id)->toBe($response->json('invoice_id'))->and($invoice->status)->toBe(InvoiceStatus::PAID)
        ->and($invoice->upstream_expected_cost)->toBe($state->cost)->and($invoice->line_items['purchase']['options']['configoption'])->toEqual($state->selected)
        ->and($user->fresh()->credit)->toBe($state->balance)->and($user->fresh()->frozen_credit)->toBe('0.00');
    $calls = count($state->calls);
    $this->postJson('/checkout', $checkout)->assertCreated()->assertJsonPath('invoice_id', $invoice->id);
    expect(count($state->calls))->toBe($calls)->and(Invoice::count())->toBe(1)->and(FulfillmentTask::count())->toBe(1);
    $task = FulfillmentTask::sole();
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    $instance = $task->instance->fresh();
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS)->and($instance->status)->toBe(InstanceStatus::ACTIVE)
        ->and($instance->upstream_instance_id)->toBe('999')->and($instance->active_config['upstream_configoption'])->toEqual($state->selected)
        ->and($user->fresh()->credit)->toBe($state->balance);
    if ($pid === 14) {
        $os = collect($instance->active_config['options'])->firstWhere('upstream_group_id', 50);
        expect($os['upstream_sub_id'])->toBe(502);
    }
    expect(FinancialTransaction::where('invoice_id', $invoice->id)->where('type', TransactionType::CREDIT_FREEZE)->count())->toBe(1)
        ->and(FinancialTransaction::where('reference_key', 'settle:'.$invoice->id)->sole()->amount)->toBe($state->price);
    foreach (['/cart/get_total', '/cart/add_to_shop', '/cart/settle', '/apply_credit'] as $path) {
        expect(collect($state->calls)->filter(fn ($actual) => $actual === $path)->count())->toBe(1);
    }
})->with(['CDN391 ordinary cycle' => [391], 'API731 amount' => [731], 'cloud14 synthetic available branch' => [14]]);

it('keeps the CDN391 trial unavailable despite a static allowance of 888 and no identity conditions', function (): void {
    [$user, $product, $state, $payload] = currentClassicAcceptanceFixture(391);
    expect(collect(app(DescribeStoreProductAction::class)->execute($product)['cycles'])->pluck('value')->all())->toBe(['monthly']);
    $calls = count($state->calls);
    $payload['billing_cycle'] = 'ontrial';
    $this->actingAs($user)->postJson('/console/products/'.$product->id.'/quote', $payload)->assertUnprocessable()->assertJsonValidationErrors('billing_cycle');
    $this->postJson('/checkout', $payload)->assertUnprocessable()->assertJsonValidationErrors('billing_cycle');
    expect(count($state->calls))->toBe($calls);
    currentClassicAcceptanceNoFinancialWrites($user);
});

it('shows cloud14 sold out and rejects checkout before collecting money when the supplier snapshot has zero stock', function (): void {
    [$user, $product, $state, $payload] = currentClassicAcceptanceFixture(14);
    expect(app(DescribeStoreProductAction::class)->execute($product)['stock'])->toBe(0);
    $this->actingAs($user)->get('/console/products/'.$product->id.'/configure')->assertOk()->assertSee('最近同步显示售罄')->assertDontSee('id="classic-configuration"', false);
    $this->postJson('/checkout', $payload)->assertUnprocessable()->assertJsonValidationErrors('product_id');
    foreach (['/cart/get_total', '/cart/add_to_shop', '/cart/settle', '/apply_credit'] as $path) {
        expect($state->calls)->not->toContain($path);
    }
    currentClassicAcceptanceNoFinancialWrites($user);
});

it('rejects client supplied unknown custom fields for the current three catalogs before supplier or financial work', function (int $pid): void {
    [$user, , $state, $payload] = currentClassicAcceptanceFixture($pid, availableCloud: $pid === 14);
    $payload['options']['customfield'] = [999 => 'client-supplied-field'];
    $calls = count($state->calls);
    $this->actingAs($user)->postJson('/checkout', $payload)->assertUnprocessable()->assertJsonValidationErrors('options');
    expect(count($state->calls))->toBe($calls);
    currentClassicAcceptanceNoFinancialWrites($user);
})->with([391, 731, 14]);
