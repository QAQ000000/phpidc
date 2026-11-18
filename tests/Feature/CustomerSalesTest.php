<?php

declare(strict_types=1);

use App\Actions\ExecuteFulfillmentTaskAction;
use App\Enums\InvoiceStatus;
use App\Jobs\ExecuteFulfillmentTaskJob;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\RedemptionCode;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);
beforeEach(fn () => $this->installApplicationForTest());

it('sells a local card through wallet checkout, a queued job and the owner console', function (): void {
    Queue::fake();
    Http::fake();
    $user = User::factory()->create(['credit' => '30.00']);
    $product = Product::factory()->create(['local_prices' => ['onetime' => ['price' => '10.00', 'enabled' => true]]]);
    $code = RedemptionCode::factory()->create(['product_id' => $product->id, 'code' => '<CARD-SECRET>']);
    $response = $this->actingAs($user)->postJson('/checkout', ['request_id' => (string) Str::ulid(),
        'product_id' => $product->id, 'billing_cycle' => 'onetime', 'use_credit' => true,
        'total_amount' => '0.01', 'credit_applied' => '0.01',
    ])->assertCreated()->assertJsonPath('total_amount', '10.00')->assertJsonPath('status', 'Paid');
    $invoice = Invoice::findOrFail($response->json('invoice_id'));
    $task = FulfillmentTask::where('invoice_id', $invoice->id)->sole();
    Queue::assertPushed(ExecuteFulfillmentTaskJob::class, fn ($job) => $job->taskId === $task->id);
    (new ExecuteFulfillmentTaskJob($task->id))->handle(app(ExecuteFulfillmentTaskAction::class));
    (new ExecuteFulfillmentTaskJob($task->id))->handle(app(ExecuteFulfillmentTaskAction::class));
    expect($code->fresh()->status)->toBe('sold')->and($task->fresh()->status->value)->toBe('success')
        ->and($user->fresh()->credit)->toBe('20.00');
    $this->get('/console')->assertOk()->assertSee($invoice->invoice_no)->assertDontSee('<CARD-SECRET>');
    $this->get('/console/invoices/'.$invoice->id)->assertOk()->assertSee('Paid');
    $this->get('/console/instances/'.$invoice->instance_id)->assertOk()->assertSee('&lt;CARD-SECRET&gt;', false);
    $this->actingAs(User::factory()->create())->get('/console/instances/'.$invoice->instance_id)->assertForbidden();
    Http::assertNothingSent();
});

it('signs and initiates an epay attempt outside locks then delivers only after a verified callback', function (): void {
    Queue::fake();
    DB::table('system_settings')->insert([
        ['group' => 'payment', 'key' => 'epay_api_url', 'value' => 'https://epay.test', 'is_encrypted' => false],
        ['group' => 'payment', 'key' => 'epay_pid', 'value' => 'merchant-1', 'is_encrypted' => false],
        ['group' => 'payment', 'key' => 'epay_key', 'value' => Crypt::encryptString('test-secret'), 'is_encrypted' => true],
    ]);
    $user = User::factory()->create(['credit' => '3.00']);
    $product = Product::factory()->create(['local_prices' => ['onetime' => ['price' => '10.00', 'enabled' => true]]]);
    RedemptionCode::factory()->create(['product_id' => $product->id, 'code' => 'GATEWAY-CARD']);
    $response = $this->actingAs($user)->postJson('/checkout', ['request_id' => (string) Str::ulid(), 'product_id' => $product->id, 'use_credit' => true, 'gateway' => 'epay_wechat'])
        ->assertCreated()->assertJsonPath('gateway_amount', '7.00')->assertJsonPath('status', 'Unpaid');
    $invoice = Invoice::findOrFail($response->json('invoice_id'));
    $level = DB::transactionLevel();
    Http::fake(function ($request) use ($level): PromiseInterface {
        expect(DB::transactionLevel())->toBe($level)
            ->and(PaymentAttempt::where('out_trade_no', $request['out_trade_no'])->count())->toBe(1)
            ->and($request['money'])->toBe('7.00')
            ->and($request->hasHeader('Content-Type', 'application/x-www-form-urlencoded'))->toBeTrue();

        return Http::response(['code' => 1, 'payurl' => 'https://epay.test/pay/123']);
    });
    $pay = $this->postJson('/console/invoices/'.$invoice->id.'/pay')->assertOk()->assertJsonPath('payment_url', 'https://epay.test/pay/123');
    $order = $pay->json('out_trade_no');
    $this->assertDatabaseCount('instances', 0);
    $payload = ['pid' => 'merchant-1', 'type' => 'wxpay', 'out_trade_no' => $order,
        'trade_no' => 'EPAY-TRADE-1', 'money' => '7.00', 'trade_status' => 'TRADE_SUCCESS'];
    ksort($payload, SORT_STRING);
    $raw = [];
    foreach ($payload as $key => $value) {
        $raw[] = $key.'='.$value;
    }
    $payload['sign'] = strtoupper(md5(implode('&', $raw).'test-secret'));
    $this->post('/api/payments/epay_wechat/notify', $payload)->assertOk()->assertSee('success');
    $this->post('/api/payments/epay_wechat/notify', $payload)->assertOk()->assertSee('success');
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and($user->fresh()->frozen_credit)->toBe('0.00')->and($user->fresh()->credit)->toBe('0.00');
    $this->assertDatabaseCount('payment_callbacks', 1);
    $this->assertDatabaseCount('fulfillment_tasks', 1);
    $task = FulfillmentTask::sole();
    (new ExecuteFulfillmentTaskJob($task->id))->handle(app(ExecuteFulfillmentTaskAction::class));
    $this->get('/console/instances/'.$invoice->fresh()->instance_id)->assertOk()->assertSee('GATEWAY-CARD');
    $payload['trade_no'] = 'TAMPERED';
    $this->postJson('/api/payments/epay_wechat/notify', $payload)->assertUnprocessable();
    $this->assertDatabaseCount('payment_callbacks', 1);
});

it('registers and logs in customers without allowing balance injection', function (): void {
    $this->post('/register', ['name' => 'Buyer', 'email' => 'buyer@example.test', 'password' => 'StrongPassword123!',
        'password_confirmation' => 'StrongPassword123!', 'credit' => '9999.00'])->assertRedirect('/console');
    $user = User::where('email', 'buyer@example.test')->sole();
    expect($user->credit)->toBe('0.00');
    $this->post('/logout')->assertRedirect('/login');
    $this->get('/login')->assertOk();
    $this->post('/login', ['email' => 'buyer@example.test', 'password' => 'StrongPassword123!'])->assertRedirect('/console');
    $this->get('/console')->assertOk();
});

it('prevents physical user deletion when financial or fulfillment records exist and enforces tenant isolation', function (): void {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $instance = Instance::factory()->create(['user_id' => $owner->id, 'driver_type' => 'redemption']);
    $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'total_amount' => '10.00']);

    expect(fn () => $owner->forceDelete())->toThrow(QueryException::class);

    $this->actingAs($intruder)->get("/console/instances/{$instance->id}")->assertForbidden();
    $this->actingAs($intruder)->get("/console/invoices/{$invoice->id}")->assertForbidden();
});
