<?php

declare(strict_types=1);

use App\Actions\ConfigureWalletTopupAction;
use App\Actions\CreateWalletTopupInvoiceAction;
use App\Actions\ExpireUnpaidInvoicesAction;
use App\Actions\HandlePaymentCallbackAction;
use App\Actions\InitiatePaymentAction;
use App\Actions\RefundInvoiceAction;
use App\Data\VerifiedPaymentResult;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentCallbackOutcome;
use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\FulfillmentTask;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\PaymentCallback;
use App\Models\User;
use App\Services\NotificationManager;
use App\Services\SettingService;
use App\Support\WalletTopupIntent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Queue::fake();
    Http::preventStrayRequests();
    foreach (['wallet.topup_enabled' => '1', 'payment.epay_api_url' => 'https://pay.example.test',
        'payment.epay_pid' => 'topup-test-merchant', 'payment.epay_key' => 'topup-test-key'] as $key => $value) {
        walletTopupSetting($key, $value);
    }
});

function walletTopupSetting(string $key, string $value): void
{
    [$group, $name] = explode('.', $key, 2);
    DB::table('system_settings')->updateOrInsert(['group' => $group, 'key' => $name], [
        'value' => $name === 'epay_key' ? Crypt::encryptString($value) : $value, 'is_encrypted' => $name === 'epay_key',
        'created_at' => now(), 'updated_at' => now()]);
    app(SettingService::class)->forget($key);
}

function walletTopupFixture(string $amount = '10.00', string $credit = '3.00'): array
{
    $user = User::factory()->create(['credit' => $credit, 'frozen_credit' => '2.00']);
    $input = ['request_id' => (string) Str::ulid(), 'amount' => $amount, 'gateway' => 'epay_wechat'];
    $invoice = app(CreateWalletTopupInvoiceAction::class)->execute($user, $input);

    return [$user, $invoice, $input];
}

function walletTopupCallback(Invoice $invoice, string $trade = 'TOPUP-TRADE', string $amount = '10.00', ?string $outTradeNo = null): string
{
    return app(HandlePaymentCallbackAction::class)->execute(new VerifiedPaymentResult(gateway: 'epay_wechat',
        gatewayTradeNo: $trade, outTradeNo: $outTradeNo ?? $invoice->invoice_no, paidAmount: $amount,
        rawPayload: ['money' => $amount, 'trade_no' => $trade]));
}

it('creates only a canonical CNY topup invoice without product or wallet side effects', function (string $amount, string $canonical): void {
    [$user, $invoice] = walletTopupFixture($amount);
    expect($invoice->type)->toBe(InvoiceType::WALLET_TOPUP)->and($invoice->status)->toBe(InvoiceStatus::UNPAID)
        ->and($invoice->total_amount)->toBe($canonical)->and($invoice->gateway_amount)->toBe($canonical)
        ->and($invoice->credit_applied)->toBe('0.00')->and($invoice->discount_amount)->toBe('0.00')
        ->and($invoice->currency)->toBe('CNY')->and($invoice->product_id)->toBeNull()->and($invoice->instance_id)->toBeNull()
        ->and($invoice->coupon_id)->toBeNull()->and($invoice->purchase_request_id)->toBeNull()
        ->and($invoice->billing_period_key)->toBeNull()->and($invoice->expires_at->isFuture())->toBeTrue()
        ->and($user->fresh()->credit)->toBe('3.00')->and($user->fresh()->frozen_credit)->toBe('2.00')
        ->and(app(WalletTopupIntent::class)->isValid($invoice->fresh()))->toBeTrue();
    foreach (['financial_transactions', 'payment_attempts', 'instances', 'fulfillment_tasks', 'coupon_usages', 'affiliate_commissions', 'notification_events'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    Http::assertNothingSent();
})->with([['1', '1.00'], ['1.0', '1.00'], ['10000.00', '10000.00']]);

it('rejects malformed or out of range topup inputs with no financial side effects', function (mixed $amount): void {
    $user = User::factory()->create(['credit' => '4.00']);
    expect(fn () => app(CreateWalletTopupInvoiceAction::class)->execute($user,
        ['request_id' => (string) Str::ulid(), 'amount' => $amount, 'gateway' => 'epay_wechat']))->toThrow(ValidationException::class);
    expect($user->fresh()->credit)->toBe('4.00');
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
    Http::assertNothingSent();
})->with(['zero' => ['0.00'], 'below' => ['0.99'], 'above' => ['10000.01'], 'negative' => ['-1.00'],
    'plus' => ['+1.00'], 'exponent' => ['1e2'], 'precision' => ['1.001'], 'float' => [1.0],
    'integer' => [1], 'array' => [[]], 'empty' => [''], 'space' => [' 1.00'], 'huge' => ['999999999999999999999']]);

it('requires a stable topup request ULID and supported gateway', function (array $changes): void {
    $user = User::factory()->create();
    $input = array_replace(['request_id' => (string) Str::ulid(), 'amount' => '10', 'gateway' => 'epay_wechat'], $changes);
    expect(fn () => app(CreateWalletTopupInvoiceAction::class)->execute($user, $input))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('invoices', 0);
})->with([[['request_id' => null]], [['request_id' => 'not-a-ulid']], [['request_id' => []]],
    [['gateway' => 'unsupported']], [['gateway' => []]]]);

it('replays terminal topup invoices before current enablement and canonicalizes the request', function (InvoiceStatus $state): void {
    [$user, $invoice, $input] = walletTopupFixture('1');
    $invoice->update(['status' => $state]);
    $before = $invoice->fresh()->getRawOriginal();
    walletTopupSetting('wallet.topup_enabled', '0');
    walletTopupSetting('payment.epay_api_url', '');
    $again = app(CreateWalletTopupInvoiceAction::class)->execute($user,
        array_replace($input, ['request_id' => strtolower($input['request_id']), 'amount' => '1.00']));
    expect($again->id)->toBe($invoice->id)->and($again->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseCount('financial_transactions', 0);
    Http::assertNothingSent();
})->with([InvoiceStatus::UNPAID, InvoiceStatus::PAID, InvoiceStatus::EXPIRED, InvoiceStatus::CANCELLED]);

it('rejects changed intent under a topup key and scopes keys to the original user', function (): void {
    [$user, $invoice, $input] = walletTopupFixture();
    foreach ([['amount' => '11.00'], ['gateway' => 'epay_alipay']] as $changes) {
        expect(fn () => app(CreateWalletTopupInvoiceAction::class)->execute($user, array_replace($input, $changes)))
            ->toThrow(ValidationException::class);
    }
    $other = User::factory()->create();
    $second = app(CreateWalletTopupInvoiceAction::class)->execute($other, $input);
    expect($second->id)->not->toBe($invoice->id)->and($second->topup_request_id)->toBe($invoice->topup_request_id);
    $this->assertDatabaseCount('invoices', 2);
    $this->assertDatabaseCount('financial_transactions', 0);
});

it('blocks new topups while disabled or the payment gateway is unavailable', function (string $setting): void {
    walletTopupSetting($setting, $setting === 'wallet.topup_enabled' ? '0' : '');
    $user = User::factory()->create();
    expect(fn () => app(CreateWalletTopupInvoiceAction::class)->execute($user,
        ['request_id' => (string) Str::ulid(), 'amount' => '10.00', 'gateway' => 'epay_wechat']))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('invoices', 0);
})->with(['wallet.topup_enabled', 'payment.epay_api_url']);

it('settles principal and excess exactly once and records no resource or frozen settlement', function (string $paid, string $balance, int $ledger): void {
    [$user, $invoice] = walletTopupFixture();
    expect(walletTopupCallback($invoice, amount: $paid))->toBe('success');
    walletTopupCallback($invoice, amount: $paid);
    $principal = FinancialTransaction::where('reference_key', 'topup:'.$invoice->id)->sole();
    expect($user->fresh()->credit)->toBe($balance)->and($user->fresh()->frozen_credit)->toBe('2.00')
        ->and($principal->type)->toBe(TransactionType::CREDIT_IN)->and($principal->amount)->toBe('10.00')
        ->and($principal->balance_after)->toBe('13.00')->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and($invoice->fresh()->instance_id)->toBeNull()->and($invoice->fresh()->paid_at)->not->toBeNull()
        ->and(FinancialTransaction::where('reference_key', 'payment:'.$invoice->id)->sole()->type)->toBe(TransactionType::PAYMENT)
        ->and(DB::table('notification_events')->where('event_key', 'invoice:'.$invoice->id.':wallet-topup-credited')->count())->toBe(1);
    $this->assertDatabaseCount('financial_transactions', $ledger);
    $this->assertDatabaseCount('payment_callbacks', 1);
    foreach (['instances', 'fulfillment_tasks', 'coupon_usages', 'affiliate_commissions'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    expect(PaymentCallback::sole()->outcome)->toBe($paid === '10.00' ? PaymentCallbackOutcome::PROCESSED_PAID : PaymentCallbackOutcome::PROCESSED_OVERPAYMENT);
})->with([['10.00', '13.00', 2], ['12.00', '15.00', 3]]);

it('does not accumulate short payments and preserves each real receipt for reconciliation', function (): void {
    [$user, $invoice] = walletTopupFixture();
    walletTopupCallback($invoice, 'SHORT-1', '6.00');
    walletTopupCallback($invoice, 'SHORT-2', '6.00');
    expect($user->fresh()->credit)->toBe('3.00')->and($invoice->fresh()->status)->toBe(InvoiceStatus::UNPAID)
        ->and(PaymentCallback::where('outcome', PaymentCallbackOutcome::REJECTED_SHORT_AMOUNT)->count())->toBe(2)
        ->and(DB::table('notification_events')->where('event_type', 'payment.callback.attention')->count())->toBe(2);
    $this->assertDatabaseCount('financial_transactions', 0);
    walletTopupCallback($invoice, 'FULL-3', '10.00');
    expect($user->fresh()->credit)->toBe('13.00')->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID);
    $this->assertDatabaseCount('payment_callbacks', 3);
    $this->assertDatabaseCount('financial_transactions', 2);
});

it('accepts database JSON key normalization without weakening stored intent or money checks', function (): void {
    [$user, $invoice, $input] = walletTopupFixture();
    $items = $invoice->line_items;
    $items['wallet_topup']['request'] = array_reverse($items['wallet_topup']['request'], true);
    $items['items'][0] = array_reverse($items['items'][0], true);
    $invoice->update(['line_items' => $items]);
    expect(app(CreateWalletTopupInvoiceAction::class)->execute($user, $input)->id)->toBe($invoice->id);
    walletTopupCallback($invoice);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::PAID)->and($user->fresh()->credit)->toBe('13.00');
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseCount('financial_transactions', 2);
});

it('credits a second distinct real trade to the wallet without repeating the topup principal', function (): void {
    [$user, $invoice] = walletTopupFixture();
    walletTopupCallback($invoice, 'FIRST', '10.00');
    walletTopupCallback($invoice, 'SECOND', '7.00');
    walletTopupCallback($invoice, 'SECOND', '7.00');
    expect($user->fresh()->credit)->toBe('20.00')->and(FinancialTransaction::where('reference_key', 'topup:'.$invoice->id)->count())->toBe(1)
        ->and(PaymentCallback::where('gateway_trade_no', 'SECOND')->sole()->outcome)->toBe(PaymentCallbackOutcome::PROCESSED_OVERPAYMENT)
        ->and(FulfillmentTask::count())->toBe(0);
    $this->assertDatabaseCount('financial_transactions', 3);
});

it('expires a topup without unfreezing unrelated funds and credits late money without reopening', function (): void {
    [$user, $invoice] = walletTopupFixture();
    $invoice->update(['expires_at' => now()->subMinute()]);
    app(ExpireUnpaidInvoicesAction::class)->execute();
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::EXPIRED)->and($user->fresh()->frozen_credit)->toBe('2.00');
    $this->assertDatabaseCount('financial_transactions', 0);
    walletTopupCallback($invoice, 'LATE', '7.00');
    expect($user->fresh()->credit)->toBe('10.00')->and($user->fresh()->frozen_credit)->toBe('2.00')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::EXPIRED)->and(PaymentCallback::sole()->outcome)->toBe(PaymentCallbackOutcome::PROCESSED_LATE_CREDIT);
    $this->assertDatabaseCount('financial_transactions', 1);
    $this->assertDatabaseCount('fulfillment_tasks', 0);
});

it('rejects an entire overflowing receipt durably and never retries it after wallet space changes', function (): void {
    [$user, $invoice] = walletTopupFixture('1.00', '9999999994.99');
    walletTopupCallback($invoice, 'CAPACITY', '10.00');
    expect($user->fresh()->credit)->toBe('9999999994.99')->and($invoice->fresh()->status)->toBe(InvoiceStatus::UNPAID)
        ->and(PaymentCallback::sole()->outcome)->toBe(PaymentCallbackOutcome::REJECTED_INVALID_STATE)
        ->and(DB::table('audit_logs')->where('action', 'payment.callback.attention')->value('reason'))->toBe('wallet_capacity');
    $this->assertDatabaseCount('financial_transactions', 0);
    $user->forceFill(['credit' => '0.00'])->save();
    walletTopupCallback($invoice, 'CAPACITY', '10.00');
    expect($user->fresh()->credit)->toBe('0.00')->and($invoice->fresh()->status)->toBe(InvoiceStatus::UNPAID);
    $this->assertDatabaseCount('payment_callbacks', 1);
    $this->assertDatabaseCount('notification_events', 1);
});

it('rejects damaged topup evidence before payment or automatic receipt accounting', function (string $damage): void {
    [$user, $invoice] = walletTopupFixture();
    $items = $invoice->line_items;
    match ($damage) {
        'amount' => $invoice->gateway_amount = '11.00',
        'discount' => $invoice->discount_amount = '1.00',
        'wallet' => $invoice->credit_applied = '1.00',
        'snapshot' => $items['wallet_topup']['request']['amount'] = '11.00',
        'line' => $items['items'][0]['amount'] = '0.01',
    };
    $invoice->line_items = $items;
    $invoice->save();
    expect(fn () => app(InitiatePaymentAction::class)->execute($invoice, $user))->toThrow(ValidationException::class);
    Http::assertNothingSent();
    walletTopupCallback($invoice);
    expect($user->fresh()->credit)->toBe('3.00')->and($invoice->fresh()->status)->toBe(InvoiceStatus::UNPAID)
        ->and(DB::table('audit_logs')->where('action', 'payment.callback.attention')->value('reason'))->toBe('invalid_topup_snapshot');
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('payment_attempts', 0);
})->with(['amount', 'discount', 'wallet', 'snapshot', 'line']);

it('retains original financial identity after closure without delivering to a new account', function (InvoiceStatus $state): void {
    [$user, $invoice] = walletTopupFixture();
    $email = $user->email;
    $user->forceFill(['email' => 'closed-'.$user->id.'@example.invalid', 'anonymized_at' => now()])->save();
    $user->delete();
    $new = User::factory()->create(['email' => $email, 'credit' => '0.00']);
    $invoice->update(['status' => $state]);
    walletTopupCallback($invoice, 'CLOSED-LATE', '7.00');
    expect(User::withTrashed()->findOrFail($user->id)->credit)->toBe('10.00')->and($new->fresh()->credit)->toBe('0.00')
        ->and(User::withTrashed()->findOrFail($user->id)->trashed())->toBeTrue()
        ->and($invoice->fresh()->status)->toBe($state)
        ->and(DB::table('audit_logs')->where('action', 'payment.callback.attention')->value('reason'))->toBe('closed_account');
    $this->assertDatabaseCount('financial_transactions', 1);
    $this->assertDatabaseCount('fulfillment_tasks', 0);
    expect(DB::table('notification_events')->where('user_id', $new->id)->count())->toBe(0);
})->with([InvoiceStatus::EXPIRED, InvoiceStatus::CANCELLED, InvoiceStatus::PAID]);

it('blocks new topups payments and provisioning for an anomalous closed unpaid account', function (): void {
    [$user, $invoice, $input] = walletTopupFixture();
    $user->forceFill(['anonymized_at' => now()])->save();
    $user->delete();
    expect(fn () => app(CreateWalletTopupInvoiceAction::class)->execute($user, $input))->toThrow(ValidationException::class);
    expect(fn () => app(InitiatePaymentAction::class)->execute($invoice, $user))->toThrow(ValidationException::class);
    walletTopupCallback($invoice);
    expect(User::withTrashed()->findOrFail($user->id)->credit)->toBe('3.00')->and($invoice->fresh()->status)->toBe(InvoiceStatus::UNPAID)
        ->and(PaymentCallback::sole()->outcome)->toBe(PaymentCallbackOutcome::REJECTED_INVALID_STATE);
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('fulfillment_tasks', 0);
    Http::assertNothingSent();
});

it('never allows either product refund mode to repay a topup including keepService', function (string $mode): void {
    [$user, $invoice] = walletTopupFixture();
    walletTopupCallback($invoice);
    $actor = User::factory()->create();
    Gate::define('refund-invoice', fn (): bool => true);
    expect(fn () => app(RefundInvoiceAction::class)->execute($invoice, $mode, keepService: true, reason: 'Attempt recharge refund', actor: $actor))
        ->toThrow(DomainException::class, 'Wallet top-ups cannot use product refunds.');
    expect($user->fresh()->credit)->toBe('13.00')->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID);
    $this->assertDatabaseCount('financial_transactions', 2);
    expect(DB::table('audit_logs')->where('action', 'invoice.refund')->count())->toBe(0);
})->with(['refund_wallet', 'external_record']);

it('rolls back the receipt and wallet if the durable notification cannot be recorded', function (): void {
    [$user, $invoice] = walletTopupFixture();
    $real = app(NotificationManager::class);
    $failure = Mockery::mock(NotificationManager::class);
    $failure->shouldReceive('queue')->once()->andThrow(new RuntimeException('simulated outbox write failure'));
    $this->app->instance(NotificationManager::class, $failure);
    expect(fn () => walletTopupCallback($invoice))->toThrow(RuntimeException::class, 'simulated outbox write failure');
    expect($user->fresh()->credit)->toBe('3.00')->and($invoice->fresh()->status)->toBe(InvoiceStatus::UNPAID);
    $this->assertDatabaseCount('payment_callbacks', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->app->instance(NotificationManager::class, $real);
    walletTopupCallback($invoice);
    expect($user->fresh()->credit)->toBe('13.00')->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID);
});

it('commits payment attempts before HTTP and settles the exact mapped topup', function (): void {
    [$user, $invoice] = walletTopupFixture();
    Http::fake(function ($request) use ($invoice) {
        $attempt = PaymentAttempt::where('out_trade_no', $request['out_trade_no'])->sole();
        expect($attempt->invoice_id)->toBe($invoice->id)->and($attempt->amount)->toBe('10.00')
            ->and($attempt->request_payload['epay_profile_id'])->toBeString();

        return Http::response(['code' => 1, 'payurl' => 'https://pay.example.test/checkout'], 200);
    });
    $result = app(InitiatePaymentAction::class)->execute($invoice, $user);
    expect($result->success)->toBeTrue();
    walletTopupCallback($invoice, 'ATTEMPT-PAID', '10.00', $result->outTradeNo);
    expect(PaymentAttempt::sole()->status)->toBe('paid')->and($user->fresh()->credit)->toBe('13.00');
});

it('provides authenticated wallet creation and safe paid invoice views without resource controls', function (): void {
    $user = User::factory()->create(['credit' => '3.00']);
    $input = ['request_id' => (string) Str::ulid(), 'amount' => '10', 'gateway' => 'epay_wechat'];
    $this->get('/console/wallet')->assertRedirect('/login');
    $wallet = $this->actingAs($user)->get('/console/wallet')->assertOk()->assertSee('我的钱包');
    expect($wallet->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $response = $this->postJson('/console/wallet/topups', $input + ['total_amount' => '0.01', 'credit_applied' => '10.00', 'user_id' => 123]);
    $response->assertCreated()->assertJsonPath('total_amount', '10.00')->assertJsonPath('currency', 'CNY');
    $invoice = Invoice::findOrFail($response->json('invoice_id'));
    walletTopupCallback($invoice);
    $this->get('/console/invoices/'.$invoice->id)->assertOk()->assertSee('充值已到账')->assertDontSee('交付信息等待确认')->assertDontSee('历史商品');
    $this->get('/console/wallet')->assertOk()->assertSee($invoice->invoice_no)->assertSee('13.00');
    $other = User::factory()->create();
    $this->actingAs($other)->get('/console/invoices/'.$invoice->id)->assertForbidden();
    $this->get('/console/wallet')->assertOk()->assertDontSee($invoice->invoice_no);
});

it('reads current topup enablement despite a stale settings cache and preserves payable existing invoices', function (): void {
    [$user, $invoice] = walletTopupFixture();
    expect(app(SettingService::class)->getBool('wallet.topup_enabled'))->toBeTrue();
    DB::table('system_settings')->where('group', 'wallet')->where('key', 'topup_enabled')->update(['value' => '0']);
    expect(app(SettingService::class)->getBool('wallet.topup_enabled'))->toBeTrue()
        ->and(app(ConfigureWalletTopupAction::class)->enabled())->toBeFalse();
    expect(fn () => app(CreateWalletTopupInvoiceAction::class)->execute($user,
        ['request_id' => (string) Str::ulid(), 'amount' => '10.00', 'gateway' => 'epay_wechat']))->toThrow(ValidationException::class);
    Http::fake(['*' => Http::response(['code' => 1, 'payurl' => 'https://pay.example.test/continue'])]);
    expect(app(InitiatePaymentAction::class)->execute($invoice, $user)->success)->toBeTrue();
    $this->assertDatabaseCount('invoices', 1);
});

it('configures the topup switch with fresh permission checks confirmation and financial audit', function (): void {
    $actor = User::factory()->create();
    foreach (['finance.view', 'finance.wallet.configure'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $actor->givePermissionTo($permission);
    }
    $this->actingAs($actor)->post('/admin/finance/wallet-topup-settings', ['enabled' => '0', 'reason' => 'Disable topups after reconciliation', 'confirm' => '1'])
        ->assertRedirect('/admin/finance');
    expect(app(ConfigureWalletTopupAction::class)->enabled())->toBeFalse()
        ->and(DB::table('audit_logs')->where('action', 'wallet.topup.configure')->value('actor_id'))->toBe($actor->id);
    $this->post('/admin/finance/wallet-topup-settings', ['enabled' => '1', 'reason' => 'Enable without confirmation'])
        ->assertSessionHasErrors('confirm');
    expect(app(ConfigureWalletTopupAction::class)->enabled())->toBeFalse();
    // Warm the caller's role/permission relation, then revoke externally; the Action must use its locked fresh actor.
    expect($actor->can('finance.wallet.configure'))->toBeTrue();
    DB::table('model_has_permissions')->where('model_id', $actor->id)->where('permission_id', Permission::findByName('finance.wallet.configure')->id)->delete();
    expect(fn () => app(ConfigureWalletTopupAction::class)->execute($actor, true, 'Revoked actor attempt'))
        ->toThrow(AuthorizationException::class);
    expect(app(ConfigureWalletTopupAction::class)->enabled())->toBeFalse()
        ->and(DB::table('audit_logs')->where('action', 'wallet.topup.configure')->count())->toBe(1);
});
