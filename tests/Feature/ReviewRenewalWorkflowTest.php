<?php

declare(strict_types=1);

use App\Actions\CreateComboInvoiceAction;
use App\Actions\HandlePaymentCallbackAction;
use App\Actions\ProcessInstanceLifecycleAction;
use App\Actions\RequestInstanceOperationAction;
use App\Data\VerifiedPaymentResult;
use App\Enums\BillingCycle;
use App\Enums\DriverType;
use App\Enums\InvoiceStatus;
use App\Models\FinancialTransaction;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\UpstreamApi;
use App\Models\User;
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
});

function reviewRenewalFixture(string $credit = '100.00', bool $automatic = true): array
{
    $user = User::factory()->create(['credit' => $credit]);
    $account = UpstreamApi::factory()->create();
    $product = Product::factory()->create(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id, 'markup_percent' => '0.00']);
    $instance = Instance::factory()->active()->create(['user_id' => $user->id, 'product_id' => $product->id,
        'driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id, 'upstream_instance_id' => '99',
        'billing_cycle' => BillingCycle::MONTHLY, 'next_due_at' => now()->addDay(), 'recurring_amount' => '10.00', 'active_config' => []]);
    $level = DB::transactionLevel();
    Http::fake(function ($request) use ($instance, $level) {
        expect(DB::transactionLevel())->toBe($level);
        $path = parse_url($request->url(), PHP_URL_PATH);

        return Http::response(match ($path) {
            '/zjmf_api_login' => ['status' => 200, 'jwt' => 'isolated-review-renewal'],
            '/host/header' => ['status' => 200, 'data' => ['host_data' => ['domainstatus' => 'Active', 'nextduedate' => $instance->next_due_at->toIso8601String()]]],
            '/host/renewpage' => ['status' => 200, 'data' => ['host' => ['amount' => '10.00', 'billingcycle' => 'monthly'], 'currency' => ['code' => 'CNY']]],
            default => throw new LogicException('Unexpected isolated renewal request: '.$path),
        });
    });
    if ($automatic) {
        expect(app(ProcessInstanceLifecycleAction::class)->process($instance))->toBeTrue();
        $invoice = Invoice::sole();
    } else {
        $invoice = app(CreateComboInvoiceAction::class)->renewal($user, $instance, $credit === '3.00');
    }

    return [$user, $instance->fresh(), $invoice, $account];
}

it('honours the owner allocation once on an automatic renewal and replays without further spending', function (string $balance, bool $wallet, string $credit, string $remaining, string $status, int $tasks, int $ledger): void {
    [$user, $instance, $invoice] = reviewRenewalFixture($balance);
    expect($invoice->line_items['automatic_renewal']['selection_pending'])->toBeTrue();
    $request = (string) Str::ulid();
    $confirmed = app(CreateComboInvoiceAction::class)->renewal($user, $instance, $wallet, 'epay_alipay', $request);
    $again = app(CreateComboInvoiceAction::class)->renewal($user, $instance, $wallet, 'epay_alipay', $request);
    expect($confirmed->id)->toBe($invoice->id)->and($again->id)->toBe($invoice->id)
        ->and($confirmed->status->value)->toBe($status)->and($confirmed->credit_applied)->toBe($credit)
        ->and($confirmed->gateway_amount)->toBe($remaining)->and($confirmed->gateway)->toBe($remaining === '0.00' ? null : 'epay_alipay')
        ->and($confirmed->total_amount)->toBe('10.00')->and($confirmed->line_items['automatic_renewal']['selection_pending'])->toBeFalse();
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseCount('financial_transactions', $ledger);
    $this->assertDatabaseCount('fulfillment_tasks', $tasks);
    $this->assertDatabaseCount('payment_attempts', 0);
    $this->assertDatabaseCount('instances', 1);
    $this->assertDatabaseHas('audit_logs', ['action' => 'invoice.automatic_renewal_confirm', 'actor_id' => $user->id]);
})->with([
    'wallet' => ['100.00', true, '10.00', '0.00', 'Paid', 1, 2],
    'combo' => ['3.00', true, '3.00', '7.00', 'Unpaid', 0, 1],
    'alipay' => ['100.00', false, '0.00', '10.00', 'Unpaid', 0, 0],
]);

it('rejects a different allocation after the automatic renewal is confirmed', function (bool $sameRequest): void {
    [$user, $instance] = reviewRenewalFixture('3.00');
    $request = (string) Str::ulid();
    app(CreateComboInvoiceAction::class)->renewal($user, $instance, true, 'epay_alipay', $request);
    expect(fn () => app(CreateComboInvoiceAction::class)->renewal($user, $instance, false, 'epay_wechat', $sameRequest ? $request : (string) Str::ulid()))
        ->toThrow(ValidationException::class);
    expect($user->fresh()->credit)->toBe('0.00')->and($user->fresh()->frozen_credit)->toBe('3.00');
    $this->assertDatabaseCount('financial_transactions', 1);
})->with([true, false]);

it('refuses first allocation when existing evidence or service configuration changed', function (string $change): void {
    [$user, $instance, $invoice, $account] = reviewRenewalFixture('3.00');
    match ($change) {
        'created', 'failed', 'expired' => PaymentAttempt::create(['invoice_id' => $invoice->id, 'gateway' => 'epay_wechat', 'out_trade_no' => 'PAY'.Str::ulid(), 'amount' => '10.00', 'status' => $change]),
        'expired invoice' => $invoice->update(['expires_at' => now()->subSecond()]),
        'cancelled' => $invoice->update(['status' => InvoiceStatus::CANCELLED]),
        'secret' => $account->update(['encrypted_secret' => 'changed-after-quote']),
        'disabled account' => $account->update(['is_active' => false]),
        'currency' => $instance->update(['currency' => 'USD']),
    };
    expect(fn () => app(CreateComboInvoiceAction::class)->renewal($user, $instance, true, 'epay_alipay', (string) Str::ulid()))
        ->toThrow($change === 'disabled account' ? DomainException::class : ValidationException::class);
    expect($user->fresh()->credit)->toBe('3.00')->and($user->fresh()->frozen_credit)->toBe('0.00');
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('fulfillment_tasks', 0);
})->with(['created', 'failed', 'expired', 'expired invoice', 'cancelled', 'secret', 'disabled account', 'currency']);

it('does not silently ignore a new coupon or infer historical invoices as automatic', function (bool $automatic): void {
    [$user, $instance, $invoice] = reviewRenewalFixture(automatic: $automatic);
    $before = $invoice->fresh()->getRawOriginal();
    expect(fn () => app(CreateComboInvoiceAction::class)->renewal($user, $instance, true, 'epay_alipay', (string) Str::ulid(), $automatic ? 'NEWCOUPON' : null))
        ->toThrow(ValidationException::class);
    expect($invoice->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('financial_transactions', 0);
})->with([true, false]);

it('renders a working owner confirmation form and posts to the existing operation route', function (): void {
    [$user, $instance, $invoice] = reviewRenewalFixture();
    $this->actingAs($user)->get('/console/invoices/'.$invoice->id)->assertOk()
        ->assertSee('/console/instances/'.$instance->id.'/operations', false)->assertSee('确认续费付款方式');
    $this->post('/console/instances/'.$instance->id.'/operations', ['action' => 'renew', 'request_id' => (string) Str::ulid(),
        'use_credit' => 1, 'gateway' => 'epay_alipay', 'reason' => 'Customer confirms the renewal allocation', 'confirm' => 1])
        ->assertRedirect('/console/invoices/'.$invoice->id)->assertSessionHasNoErrors();
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::PAID)->and($user->fresh()->credit)->toBe('90.00');
});

it('cancels unpaid renewals once with termination and credits late payments without renewal', function (): void {
    [$user, $instance, $invoice] = reviewRenewalFixture('3.00', false);
    $attempt = PaymentAttempt::create(['invoice_id' => $invoice->id, 'gateway' => 'epay_wechat', 'out_trade_no' => 'PAY'.Str::ulid(), 'amount' => '7.00', 'status' => 'created']);
    $request = (string) Str::ulid();
    $task = app(RequestInstanceOperationAction::class)->execute($instance, 'terminate', 'Customer ends this service', $request, $user, 'client_action');
    $again = app(RequestInstanceOperationAction::class)->execute($instance, 'terminate', 'Customer ends this service', $request, $user, 'client_action');
    expect($task->id)->toBe($again->id)->and($task->action)->toBe('terminate')->and($attempt->fresh()->status)->toBe('expired')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::CANCELLED)->and($user->fresh()->credit)->toBe('3.00')->and($user->fresh()->frozen_credit)->toBe('0.00');
    $callback = new VerifiedPaymentResult('epay_wechat', 'review-late-payment', $attempt->out_trade_no, '7.00');
    app(HandlePaymentCallbackAction::class)->execute($callback);
    app(HandlePaymentCallbackAction::class)->execute($callback);
    expect($user->fresh()->credit)->toBe('10.00')->and($invoice->fresh()->status)->toBe(InvoiceStatus::CANCELLED);
    $this->assertDatabaseCount('fulfillment_tasks', 1);
    $this->assertDatabaseCount('payment_callbacks', 1);
    $this->assertDatabaseCount('financial_transactions', 3);
    $this->assertDatabaseHas('financial_transactions', ['reference_key' => 'unfreeze:'.$invoice->id, 'amount' => '3.00']);
});

it('rolls back termination cancellation when money evidence or other operations are unresolved', function (string $conflict): void {
    [$user, $instance, $invoice] = reviewRenewalFixture('3.00', false);
    match ($conflict) {
        'freeze' => FinancialTransaction::where('reference_key', 'freeze:'.$invoice->id)->delete(),
        'task' => FulfillmentTask::factory()->create(['instance_id' => $instance->id, 'invoice_id' => $invoice->id]),
        'upgrade' => Invoice::factory()->create(['user_id' => $user->id, 'instance_id' => $instance->id, 'type' => 'upgrade']),
    };
    expect(fn () => app(RequestInstanceOperationAction::class)->execute($instance, 'terminate', 'Customer ends service', (string) Str::ulid(), $user, 'client_action'))
        ->toThrow(DomainException::class);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::UNPAID)->and($user->fresh()->credit)->toBe('0.00')
        ->and($user->fresh()->frozen_credit)->toBe('3.00')->and($instance->fresh()->active_config)->not->toHaveKey('cancellation');
    expect(FulfillmentTask::where('action', 'terminate')->count())->toBe(0);
})->with(['freeze', 'task', 'upgrade']);
