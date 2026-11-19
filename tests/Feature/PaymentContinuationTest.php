<?php

declare(strict_types=1);

use App\Actions\ConfigureEpayAction;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Queue::fake();
    Http::preventStrayRequests();
});

function paymentContinuationFixture(array $attributes = []): array
{
    $owner = User::factory()->create(['credit' => '23.00']);
    $invoice = Invoice::factory()->create($attributes + ['user_id' => $owner->id, 'gateway' => 'epay_wechat']);

    return [$owner, $invoice->refresh()];
}

function paymentContinuationNoWrites(User $owner, Invoice $invoice, array $original): void
{
    expect($owner->fresh()->credit)->toBe('23.00')->and($owner->fresh()->frozen_credit)->toBe('0.00')
        ->and($invoice->fresh()->getRawOriginal())->toBe($original);
    foreach (['payment_attempts', 'payment_callbacks', 'financial_transactions', 'instances', 'fulfillment_tasks'] as $table) {
        test()->assertDatabaseCount($table, 0);
    }
    Http::assertNothingSent();
    Queue::assertNothingPushed();
}

it('returns an unconfigured HTML payment to its invoice after a browser probe pollutes the previous URL', function (): void {
    [$owner, $invoice] = paymentContinuationFixture();
    $original = $invoice->getRawOriginal();
    $invoicePath = '/console/invoices/'.$invoice->id;
    $probe = '/.well-known/appspecific/com.chrome.devtools.json';
    $message = '支付渠道尚未配置，请联系管理员配置后再继续支付。';
    $this->actingAs($owner)->get($invoicePath)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
    $this->get($probe)->assertNotFound();
    expect(session()->previousUrl())->toBe(url($probe));

    // Browser requests from the no-referrer invoice page supply no Referer header.
    $this->post($invoicePath.'/pay')->assertRedirect($invoicePath)->assertSessionHasErrors(['gateway' => $message]);
    $this->get($invoicePath)->assertOk()->assertSee($invoice->invoice_no)->assertSee($message)
        ->assertSee('role="alert"', false)->assertSee('继续支付');
    paymentContinuationNoWrites($owner, $invoice, $original);
});

it('keeps JSON payment validation at 422 without an HTML redirect or payment effects', function (): void {
    [$owner, $invoice] = paymentContinuationFixture();
    $original = $invoice->getRawOriginal();
    $this->actingAs($owner)->get('/.well-known/appspecific/com.chrome.devtools.json')->assertNotFound();
    $response = $this->postJson('/console/invoices/'.$invoice->id.'/pay')->assertUnprocessable()
        ->assertJsonValidationErrors('gateway')
        ->assertJsonPath('errors.gateway.0', '支付渠道尚未配置，请联系管理员配置后再继续支付。');
    expect($response->headers->has('Location'))->toBeFalse();
    paymentContinuationNoWrites($owner, $invoice, $original);
});

it('rejects another owners payment without disclosing or redirecting to the invoice', function (bool $json): void {
    [$owner, $invoice] = paymentContinuationFixture();
    $original = $invoice->getRawOriginal();
    $intruder = User::factory()->create(['credit' => '23.00']);
    $this->actingAs($intruder);
    $path = '/console/invoices/'.$invoice->id.'/pay';
    $response = $json ? $this->postJson($path) : $this->post($path);
    $response->assertForbidden()->assertDontSee($invoice->invoice_no)
        ->assertDontSee('支付渠道尚未配置')->assertSessionHasNoErrors();
    expect($response->headers->has('Location'))->toBeFalse()->and($intruder->fresh()->credit)->toBe('23.00');
    paymentContinuationNoWrites($owner, $invoice, $original);
})->with(['HTML' => [false], 'JSON' => [true]]);

it('returns a no longer payable HTML invoice to its own detail page with a visible validation message', function (string $state): void {
    app(ConfigureEpayAction::class)->execute('https://continuation-epay.test', 'merchant', 'isolated-key', 'Payment continuation test');
    $attributes = match ($state) {
        'paid' => ['status' => InvoiceStatus::PAID, 'paid_at' => now()],
        'expired' => ['status' => InvoiceStatus::EXPIRED, 'expires_at' => now()->subMinute()],
        'past deadline' => ['expires_at' => now()->subMinute()],
    };
    [$owner, $invoice] = paymentContinuationFixture($attributes);
    $original = $invoice->getRawOriginal();
    $invoicePath = '/console/invoices/'.$invoice->id;
    $message = '当前账单不可支付，请核对账单状态、支付有效期和待付金额。';
    $this->actingAs($owner)->get('/.well-known/appspecific/com.chrome.devtools.json')->assertNotFound();
    $this->post($invoicePath.'/pay')->assertRedirect($invoicePath)->assertSessionHasErrors(['invoice' => $message]);
    $this->get($invoicePath)->assertOk()->assertSee($invoice->invoice_no)->assertSee($message)->assertDontSee('继续支付');
    paymentContinuationNoWrites($owner, $invoice, $original);
})->with(['paid', 'expired', 'past deadline']);

it('renders a configured HTML payment link while preserving the unpaid invoice and wallet', function (): void {
    app(ConfigureEpayAction::class)->execute('https://continuation-epay.test', 'merchant', 'isolated-key', 'Payment continuation test');
    [$owner, $invoice] = paymentContinuationFixture();
    $original = $invoice->getRawOriginal();
    $level = DB::transactionLevel();
    Http::fake(function ($request) use ($invoice, $level) {
        expect($request->url())->toBe('https://continuation-epay.test/mapi.php')
            ->and($request['money'])->toBe('10.00')
            ->and($request['return_url'])->toBe(url('/console/invoices/'.$invoice->id))
            ->and(DB::transactionLevel())->toBe($level)
            ->and(PaymentAttempt::where('out_trade_no', $request['out_trade_no'])->count())->toBe(1);

        return Http::response(['code' => 1, 'payurl' => 'https://continuation-epay.test/pay/order-123']);
    });
    $this->actingAs($owner)->post('/console/invoices/'.$invoice->id.'/pay')->assertOk()
        ->assertSee('href="https://continuation-epay.test/pay/order-123"', false)->assertSee('前往支付')
        ->assertSee('href="/console/invoices/'.$invoice->id.'"', false);
    Http::assertSentCount(1);
    $this->assertDatabaseHas('payment_attempts', ['invoice_id' => $invoice->id, 'amount' => '10.00', 'status' => 'created']);
    $this->assertDatabaseCount('payment_attempts', 1);
    expect($owner->fresh()->credit)->toBe('23.00')->and($owner->fresh()->frozen_credit)->toBe('0.00')
        ->and($invoice->fresh()->getRawOriginal())->toBe($original);
    foreach (['payment_callbacks', 'financial_transactions', 'instances', 'fulfillment_tasks'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    Queue::assertNothingPushed();
});

it('preserves missing invoice and GET payment route failures without initiating a payment', function (): void {
    [$owner, $invoice] = paymentContinuationFixture();
    $original = $invoice->getRawOriginal();
    $this->actingAs($owner)->get('/console/invoices/'.$invoice->id.'/pay')->assertNotFound();
    $this->post('/console/invoices/'.($invoice->id + 1000).'/pay')->assertNotFound();
    paymentContinuationNoWrites($owner, $invoice, $original);
});
