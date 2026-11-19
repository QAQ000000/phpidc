<?php

declare(strict_types=1);

use App\Actions\CreatePurchaseFulfillmentAction;
use App\Actions\DescribeInstanceOrderFieldsAction;
use App\Enums\BillingCycle;
use App\Enums\DriverType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Instance;
use App\Models\InstanceUpstreamBinding;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductCustomField;
use App\Models\UpstreamApi;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Http::preventStrayRequests();
    Http::fake();
    Queue::fake();
});

/** @return array{user: User, product: Product, invoice: Invoice, instance: Instance} */
function instanceOrderFieldsFixture(array $values = [101 => 'order.example.test', 102 => 'PRIVATE-FIELD-PASSWORD']): array
{
    $user = User::factory()->create();
    $account = UpstreamApi::factory()->create();
    $product = Product::factory()->create(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id,
        'upstream_pid' => '17', 'provisioning_rules' => ['classic_catalog' => ['version' => 1, 'dependencies' => [], 'product_publisher_uid' => 0]]]);
    foreach ([101 => ['name' => '站点域名', 'field_type' => 'text'], 102 => ['name' => '私密字段', 'field_type' => 'password']] as $id => $definition) {
        ProductCustomField::create(['product_id' => $product->id, 'upstream_field_id' => $id, 'sync_source' => 'upstream',
            'show_on_order' => true, 'show_on_detail' => true, 'is_hidden' => false, ...$definition]);
    }
    $invoice = Invoice::factory()->paid()->create(['user_id' => $user->id, 'product_id' => $product->id,
        'line_items' => ['purchase' => ['name' => $product->name, 'driver_type' => DriverType::ZJMF_API->value,
            'upstream_api_id' => $account->id, 'upstream_pid' => '17', 'billing_cycle' => BillingCycle::ONETIME->value,
            'encrypted_params' => Crypt::encryptString(json_encode(['password' => 'PRIVATE-HOST-PASSWORD', 'expected_cost' => '7.59',
                'upstream_pid' => '17', 'options' => ['customfield' => $values]], JSON_THROW_ON_ERROR))]]]);
    // Use the actual paid-order settlement handoff without executing its queued work.
    DB::transaction(fn () => app(CreatePurchaseFulfillmentAction::class)->execute($invoice));

    return ['user' => $user, 'product' => $product, 'invoice' => $invoice->fresh(), 'instance' => $invoice->fresh()->instance];
}

it('shows owner order fields from the encrypted paid purchase while keeping passwords outside the view projection', function (): void {
    ['user' => $user, 'invoice' => $invoice, 'instance' => $instance] = instanceOrderFieldsFixture();
    $before = $instance->getRawOriginal();
    $invoiceBefore = $invoice->getRawOriginal();
    $tasks = DB::table('fulfillment_tasks')->count();
    $events = DB::table('notification_events')->count();

    $response = $this->actingAs($user)->get('/console/instances/'.$instance->id)->assertOk()->assertSee('下单资料')
        ->assertSee('下单时填写的资料')->assertSee('order.example.test')->assertSee('私密字段')->assertSee('已填写（内容已隐藏）')
        ->assertDontSee('PRIVATE-FIELD-PASSWORD')->assertDontSee('PRIVATE-HOST-PASSWORD')->assertDontSee('7.59')
        ->assertHeader('Cache-Control', 'no-store, private');
    expect($response->viewData('orderFields'))->toBe(['fields' => [
        ['label' => '站点域名', 'value' => 'order.example.test'], ['label' => '私密字段', 'value' => '已填写（内容已隐藏）'],
    ], 'unavailable' => false]);
    expect($instance->fresh()->getRawOriginal())->toBe($before)->and($instance->fresh()->custom_field_values)->toBeNull()
        ->and($invoice->fresh()->getRawOriginal())->toBe($invoiceBefore)
        ->and(DB::table('fulfillment_tasks')->count())->toBe($tasks)->and(DB::table('notification_events')->count())->toBe($events);
    expect(DB::table('invoices')->where('id', $invoice->id)->value('line_items'))->not->toContain('PRIVATE-FIELD-PASSWORD');
    Http::assertNothingSent();
});

it('projects only currently visible upstream detail fields and escapes their labels and values', function (): void {
    ['user' => $user, 'product' => $product, 'instance' => $instance] = instanceOrderFieldsFixture([
        101 => '<script>alert("field")</script>', 102 => 'PRIVATE-FIELD-PASSWORD', 103 => 'DETAIL-OFF-VALUE',
        104 => 'HIDDEN-FIELD-VALUE', 105 => 'LOCAL-FIELD-VALUE', 106 => 'DELETED-FIELD-VALUE', 999 => 'UNKNOWN-FIELD-VALUE',
    ]);
    $product->customFields()->where('upstream_field_id', 101)->update(['name' => '<b>域名</b>']);
    foreach ([103 => ['show_on_detail' => false], 104 => ['is_hidden' => true], 105 => ['sync_source' => 'local'], 106 => []] as $id => $overrides) {
        $field = ProductCustomField::create(['product_id' => $product->id, 'upstream_field_id' => $id, 'name' => 'EXCLUDED-'.$id,
            'field_type' => 'text', 'show_on_order' => true, 'show_on_detail' => true, 'is_hidden' => false, 'sync_source' => 'upstream', ...$overrides]);
        if ($id === 106) {
            $field->delete();
        }
    }
    $this->actingAs($user)->get('/console/instances/'.$instance->id)->assertOk()
        ->assertSee('&lt;b&gt;域名&lt;/b&gt;', false)->assertSee('&lt;script&gt;alert(&quot;field&quot;)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert("field")</script>', false)->assertDontSee('<b>域名</b>', false)
        ->assertDontSee('DETAIL-OFF-VALUE')->assertDontSee('HIDDEN-FIELD-VALUE')->assertDontSee('LOCAL-FIELD-VALUE')
        ->assertDontSee('DELETED-FIELD-VALUE')->assertDontSee('UNKNOWN-FIELD-VALUE')->assertDontSee('PRIVATE-FIELD-PASSWORD');
    Http::assertNothingSent();
});

it('does not display fields from renewal invoices another product or another owner', function (): void {
    ['user' => $user, 'product' => $product, 'invoice' => $invoice, 'instance' => $instance] = instanceOrderFieldsFixture();
    $lineItems = $invoice->line_items;
    $lineItems['purchase']['encrypted_params'] = Crypt::encryptString(json_encode(['options' => ['customfield' => [101 => 'FOREIGN-ORDER-VALUE']]], JSON_THROW_ON_ERROR));
    foreach ([['type' => InvoiceType::RENEW], ['product_id' => Product::factory()->create()->id], ['user_id' => User::factory()->create()->id]] as $overrides) {
        Invoice::factory()->paid()->create(['user_id' => $user->id, 'instance_id' => $instance->id, 'product_id' => $product->id,
            'line_items' => $lineItems, ...$overrides]);
    }
    $invoice->update(['status' => InvoiceStatus::CANCELLED]);
    $response = $this->actingAs($user)->get('/console/instances/'.$instance->id)->assertOk()
        ->assertDontSee('FOREIGN-ORDER-VALUE')->assertDontSee('order.example.test')->assertDontSee('下单资料');
    expect($response->viewData('orderFields'))->toBe(['fields' => [], 'unavailable' => false]);
    $this->actingAs(User::factory()->create())->get('/console/instances/'.$instance->id)->assertForbidden();
    Http::assertNothingSent();
});

it('does not attach original order fields to a changed product or resource mapping', function (string $change): void {
    ['user' => $user, 'product' => $product, 'invoice' => $invoice, 'instance' => $instance] = instanceOrderFieldsFixture();
    match ($change) {
        'product-account' => $product->update(['upstream_api_id' => UpstreamApi::factory()->create()->id]),
        'product-pid' => $product->update(['upstream_pid' => '18']),
        'instance-account' => $instance->update(['upstream_api_id' => UpstreamApi::factory()->create()->id]),
        'instance-pid' => $instance->update(['active_config' => ['upstream_pid' => '18']]),
        'instance-pid-shape' => $instance->update(['active_config' => ['upstream_pid' => []]]),
        'purchase-account' => $invoice->update(['line_items' => ['purchase' => array_replace($invoice->line_items['purchase'], ['upstream_api_id' => UpstreamApi::factory()->create()->id])]]),
        'purchase-pid-shape' => $invoice->update(['line_items' => ['purchase' => array_replace($invoice->line_items['purchase'], ['upstream_pid' => []])]]),
        'replacement-host' => InstanceUpstreamBinding::create(['instance_id' => $instance->id, 'driver_type' => $instance->driver_type,
            'upstream_api_id' => $instance->upstream_api_id, 'upstream_instance_id' => 'original-host', 'bound_at' => now()->subDay(),
            'unbound_at' => now(), 'unbind_reason' => 'hot_swap', 'upstream_disposition' => 'terminated', 'reason_detail' => 'Isolated replacement test']),
    };
    $response = $this->actingAs($user)->get('/console/instances/'.$instance->id)->assertOk()
        ->assertDontSee('order.example.test')->assertDontSee('PRIVATE-FIELD-PASSWORD')->assertDontSee('下单资料');
    expect($response->viewData('orderFields'))->toBe(['fields' => [], 'unavailable' => false]);
    Http::assertNothingSent();
})->with(['product-account', 'product-pid', 'instance-account', 'instance-pid', 'instance-pid-shape', 'purchase-account', 'purchase-pid-shape', 'replacement-host']);

it('keeps the service page usable when historical order field parameters are missing or unreadable', function (string $case): void {
    ['user' => $user, 'invoice' => $invoice, 'instance' => $instance] = instanceOrderFieldsFixture();
    $purchase = $invoice->line_items['purchase'];
    $purchase['encrypted_params'] = match ($case) {
        'missing' => null,
        'ciphertext' => 'invalid-private-ciphertext',
        'json' => Crypt::encryptString('{'),
        'scalar' => Crypt::encryptString('true'),
        'options' => Crypt::encryptString('{"options":"wrong"}'),
        'customfields' => Crypt::encryptString('{"options":{"customfield":"wrong"}}'),
        'field-value' => Crypt::encryptString('{"options":{"customfield":{"101":["PRIVATE-NESTED-VALUE"]}}}'),
    };
    $invoice->update(['line_items' => ['purchase' => $purchase]]);
    $response = $this->actingAs($user)->get('/console/instances/'.$instance->id)->assertOk()->assertSee('历史下单资料暂不可用。')
        ->assertSee('关联账单')->assertSee($invoice->invoice_no)->assertDontSee('PRIVATE-NESTED-VALUE')->assertDontSee('invalid-private-ciphertext');
    expect($response->viewData('orderFields'))->toBe(['fields' => [], 'unavailable' => true]);
    Http::assertNothingSent();
})->with(['missing', 'ciphertext', 'json', 'scalar', 'options', 'customfields', 'field-value']);

it('keeps absent original snapshots and empty optional field values distinct', function (): void {
    ['user' => $user, 'invoice' => $invoice, 'instance' => $instance] = instanceOrderFieldsFixture([101 => '', 102 => '']);
    $response = $this->actingAs($user)->get('/console/instances/'.$instance->id)->assertOk()->assertSee('未填写')
        ->assertDontSee('已填写（内容已隐藏）');
    expect($response->viewData('orderFields')['fields'])->toBe([
        ['label' => '站点域名', 'value' => '未填写'], ['label' => '私密字段', 'value' => '未填写'],
    ]);
    $invoice->update(['line_items' => null]);
    expect(app(DescribeInstanceOrderFieldsAction::class)->execute($user, $instance))->toBe(['fields' => [], 'unavailable' => false]);
    Http::assertNothingSent();
});
