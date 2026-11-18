<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Actions\ClaimCouponAction;
use App\Actions\CreateComboInvoiceAction;
use App\Actions\ExecuteFulfillmentTaskAction;
use App\Actions\ExpireUnpaidInvoicesAction;
use App\Actions\HandlePaymentCallbackAction;
use App\Actions\QuoteRenewalRecoveryAction;
use App\Actions\ReencryptSecretsAction;
use App\Data\VerifiedPaymentResult;
use App\Enums\BillingCycle;
use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Filament\Pages\FinanceInvoice;
use App\Models\Coupon;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Services\NotificationManager;
use App\Support\InstallationState;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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

function recoveryFixture(string $credit = '100.00', bool $originalWallet = false): array
{
    $user = User::factory()->create(['credit' => $credit]);
    $account = UpstreamApi::factory()->create();
    $product = Product::factory()->create(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id, 'markup_percent' => '0.00']);
    $instance = Instance::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'driver_type' => DriverType::ZJMF_API,
        'upstream_api_id' => $account->id, 'upstream_instance_id' => '99', 'status' => InstanceStatus::ACTIVE,
        'billing_cycle' => BillingCycle::MONTHLY, 'next_due_at' => now()->addDay(), 'recurring_amount' => '10.00', 'active_config' => []]);
    $state = (object) ['cost' => '10.00', 'due' => $instance->next_due_at->toIso8601String(), 'status' => 'Active', 'calls' => [], 'hook' => null];
    $level = DB::transactionLevel();
    Http::fake(function ($request) use ($state, $level) {
        expect(DB::transactionLevel())->toBe($level);
        $path = parse_url($request->url(), PHP_URL_PATH);
        $state->calls[$path] = ($state->calls[$path] ?? 0) + 1;
        if ($state->hook !== null && ($result = ($state->hook)($path)) !== null) {
            return $result;
        }
        if ($path === '/apply_credit') {
            $state->due = Carbon::parse($state->due)->addMonthNoOverflow()->toIso8601String();
        }

        return Http::response(match ($path) {
            '/zjmf_api_login' => ['status' => 200, 'jwt' => 'isolated-renewal-jwt'],
            '/host/header' => ['status' => 200, 'data' => ['host_data' => ['domainstatus' => $state->status, 'nextduedate' => $state->due]]],
            '/host/renewpage' => ['status' => 200, 'data' => ['host' => ['amount' => $state->cost, 'billingcycle' => 'monthly'], 'currency' => ['code' => 'CNY']]],
            '/host/renew' => ['status' => 200, 'data' => ['invoiceid' => 765]],
            '/use_credit_page' => ['status' => 200, 'data' => ['total' => $state->cost, 'currency' => ['code' => 'CNY']]],
            '/apply_credit' => ['status' => 1001],
            default => throw new RuntimeException('Unexpected recovery upstream call: '.$path),
        });
    });
    $root = app(CreateComboInvoiceAction::class)->renewal($user, $instance, $originalWallet, 'epay_wechat');
    $root->update(['expires_at' => now()->subSecond()]);
    app(ExpireUnpaidInvoicesAction::class)->execute();

    return [$user, $instance, $root->fresh(), $state];
}

function confirmRecovery(User $user, Invoice $parent, array $quote, bool $wallet = true, ?string $coupon = null, ?string $request = null): Invoice
{
    return app(CreateComboInvoiceAction::class)->recoverRenewal($user, $parent, $request ?? (string) Str::ulid(), $quote['quote_token'], $wallet, 'epay_wechat', $coupon, 'Customer confirmed a newly quoted renewal');
}

it('recovers an expired renewal exactly once with a confirmed payment allocation', function (string $credit, string $cost, bool $wallet, string $balance, string $frozen, string $gateway, int $tasks, int $ledger): void {
    [$user, $instance, $root, $state] = recoveryFixture($credit);
    $state->cost = $cost;
    $before = $root->getRawOriginal();
    $quote = app(QuoteRenewalRecoveryAction::class)->execute($user, $root, $wallet, 'epay_wechat', null);
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseCount('financial_transactions', 0);
    $request = (string) Str::ulid();
    $invoice = confirmRecovery($user, $root, $quote, $wallet, request: $request);
    $this->travel(10)->minutes();
    $again = confirmRecovery($user, $root, $quote, $wallet, request: $request);
    expect($again->id)->toBe($invoice->id)->and($invoice->renewal_root_id)->toBe($root->id)->and($invoice->replaces_invoice_id)->toBe($root->id)
        ->and($invoice->billing_period_key)->toBeNull()->and($invoice->purchase_request_id)->toBeNull()
        ->and($root->fresh()->getRawOriginal())->toBe($before)->and($user->fresh()->credit)->toBe($balance)
        ->and($user->fresh()->frozen_credit)->toBe($frozen)->and($invoice->gateway_amount)->toBe($gateway)
        ->and($invoice->upstream_expected_cost)->toBe($cost);
    $this->assertDatabaseCount('invoices', 2);
    $this->assertDatabaseCount('fulfillment_tasks', $tasks);
    $this->assertDatabaseCount('instances', 1);
    $this->assertDatabaseCount('financial_transactions', $ledger);
    $this->assertDatabaseCount('payment_attempts', 0);
    $this->assertDatabaseHas('audit_logs', ['action' => 'invoice.renewal_recovery', 'actor_id' => $user->id, 'subject_id' => $invoice->id]);
})->with([
    'wallet' => ['100.00', '10.00', true, '90.00', '0.00', '0.00', 1, 2],
    'combo' => ['3.00', '10.00', true, '0.00', '3.00', '7.00', 0, 1],
    'gateway' => ['100.00', '10.00', false, '100.00', '0.00', '10.00', 0, 0],
    'zero' => ['0.00', '0.00', true, '0.00', '0.00', '0.00', 1, 1],
]);

it('unfreezes the original wallet share and uses a fresh authorization for the replacement', function (): void {
    [$user, $instance, $root] = recoveryFixture('3.00', true);
    expect($user->fresh()->credit)->toBe('3.00')->and($user->fresh()->frozen_credit)->toBe('0.00');
    $quote = app(QuoteRenewalRecoveryAction::class)->execute($user, $root, true, 'epay_wechat', null);
    $invoice = confirmRecovery($user, $root, $quote);
    expect($invoice->credit_applied)->toBe('3.00')->and($user->fresh()->credit)->toBe('0.00')->and($user->fresh()->frozen_credit)->toBe('3.00');
    $this->assertDatabaseHas('financial_transactions', ['reference_key' => 'unfreeze:'.$root->id, 'amount' => '3.00']);
    $this->assertDatabaseHas('financial_transactions', ['reference_key' => 'freeze:'.$invoice->id, 'amount' => '3.00']);
    $this->assertDatabaseCount('financial_transactions', 3);
});

it('settles the replacement once and treats original late payments as wallet credit without a second renewal', function (): void {
    [$user, $instance, $root, $state] = recoveryFixture('3.00', true);
    $quote = app(QuoteRenewalRecoveryAction::class)->execute($user, $root, true, 'epay_wechat', null);
    $invoice = confirmRecovery($user, $root, $quote);
    $callbacks = app(HandlePaymentCallbackAction::class);
    $late = new VerifiedPaymentResult('epay_wechat', 'late-original', $root->invoice_no, '7.00');
    $paid = new VerifiedPaymentResult('epay_wechat', 'replacement-paid', $invoice->invoice_no, '7.00');
    $callbacks->execute($late);
    $callbacks->execute($paid);
    $callbacks->execute($paid);
    $callbacks->execute($late);
    app(ExecuteFulfillmentTaskAction::class)->execute(FulfillmentTask::sole()->id);
    expect($root->fresh()->status)->toBe(InvoiceStatus::EXPIRED)->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and($user->fresh()->credit)->toBe('7.00')->and($user->fresh()->frozen_credit)->toBe('0.00')
        ->and(FulfillmentTask::sole()->status)->toBe(FulfillmentTaskStatus::SUCCESS)
        ->and($state->calls['/host/renew'])->toBe(1)->and($state->calls['/apply_credit'])->toBe(1);
    $this->assertDatabaseCount('payment_callbacks', 2);
    $this->assertDatabaseCount('fulfillment_tasks', 1);
});

it('requires another explicit confirmation after the replacement also expires and keeps the chain linear', function (): void {
    [$user, $instance, $root] = recoveryFixture();
    $quote = app(QuoteRenewalRecoveryAction::class)->execute($user, $root, false, 'epay_wechat', null);
    $first = confirmRecovery($user, $root, $quote, false);
    expect(confirmRecovery($user, $root, $quote, false)->id)->toBe($first->id);
    expect(app(CreateComboInvoiceAction::class)->renewal($user, $instance)->id)->toBe($first->id);
    $first->update(['expires_at' => now()->subSecond()]);
    app(ExpireUnpaidInvoicesAction::class)->execute();
    $quote = app(QuoteRenewalRecoveryAction::class)->execute($user, $first->fresh(), false, 'epay_wechat', null);
    $second = confirmRecovery($user, $first, $quote, false);
    expect($second->renewal_root_id)->toBe($root->id)->and($second->replaces_invoice_id)->toBe($first->id)
        ->and(app(CreateComboInvoiceAction::class)->renewal($user, $instance)->id)->toBe($second->id);
    $this->assertDatabaseCount('invoices', 3);
    expect(fn () => DB::transaction(fn () => Invoice::factory()->create(['replaces_invoice_id' => $first->id])))
        ->toThrow(QueryException::class);
});

it('rejects changed confirmation identity under a committed recovery request key', function (): void {
    [$user, $instance, $root] = recoveryFixture();
    $quote = app(QuoteRenewalRecoveryAction::class)->execute($user, $root, true, 'epay_wechat', null);
    $request = (string) Str::ulid();
    confirmRecovery($user, $root, $quote, request: $request);
    expect(fn () => confirmRecovery($user, $root, $quote, false, request: $request))->toThrow(ValidationException::class);
    expect($user->fresh()->credit)->toBe('90.00');
    $this->assertDatabaseCount('invoices', 2);
});

it('rejects invalid recovery quotes and changed price wallet or service before writing a new invoice', function (string $change): void {
    [$user, $instance, $root, $state] = recoveryFixture();
    $quote = app(QuoteRenewalRecoveryAction::class)->execute($user, $root, true, 'epay_wechat', null);
    match ($change) {
        'token' => $quote['quote_token'] = 'tampered',
        'expired' => $this->travel(6)->minutes(),
        'price' => $state->cost = '11.00',
        'wallet' => $user->forceFill(['credit' => '2.00'])->save(),
        'due' => $state->due = now()->addDays(2)->toIso8601String(),
        'binding' => $instance->update(['upstream_instance_id' => '100']),
    };
    expect(fn () => confirmRecovery($user, $root, $quote))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('fulfillment_tasks', 0);
})->with(['token', 'expired', 'price', 'wallet', 'due', 'binding']);

it('previews coupons without consuming them and rolls back a changed discount at confirmation', function (): void {
    [$user, $instance, $root] = recoveryFixture();
    $coupon = Coupon::create(['code' => 'RECOVER', 'type' => 'fixed', 'value' => '1.00', 'min_order_amount' => '0.00',
        'applies_to' => 'renew', 'total_quota' => 1, 'per_user_limit' => 1, 'is_active' => true]);
    app(ClaimCouponAction::class)->execute($user, $coupon->code);
    $quote = app(QuoteRenewalRecoveryAction::class)->execute($user, $root, true, 'epay_wechat', $coupon->code);
    expect($coupon->fresh()->used_count)->toBe(0)->and($quote['total'])->toBe('9.00');
    $coupon->update(['value' => '2.00']);
    expect(fn () => confirmRecovery($user, $root, $quote, coupon: $coupon->code))->toThrow(ValidationException::class);
    expect($coupon->fresh()->used_count)->toBe(0);
    $this->assertDatabaseCount('coupon_usages', 0);
    $quote = app(QuoteRenewalRecoveryAction::class)->execute($user, $root, true, 'epay_wechat', $coupon->code);
    $invoice = confirmRecovery($user, $root, $quote, coupon: $coupon->code);
    expect($invoice->total_amount)->toBe('8.00')->and($coupon->fresh()->used_count)->toBe(1);
    $this->assertDatabaseCount('coupon_usages', 1);
});

it('does not recover other terminal statuses unsettled wallet releases or unresolved supplier tasks', function (string $change): void {
    [$user, $instance, $root] = recoveryFixture();
    match ($change) {
        'cancelled' => $root->update(['status' => InvoiceStatus::CANCELLED]),
        'refunded' => $root->update(['status' => InvoiceStatus::REFUNDED]),
        'paid' => $root->update(['status' => InvoiceStatus::PAID, 'paid_at' => now()]),
        'not swept' => $root->update(['status' => InvoiceStatus::UNPAID]),
        'wallet evidence' => $root->update(['credit_applied' => '3.00']),
        'unresolved task' => FulfillmentTask::factory()->create(['instance_id' => $instance->id, 'invoice_id' => $root->id, 'user_id' => $user->id, 'status' => FulfillmentTaskStatus::FAILED]),
    };
    expect(fn () => app(QuoteRenewalRecoveryAction::class)->execute($user, $root->fresh(), true, 'epay_wechat', null))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('invoices', 1);
    expect($user->fresh()->credit)->toBe('100.00');
})->with(['cancelled', 'refunded', 'paid', 'not swept', 'wallet evidence', 'unresolved task']);

it('provides owner-only recovery forms and a separate confirmation without exposing supplier cost', function (): void {
    [$user, $instance, $root] = recoveryFixture();
    $path = '/console/invoices/'.$root->id.'/renewal-recovery';
    $this->actingAs(User::factory()->create())->postJson($path.'/quote', [])->assertForbidden();
    $this->actingAs($user)->get('/console/invoices/'.$root->id)->assertOk()->assertSee('重新核价并恢复续费');
    $request = (string) Str::ulid();
    $data = ['request_id' => $request, 'use_credit' => true, 'gateway' => 'epay_wechat'];
    $html = $this->post($path.'/quote', $data)->assertOk()->assertSee($request)->assertSee('确认并创建续费账单');
    expect($html->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $quote = $this->postJson($path.'/quote', $data)->assertOk()->assertJsonMissingPath('upstream')->assertJsonMissingPath('fingerprint')->json();
    $payload = $data + ['quote_token' => $quote['quote_token'], 'reason' => 'I confirmed the renewed price'];
    $this->postJson($path, $payload)->assertUnprocessable()->assertJsonValidationErrors('confirm');
    $result = $this->postJson($path, $payload + ['confirm' => true])->assertCreated();
    $invoice = Invoice::findOrFail($result->json('invoice_id'));
    $this->get('/console/invoices/'.$root->id)->assertOk()->assertSee($invoice->invoice_no)->assertSee('查看当前续费账单');
    $this->assertDatabaseCount('invoices', 2);
    expect(FinanceInvoice::canAccess())->toBeFalse();
    $operator = User::factory()->create();
    app(AssignAdminRoleAction::class)->execute($operator, 'finance-viewer', 'Isolated recovery verification');
    $this->actingAs($operator)->get('/admin/finance/invoice?invoice_id='.$root->id)->assertOk()->assertSee('续费恢复核对')->assertSee($invoice->invoice_no);
});

it('keeps committed recovery replay available after key rotation and quote expiry', function (): void {
    [$user, $instance, $root, $state] = recoveryFixture();
    $quote = app(QuoteRenewalRecoveryAction::class)->execute($user, $root, true, 'epay_wechat', null);
    $request = (string) Str::ulid();
    $invoice = confirmRecovery($user, $root, $quote, request: $request);
    $old = config('app.key');
    $new = 'base64:'.base64_encode(random_bytes(32));
    $installation = app(InstallationState::class);
    $installation->write('installed.lock', $installation->seal(['format' => 1, 'admin_id' => 1], $old));
    File::put(storage_path('framework/down'), '{}');
    app(ReencryptSecretsAction::class)->execute($old, $new, 'Isolated recovery identity rotation', true);
    $beforeCalls = $state->calls;
    $previous = app('encrypter');
    try {
        config(['app.key' => $new]);
        app()->instance('encrypter', $installation->encrypter($new));
        Crypt::clearResolvedInstance('encrypter');
        $this->travel(10)->minutes();
        expect(confirmRecovery($user, $root, $quote, request: $request)->id)->toBe($invoice->id);
        expect(fn () => confirmRecovery($user, $root, $quote, false, request: $request))->toThrow(ValidationException::class);
        expect($state->calls)->toBe($beforeCalls)->and($user->fresh()->credit)->toBe('90.00');
    } finally {
        config(['app.key' => $old]);
        app()->instance('encrypter', $previous);
        Crypt::clearResolvedInstance('encrypter');
    }
    $this->assertDatabaseCount('invoices', 2);
    $this->assertDatabaseCount('fulfillment_tasks', 1);
});

it('rolls back recovery money coupon invoice and outbox together when settlement fails', function (): void {
    [$user, $instance, $root] = recoveryFixture();
    $coupon = Coupon::create(['code' => 'ATOMIC', 'type' => 'fixed', 'value' => '1.00', 'min_order_amount' => '0.00',
        'applies_to' => 'renew', 'total_quota' => 1, 'per_user_limit' => 1, 'is_active' => true]);
    app(ClaimCouponAction::class)->execute($user, $coupon->code);
    $quote = app(QuoteRenewalRecoveryAction::class)->execute($user, $root, true, 'epay_wechat', $coupon->code);
    $notifications = Mockery::mock(NotificationManager::class);
    $notifications->shouldReceive('queue')->once()->andThrow(new RuntimeException('Simulated outbox failure'));
    app()->instance(NotificationManager::class, $notifications);
    expect(fn () => confirmRecovery($user, $root, $quote, coupon: $coupon->code))->toThrow(RuntimeException::class, 'Simulated outbox failure');
    expect($user->fresh()->credit)->toBe('100.00')->and($user->fresh()->frozen_credit)->toBe('0.00')->and($coupon->fresh()->used_count)->toBe(0);
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('fulfillment_tasks', 0);
    $this->assertDatabaseCount('coupon_usages', 0);
    $this->assertDatabaseMissing('audit_logs', ['action' => 'invoice.renewal_recovery']);
});

it('does not return the original coupon quota when an expired renewal is recovered', function (): void {
    [$user, $instance, $root] = recoveryFixture();
    $coupon = Coupon::create(['code' => 'ONCE', 'type' => 'fixed', 'value' => '1.00', 'min_order_amount' => '0.00',
        'applies_to' => 'renew', 'total_quota' => 1, 'per_user_limit' => 1, 'is_active' => true, 'used_count' => 1]);
    app(ClaimCouponAction::class)->execute($user, $coupon->code);
    DB::table('coupon_usages')->insert(['coupon_id' => $coupon->id, 'user_id' => $user->id, 'invoice_id' => $root->id, 'discount_amount' => '1.00']);
    expect(fn () => app(QuoteRenewalRecoveryAction::class)->execute($user, $root, true, 'epay_wechat', $coupon->code))->toThrow(ValidationException::class);
    $quote = app(QuoteRenewalRecoveryAction::class)->execute($user, $root, false, 'epay_wechat', null);
    confirmRecovery($user, $root, $quote, false);
    expect($coupon->fresh()->used_count)->toBe(1);
    $this->assertDatabaseCount('coupon_usages', 1);
});

it('retains unknown supplier intents without issuing a second renewal or payment', function (): void {
    [$user, $instance, $root, $state] = recoveryFixture();
    $quote = app(QuoteRenewalRecoveryAction::class)->execute($user, $root, true, 'epay_wechat', null);
    $invoice = confirmRecovery($user, $root, $quote);
    $task = FulfillmentTask::sole();
    $payload = $task->payload;
    $payload['operation'] = ['state' => 'create_requested'];
    $task->update(['payload' => $payload]);
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::FAILED)->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and($state->calls['/host/renew'] ?? 0)->toBe(0)->and($state->calls['/apply_credit'] ?? 0)->toBe(0);
    expect(fn () => app(QuoteRenewalRecoveryAction::class)->execute($user, $invoice->fresh(), true, 'epay_wechat', null))->toThrow(ValidationException::class);
    expect($user->fresh()->credit)->toBe('90.00');
    $this->assertDatabaseCount('invoices', 2);
    $this->assertDatabaseCount('fulfillment_tasks', 1);
});

it('refuses recovery when the supplier is unavailable or the service configuration changes', function (string $change): void {
    [$user, $instance, $root, $state] = recoveryFixture();
    $quote = app(QuoteRenewalRecoveryAction::class)->execute($user, $root, true, 'epay_wechat', null);
    match ($change) {
        'unavailable' => $state->hook = fn ($path) => $path === '/host/renewpage' ? Http::response(['status' => 503], 503) : null,
        'config' => $instance->update(['active_config' => ['changed' => true]]),
        'terminated' => $state->status = 'Deleted',
        'currency' => $instance->update(['currency' => 'USD']),
    };
    // Changing the service currency breaks its identity with the CNY root before quoting.
    expect(fn () => confirmRecovery($user, $root, $quote))->toThrow($change === 'unavailable' ? DomainException::class : ValidationException::class);
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseCount('financial_transactions', 0);
    expect($user->fresh()->credit)->toBe('100.00');
})->with(['unavailable', 'config', 'terminated', 'currency']);

it('requires a reason ownership and CSRF and does not flash the private quote token', function (): void {
    [$user, $instance, $root] = recoveryFixture();
    $quote = app(QuoteRenewalRecoveryAction::class)->execute($user, $root, true, 'epay_wechat', null);
    $path = '/console/invoices/'.$root->id.'/renewal-recovery';
    $data = ['request_id' => (string) Str::ulid(), 'quote_token' => $quote['quote_token'], 'use_credit' => true, 'gateway' => 'epay_wechat', 'confirm' => true];
    $this->actingAs(User::factory()->create())->postJson($path, $data + ['reason' => 'Not the owner'])->assertForbidden();
    $this->actingAs($user)->from('/console/invoices/'.$root->id)->post($path, $data)->assertRedirect()->assertSessionHasErrors('reason');
    expect(session()->getOldInput())->not->toHaveKey('quote_token');
    $this->app->detectEnvironment(fn () => 'local');
    $this->withSession(['_token' => 'test-recovery-csrf'])->post($path.'/quote', [])->assertStatus(419);
    $this->assertDatabaseCount('invoices', 1);
    expect($user->fresh()->credit)->toBe('100.00');
});

it('shows recovery history on the related service without querying the supplier', function (): void {
    [$user, $instance, $root, $state] = recoveryFixture();
    $calls = $state->calls;
    $this->actingAs($user)->get('/console/instances/'.$instance->id)->assertOk()->assertSee($root->invoice_no)->assertSee('重新核价并恢复续费');
    expect($state->calls)->toBe($calls);
    $quote = app(QuoteRenewalRecoveryAction::class)->execute($user, $root, false, 'epay_wechat', null);
    $invoice = confirmRecovery($user, $root, $quote, false);
    $calls = $state->calls;
    $this->get('/console/instances/'.$instance->id)->assertOk()->assertSee($invoice->invoice_no)->assertSee($root->invoice_no);
    expect($state->calls)->toBe($calls);
});

it('rejects a corrupted renewal chain at payment and retains callback evidence without settling it', function (): void {
    [$user, $instance, $root] = recoveryFixture();
    $quote = app(QuoteRenewalRecoveryAction::class)->execute($user, $root, false, 'epay_wechat', null);
    $invoice = confirmRecovery($user, $root, $quote, false);
    // Simulate inconsistent historical data: a predecessor was manually reopened.
    $root->update(['status' => InvoiceStatus::UNPAID]);
    $payment = new VerifiedPaymentResult('epay_wechat', 'corrupt-chain-paid', $invoice->invoice_no, '10.00');
    expect(app(HandlePaymentCallbackAction::class)->execute($payment))->toBe('success');
    $this->assertDatabaseHas('payment_callbacks', ['gateway_trade_no' => 'corrupt-chain-paid', 'outcome' => 'rejected_invalid_state']);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::UNPAID)->and($user->fresh()->credit)->toBe('100.00');
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('fulfillment_tasks', 0);
});

it('requires manual review when the saved renewal period would itself remain overdue', function (): void {
    [$user, $instance, $root, $state] = recoveryFixture();
    $this->travel(40)->days();
    $calls = $state->calls;
    expect(fn () => app(QuoteRenewalRecoveryAction::class)->execute($user, $root, true, 'epay_wechat', null))
        ->toThrow(ValidationException::class, 'confirm supplier billing and start-date policy manually');
    expect($state->calls)->toBe($calls)->and($user->fresh()->credit)->toBe('100.00');
    $this->actingAs($user)->get('/console/invoices/'.$root->id)->assertOk()->assertSee('confirm supplier billing and start-date policy manually');
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('fulfillment_tasks', 0);
});
