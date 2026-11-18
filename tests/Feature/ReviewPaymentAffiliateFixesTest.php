<?php

declare(strict_types=1);

use App\Actions\AccrueAffiliateCommissionAction;
use App\Actions\ConfigureEpayAction;
use App\Actions\InitiatePaymentAction;
use App\Actions\ReencryptSecretsAction;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentCallbackOutcome;
use App\Models\AffiliateCommission;
use App\Models\FinancialTransaction;
use App\Models\FulfillmentTask;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\PaymentCallback;
use App\Models\User;
use App\Services\SettingService;
use App\Support\EpayProfiles;
use App\Support\InstallationState;
use App\Support\Money;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Queue::fake();
    Http::preventStrayRequests();
});

function reviewAffiliateInvoice(string $amount = '0.01'): Invoice
{
    $referrer = User::factory()->create();

    return Invoice::factory()->paid()->create(['total_amount' => $amount, 'gateway_amount' => $amount,
        'line_items' => ['affiliate' => ['referrer_id' => $referrer->id, 'rate' => '10.00', 'freeze_days' => 7]]]);
}

it('stops recovering a paid invoice after its zero commission has been checked once', function (): void {
    $invoice = reviewAffiliateInvoice();
    $snapshot = $invoice->fresh()->line_items;
    $retrieved = 0;
    Invoice::retrieved(function (Invoice $candidate) use ($invoice, &$retrieved): void {
        if ($candidate->id === $invoice->id) {
            $retrieved++;
        }
    });
    try {
        expect(Artisan::call('affiliate:process'))->toBe(0)->and($retrieved)->toBeGreaterThan(0);
        $retrieved = 0;
        expect(Artisan::call('affiliate:process'))->toBe(0)->and($retrieved)->toBe(0);
    } finally {
        Invoice::flushEventListeners();
    }

    expect(AffiliateCommission::count())->toBe(0)->and(FinancialTransaction::count())->toBe(0)
        ->and($invoice->fresh()->line_items)->toBe($snapshot)
        ->and(DB::table('audit_logs')->where('action', AccrueAffiliateCommissionAction::ZERO_ACCRUAL_ACTION)->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('keeps duplicate zero accrual jobs idempotent without a zero commission or wallet change', function (): void {
    $invoice = reviewAffiliateInvoice();
    $before = User::orderBy('id')->get(['id', 'credit', 'frozen_credit', 'affiliate_balance'])->toArray();
    $action = app(AccrueAffiliateCommissionAction::class);

    expect($action->execute($invoice->id))->toBeNull()->and($action->execute($invoice->id))->toBeNull()
        ->and(AffiliateCommission::count())->toBe(0)->and(FinancialTransaction::count())->toBe(0)
        ->and(User::orderBy('id')->get(['id', 'credit', 'frozen_credit', 'affiliate_balance'])->toArray())->toBe($before);
    $audit = DB::table('audit_logs')->where('action', AccrueAffiliateCommissionAction::ZERO_ACCRUAL_ACTION)->sole();
    expect($audit->subject_type)->toBe(Invoice::class)->and((int) $audit->subject_id)->toBe($invoice->id)
        ->and(json_decode($audit->after, true, flags: JSON_THROW_ON_ERROR)['amount'])->toBe('0.00');
    Queue::assertNothingPushed();
});

it('preserves the half-up commission boundary and nonzero accrual recovery', function (string $amount, string $commission): void {
    $invoice = reviewAffiliateInvoice($amount);
    Artisan::call('affiliate:process');
    Artisan::call('affiliate:process');

    if ($commission === '0.00') {
        expect(AffiliateCommission::count())->toBe(0)
            ->and(DB::table('audit_logs')->where('action', AccrueAffiliateCommissionAction::ZERO_ACCRUAL_ACTION)->count())->toBe(1);
        Queue::assertNothingPushed();
    } else {
        $row = AffiliateCommission::sole();
        expect($row->invoice_id)->toBe($invoice->id)->and($row->amount)->toBe($commission)->and($row->status)->toBe('pending')
            ->and(DB::table('audit_logs')->where('action', AccrueAffiliateCommissionAction::ZERO_ACCRUAL_ACTION)->count())->toBe(0);
    }
    expect(FinancialTransaction::count())->toBe(0);
})->with([['0.04', '0.00'], ['0.05', '0.01']]);

function reviewEpayCallback(string $outTradeNo, string $tradeNo, string $key = 'old-payment-secret', string $pid = 'old-merchant', string $type = 'wxpay', array $extra = []): array
{
    $payload = ['pid' => $pid, 'type' => $type, 'out_trade_no' => $outTradeNo,
        'trade_no' => $tradeNo, 'money' => '10.00', 'trade_status' => 'TRADE_SUCCESS', ...$extra];
    ksort($payload, SORT_STRING);
    $payload['sign'] = md5(collect($payload)->map(fn ($value, $name) => $name.'='.$value)->implode('&').$key);
    $payload['sign_type'] = 'MD5';

    return $payload;
}

function reviewLegacyEpayConfiguration(): void
{
    foreach (['epay_api_url' => 'https://old-epay.test', 'epay_pid' => 'old-merchant', 'epay_key' => 'old-payment-secret'] as $key => $value) {
        DB::table('system_settings')->insert(['group' => 'payment', 'key' => $key,
            'value' => $key === 'epay_key' ? Crypt::encryptString($value) : $value, 'is_encrypted' => $key === 'epay_key']);
    }
}

it('accepts and deduplicates an in-flight payment using its original merchant after configuration changes', function (string $newMerchant): void {
    app(ConfigureEpayAction::class)->execute('https://old-epay.test', 'old-merchant', 'old-payment-secret', 'Initial test setup');
    Http::fake(['https://old-epay.test/*' => Http::response(['code' => 1, 'payurl' => 'https://old-epay.test/pay'])]);
    $invoice = Invoice::factory()->create(['gateway' => 'epay_wechat']);
    $result = app(InitiatePaymentAction::class)->execute($invoice, $invoice->user);
    $profileId = PaymentAttempt::sole()->request_payload[EpayProfiles::ATTEMPT_KEY];
    app(ConfigureEpayAction::class)->execute('https://new-epay.test', $newMerchant, 'new-payment-secret', 'Switch merchant');

    $payload = reviewEpayCallback($result->outTradeNo, 'OLD-MERCHANT-TRADE');
    $this->postJson(route('payments.notify', ['gateway' => 'epay_wechat']), $payload)->assertOk()->assertContent('success');
    $this->postJson(route('payments.notify', ['gateway' => 'epay_wechat']), $payload)->assertOk()->assertContent('success');
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and(PaymentAttempt::sole()->request_payload[EpayProfiles::ATTEMPT_KEY])->toBe($profileId)
        ->and(PaymentAttempt::sole()->status)->toBe('paid')
        ->and(PaymentCallback::sole()->outcome)->toBe(PaymentCallbackOutcome::PROCESSED_PAID)
        ->and(FulfillmentTask::count())->toBe(1);
    Http::assertSentCount(1);
})->with(['new-merchant', 'old-merchant']);

it('binds legacy attempts of every status to their old configuration before switching and preserves late credit handling', function (string $status): void {
    reviewLegacyEpayConfiguration();
    $invoice = Invoice::factory()->expired()->create(['gateway' => 'epay_wechat']);
    $attempt = PaymentAttempt::create(['invoice_id' => $invoice->id, 'gateway' => 'epay_wechat',
        'out_trade_no' => 'LEGACY-'.$status, 'amount' => '10.00', 'status' => $status, 'request_payload' => ['diagnostic' => 'preserve']]);
    $credit = $invoice->user->credit;
    app(ConfigureEpayAction::class)->execute('https://new-epay.test', 'new-merchant', 'new-payment-secret', 'Switch legacy merchant');

    $payload = $attempt->fresh()->request_payload;
    expect($payload['diagnostic'])->toBe('preserve')->and($payload[EpayProfiles::ATTEMPT_KEY])->toBeString()
        ->and(app(EpayProfiles::class)->find($payload[EpayProfiles::ATTEMPT_KEY])['pid'])->toBe('old-merchant');
    $this->postJson(route('payments.notify', ['gateway' => 'epay_wechat']), reviewEpayCallback($attempt->out_trade_no, 'LATE-'.$status))
        ->assertOk()->assertContent('success');
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::EXPIRED)
        ->and($invoice->user->fresh()->credit)->toBe(Money::add($credit, '10.00'))
        ->and(PaymentCallback::sole()->outcome)->toBe(PaymentCallbackOutcome::PROCESSED_LATE_CREDIT)
        ->and(FulfillmentTask::count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['created', 'failed', 'expired', 'paid']);

it('selects historical credentials only through an exact gateway and attempt mapping', function (): void {
    app(ConfigureEpayAction::class)->execute('https://old-epay.test', 'old-merchant', 'old-payment-secret', 'Initial test setup');
    Http::fake(['*' => Http::response(['code' => 1, 'payurl' => 'https://old-epay.test/pay'])]);
    $invoice = Invoice::factory()->create(['gateway' => 'epay_wechat']);
    $result = app(InitiatePaymentAction::class)->execute($invoice, $invoice->user);
    $oldProfileId = PaymentAttempt::sole()->request_payload[EpayProfiles::ATTEMPT_KEY];
    app(ConfigureEpayAction::class)->execute('https://new-epay.test', 'new-merchant', 'new-payment-secret', 'Switch merchant');

    // A valid current signature cannot pay an attempt created for a different merchant.
    $this->postJson(route('payments.notify', ['gateway' => 'epay_wechat']),
        reviewEpayCallback($result->outTradeNo, 'WRONG-MERCHANT', 'new-payment-secret', 'new-merchant'))->assertUnprocessable();
    $this->postJson(route('payments.notify', ['gateway' => 'epay_alipay']),
        reviewEpayCallback($result->outTradeNo, 'WRONG-CHANNEL', type: 'alipay'))->assertUnprocessable();
    $this->postJson(route('payments.notify', ['gateway' => 'epay_wechat']),
        reviewEpayCallback($result->outTradeNo.'1', 'GUESSED-SUFFIX'))->assertUnprocessable();
    $forged = reviewEpayCallback('UNKNOWN-OLD-ORDER', 'UNKNOWN-OLD-TRADE', extra: [EpayProfiles::ATTEMPT_KEY => $oldProfileId]);
    $this->postJson(route('payments.notify', ['gateway' => 'epay_wechat']), $forged)->assertUnprocessable();
    expect(PaymentCallback::count())->toBe(0)->and(FinancialTransaction::count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::UNPAID);

    // Signed unknown orders from the current merchant retain the established ACK and diagnostic record.
    $this->postJson(route('payments.notify', ['gateway' => 'epay_wechat']),
        reviewEpayCallback('UNKNOWN-CURRENT-ORDER', 'UNKNOWN-CURRENT-TRADE', 'new-payment-secret', 'new-merchant'))
        ->assertOk()->assertContent('success');
    expect(PaymentCallback::sole()->outcome)->toBe(PaymentCallbackOutcome::UNKNOWN_INVOICE);
});

it('keeps the captured merchant for HTTP even if configuration changes after the attempt is committed', function (): void {
    app(ConfigureEpayAction::class)->execute('https://old-epay.test', 'old-merchant', 'old-payment-secret', 'Initial test setup');
    $invoice = Invoice::factory()->create(['gateway' => 'epay_wechat']);
    $baseTransactionLevel = DB::transactionLevel();
    Http::fake(function (ClientRequest $request) use ($baseTransactionLevel) {
        expect(DB::transactionLevel())->toBe($baseTransactionLevel)
            ->and(PaymentAttempt::where('out_trade_no', $request['out_trade_no'])->exists())->toBeTrue();
        app(ConfigureEpayAction::class)->execute('https://new-epay.test', 'new-merchant', 'new-payment-secret', 'Change during gateway request');
        expect($request->url())->toBe('https://old-epay.test/mapi.php')->and($request['pid'])->toBe('old-merchant');

        return Http::response(['code' => 1, 'payurl' => 'https://old-epay.test/pay']);
    });
    $result = app(InitiatePaymentAction::class)->execute($invoice, $invoice->user);
    $this->postJson(route('payments.notify', ['gateway' => 'epay_wechat']), reviewEpayCallback($result->outTradeNo, 'IN-FLIGHT-SWITCH'))
        ->assertOk()->assertContent('success');
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::PAID);
    Http::assertSentCount(1);
});

it('reuses an unchanged profile and keeps merchant secrets out of attempts audit logs and setting caches', function (): void {
    $configure = app(ConfigureEpayAction::class);
    $configure->execute('https://old-epay.test', 'old-merchant', 'old-payment-secret', 'Initial test setup');
    $profileId = app(EpayProfiles::class)->currentId();
    $configure->execute('https://old-epay.test/', 'old-merchant', 'old-payment-secret', 'Save unchanged configuration');
    expect(app(EpayProfiles::class)->currentId())->toBe($profileId)
        ->and(DB::table('system_settings')->where('group', 'payment')->where('is_encrypted', true)->count())->toBe(2);
    Http::fake(['*' => Http::response(['code' => 1, 'payurl' => 'https://old-epay.test/pay'])]);
    $invoice = Invoice::factory()->create(['gateway' => 'epay_wechat']);
    app(InitiatePaymentAction::class)->execute($invoice, $invoice->user);
    expect(app(SettingService::class)->get('payment.epay_key'))->toBe('old-payment-secret')
        ->and(DB::table('system_settings')->pluck('value')->implode(' '))->not->toContain('old-payment-secret')
        ->and(DB::table('payment_attempts')->value('request_payload'))->not->toContain('old-payment-secret')
        ->and(DB::table('audit_logs')->pluck('after')->implode(' '))->not->toContain('old-payment-secret')
        ->and(json_encode(Cache::get('setting:payment:epay_key'), JSON_THROW_ON_ERROR))->not->toContain('old-payment-secret');
});

it('rolls back merchant settings profiles and legacy bindings together when configuration audit fails', function (): void {
    reviewLegacyEpayConfiguration();
    $invoice = Invoice::factory()->create(['gateway' => 'epay_wechat']);
    $attempt = PaymentAttempt::create(['invoice_id' => $invoice->id, 'gateway' => 'epay_wechat',
        'out_trade_no' => 'ROLLBACK-LEGACY', 'amount' => '10.00', 'status' => 'created']);
    $settingsBefore = DB::table('system_settings')->orderBy('id')->get()->toJson();
    $failAudit = true;
    DB::listen(function ($query) use (&$failAudit): void {
        if ($failAudit && str_contains($query->sql, 'insert into') && str_contains($query->sql, 'audit_logs')) {
            throw new RuntimeException('Simulated configuration audit failure');
        }
    });
    try {
        expect(fn () => app(ConfigureEpayAction::class)->execute('https://new-epay.test', 'new-merchant', 'new-payment-secret', 'Switch test'))
            ->toThrow(RuntimeException::class, 'Simulated configuration audit failure');
    } finally {
        $failAudit = false;
    }
    expect(DB::table('system_settings')->orderBy('id')->get()->toJson())->toBe($settingsBefore)
        ->and($attempt->fresh()->request_payload)->toBeNull()->and(PaymentCallback::count())->toBe(0)
        ->and(DB::table('audit_logs')->count())->toBe(0);
});

it('refuses to invent the old merchant when legacy attempts exist without complete previous settings', function (): void {
    $invoice = Invoice::factory()->create(['gateway' => 'epay_wechat']);
    PaymentAttempt::create(['invoice_id' => $invoice->id, 'gateway' => 'epay_wechat',
        'out_trade_no' => 'UNEXPLAINED-LEGACY', 'amount' => '10.00', 'status' => 'failed']);
    expect(fn () => app(ConfigureEpayAction::class)->execute('https://new-epay.test', 'new-merchant', 'new-payment-secret', 'Switch test'))
        ->toThrow(DomainException::class, 'previous complete merchant configuration');
    expect(DB::table('system_settings')->count())->toBe(0)->and(PaymentAttempt::sole()->request_payload)->toBeNull();
});

it('rotates encrypted historical profiles with APP_KEY and still verifies their old merchant callbacks', function (): void {
    $oldAppKey = config('app.key');
    $newAppKey = 'base64:'.base64_encode(random_bytes(32));
    app(ConfigureEpayAction::class)->execute('https://old-epay.test', 'old-merchant', 'old-payment-secret', 'Initial test setup');
    Http::fake(['*' => Http::response(['code' => 1, 'payurl' => 'https://old-epay.test/pay'])]);
    $invoice = Invoice::factory()->create(['gateway' => 'epay_wechat']);
    $result = app(InitiatePaymentAction::class)->execute($invoice, $invoice->user);
    $oldProfileId = PaymentAttempt::sole()->request_payload[EpayProfiles::ATTEMPT_KEY];
    app(ConfigureEpayAction::class)->execute('https://new-epay.test', 'new-merchant', 'new-payment-secret', 'Switch merchant');
    $before = DB::table('system_settings')->where('is_encrypted', true)->orderBy('id')->pluck('value', 'key');
    $state = app(InstallationState::class);
    $state->write('installed.lock', $state->seal(['format' => 1, 'admin_id' => 1], $oldAppKey));
    File::ensureDirectoryExists(storage_path('framework'));
    File::put(storage_path('framework/down'), '{}');
    app(ReencryptSecretsAction::class)->execute($oldAppKey, $newAppKey, 'Isolated payment profile rotation', true);
    Crypt::swap($state->encrypter($newAppKey));
    config(['app.key' => $newAppKey]);
    File::delete(storage_path('framework/down'));

    foreach ($before as $key => $ciphertext) {
        expect(DB::table('system_settings')->where('group', 'payment')->where('key', $key)->value('value'))->not->toBe($ciphertext);
    }
    expect(app(EpayProfiles::class)->find($oldProfileId)['key'])->toBe('old-payment-secret')
        ->and(app(EpayProfiles::class)->currentCredentials()['key'])->toBe('new-payment-secret');
    $this->postJson(route('payments.notify', ['gateway' => 'epay_wechat']), reviewEpayCallback($result->outTradeNo, 'AFTER-APP-KEY-ROTATION'))
        ->assertOk()->assertContent('success');
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::PAID);
});
