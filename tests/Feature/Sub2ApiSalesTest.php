<?php

declare(strict_types=1);

use App\Actions\CreateComboInvoiceAction;
use App\Actions\ExecuteFulfillmentTaskAction;
use App\Actions\HandlePaymentCallbackAction;
use App\Actions\ImportRedemptionCodesAction;
use App\Contracts\RenewableResource;
use App\Data\VerifiedPaymentResult;
use App\Drivers\SelfHosted\Sub2ApiDriver;
use App\Enums\BillingCycle;
use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Models\FulfillmentTask;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\RedemptionCode;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Services\DriverManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);
beforeEach(fn () => $this->installApplicationForTest());

it('sells a Sub2API local-pool card through the existing wallet and combo settlement paths exactly once', function (bool $combo): void {
    Queue::fake();
    Http::preventStrayRequests();
    $this->artisan('cards:create-product', ['name' => 'Sub2API recharge', 'price' => '10.00', '--driver' => 'sub2api', '--reason' => 'Launch API credit cards'])->assertSuccessful();
    $product = Product::sole();
    expect($product->driver_type)->toBe(DriverType::SUB2API)->and($product->upstream_api_id)->toBeNull();
    $driver = app(DriverManager::class)->fulfillment($product->driver_type);
    expect($driver)->toBeInstanceOf(Sub2ApiDriver::class)->not->toBeInstanceOf(RenewableResource::class);
    $import = app(ImportRedemptionCodesAction::class);
    expect($import->execute($product, ['SUB2-PRIVATE-CARD', 'SUB2-PRIVATE-CARD'], 'Import existing upstream-issued cards'))->toBe(1)
        ->and($import->execute($product, ['SUB2-PRIVATE-CARD'], 'Repeat import'))->toBe(0);
    $buyer = User::factory()->create(['credit' => $combo ? '3.00' : '20.00']);
    $this->actingAs($buyer)->get($product->publicPath())->assertOk()->assertSee('创建订单')->assertDontSee('SUB2-PRIVATE-CARD');
    $this->get('/console')->assertOk()->assertSee('Sub2API recharge');
    $checkout = $this->postJson('/checkout', ['product_id' => $product->id, 'billing_cycle' => 'onetime', 'use_credit' => true, 'gateway' => 'epay_wechat'])->assertCreated();
    $invoice = Invoice::findOrFail($checkout->json('invoice_id'));
    expect($invoice->credit_applied)->toBe($combo ? '3.00' : '10.00')->and($invoice->gateway_amount)->toBe($combo ? '7.00' : '0.00');
    if ($combo) {
        app(HandlePaymentCallbackAction::class)->execute(new VerifiedPaymentResult('epay_wechat', 'SUB2-TRADE', $invoice->invoice_no, '7.00'));
        app(HandlePaymentCallbackAction::class)->execute(new VerifiedPaymentResult('epay_wechat', 'SUB2-TRADE', $invoice->invoice_no, '7.00'));
    }
    $task = FulfillmentTask::sole();
    $this->get('/console/instances/'.$task->instance_id)->assertOk()->assertDontSee('SUB2-PRIVATE-CARD');
    $execute = app(ExecuteFulfillmentTaskAction::class);
    $execute->execute($task->id);
    $execute->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS)
        ->and($task->instance->fresh()->status)->toBe(InstanceStatus::ACTIVE)
        ->and($task->instance->fresh()->encrypted_credentials['card_key'])->toBe('SUB2-PRIVATE-CARD')
        ->and(RedemptionCode::sole()->status)->toBe('sold');
    $this->get('/console/instances/'.$task->instance_id)->assertOk()->assertSee('SUB2-PRIVATE-CARD')->assertHeader('Cache-Control', 'no-store, private');
    $this->actingAs(User::factory()->create())->get('/console/instances/'.$task->instance_id)->assertForbidden();
    foreach (['redemption_codes' => 'code', 'instances' => 'encrypted_credentials', 'audit_logs' => 'after', 'fulfillment_tasks' => 'payload'] as $table => $column) {
        expect(DB::table($table)->pluck($column)->implode(' '))->not->toContain('SUB2-PRIVATE-CARD');
    }
    expect(DB::table('financial_transactions')->where('reference_key', 'settle:'.$invoice->id)->count())->toBe(1);
    Http::assertNothingSent();
})->with([false, true]);

it('refuses Sub2API remote mode and unsupported billing without changing the wallet', function (): void {
    $account = UpstreamApi::factory()->create(['driver' => DriverType::SUB2API]);
    expect(fn () => app(DriverManager::class)->fulfillment(DriverType::SUB2API, $account->id))->toThrow(DomainException::class);
    $this->artisan('cards:create-product', ['name' => 'Sub2API', 'price' => '10.00', '--driver' => 'sub2api', '--reason' => 'Setup'])->assertSuccessful();
    $user = User::factory()->create(['credit' => '100.00']);
    expect(fn () => app(CreateComboInvoiceAction::class)->execute($user, Product::sole(), BillingCycle::MONTHLY, [], true))->toThrow(ValidationException::class);
    expect($user->fresh()->credit)->toBe('100.00');
    $this->assertDatabaseCount('invoices', 0);
});
