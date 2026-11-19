<?php

declare(strict_types=1);

use App\Actions\ConfigureEpayAction;
use App\Actions\InitiatePaymentAction;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentCallbackOutcome;
use App\Models\FulfillmentTask;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\PaymentCallback;
use App\Models\User;
use App\Services\SettingService;
use App\Support\EpayProfiles;
use App\Support\PaymentChannels;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Http::preventStrayRequests();
    Queue::fake();
});

function adminPaymentSettingsOperator(array $permissions = ['settings.view', 'settings.payment.manage']): User
{
    $actor = User::factory()->create();
    foreach ($permissions as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $actor;
}

function adminPaymentSettingsReceipt(string $outTradeNo): array
{
    $payload = ['pid' => 'old-merchant', 'type' => 'wxpay', 'out_trade_no' => $outTradeNo,
        'trade_no' => 'OLD-CHANNEL-TRADE', 'money' => '10.00', 'trade_status' => 'TRADE_SUCCESS'];
    ksort($payload, SORT_STRING);
    $payload['sign'] = md5(collect($payload)->map(fn ($value, $name) => $name.'='.$value)->implode('&').'OLD-EPAY-KEY-SENTINEL');
    $payload['sign_type'] = 'MD5';

    return $payload;
}

it('requires payment configuration authority as well as settings access for a web actor', function (array $permissions): void {
    $actor = adminPaymentSettingsOperator($permissions);
    expect(fn () => app(ConfigureEpayAction::class)->execute('https://epay-settings.test', 'merchant', 'KEY-SENTINEL', 'Save settings', $actor))
        ->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('system_settings', 0);
    $this->assertDatabaseCount('audit_logs', 0);
    Http::assertNothingSent();
})->with([
    'viewer only' => [['settings.view']],
    'manage without panel access' => [['settings.payment.manage']],
    'ordinary account' => [[]],
]);

it('rechecks payment settings authority and account state from the locked actor', function (string $change): void {
    $actor = adminPaymentSettingsOperator();
    expect($actor->can('settings.payment.manage'))->toBeTrue();
    if ($change === 'permission') {
        User::findOrFail($actor->id)->revokePermissionTo('settings.payment.manage');
    } else {
        DB::table('users')->where('id', $actor->id)->update([$change => now()]);
    }
    expect(fn () => app(ConfigureEpayAction::class)->execute('https://epay-settings.test', 'merchant', 'KEY-SENTINEL', 'Save settings', $actor))
        ->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('system_settings', 0);
    $this->assertDatabaseCount('audit_logs', 0);
})->with(['permission', 'deleted_at', 'anonymized_at']);

it('preserves blank web merchant keys byte for byte without making another credential profile', function (): void {
    $configure = app(ConfigureEpayAction::class);
    $configure->execute('https://epay-settings.test', 'merchant', 'EPAY-SECRET-SENTINEL', 'Initial CLI configuration');
    $profileId = app(EpayProfiles::class)->currentId();
    $ciphertext = DB::table('system_settings')->where('group', 'payment')->where('key', 'epay_key')->value('value');
    $actor = adminPaymentSettingsOperator();
    $configure->execute('https://epay-settings.test/', 'merchant', '', '保存支付配置', $actor,
        ['epay_wechat' => false, 'epay_alipay' => true]);
    expect(DB::table('system_settings')->where('group', 'payment')->where('key', 'epay_key')->value('value'))->toBe($ciphertext)
        ->and(app(EpayProfiles::class)->currentId())->toBe($profileId)
        ->and(DB::table('system_settings')->where('group', 'payment')->where('key', 'like', EpayProfiles::PROFILE_PREFIX.'%')->where('is_encrypted', true)->count())->toBe(1)
        ->and(app(PaymentChannels::class)->available())->toBe(['epay_alipay' => '支付宝']);
    $audit = DB::table('audit_logs')->where('actor_id', $actor->id)->sole();
    $after = json_decode($audit->after, true, flags: JSON_THROW_ON_ERROR);
    expect($audit->actor_type)->toBe('user')->and($audit->subject_type)->toBe('system_settings')
        ->and($audit->reason)->toBe('保存支付配置')->and($audit->request_id)->toBeString()->not->toBe('')
        ->and($after['keys'])->toBe(['payment.epay_api_url', 'payment.epay_pid', 'payment.epay_wechat_enabled', 'payment.epay_alipay_enabled'])
        ->and($after['profile_id'])->toBe($profileId)
        ->and($audit->after)->not->toContain('EPAY-SECRET-SENTINEL')
        ->and(DB::table('system_settings')->pluck('value')->implode(' '))->not->toContain('EPAY-SECRET-SENTINEL');
    Http::assertNothingSent();
});

it('requires the first merchant key while retaining the trusted CLI nonblank contract', function (): void {
    $actor = adminPaymentSettingsOperator();
    expect(fn () => app(ConfigureEpayAction::class)->execute('https://epay-settings.test', 'merchant', '', 'Initial configuration', $actor))
        ->toThrow(DomainException::class, 'first payment configuration');
    expect(fn () => app(ConfigureEpayAction::class)->execute('https://epay-settings.test', 'merchant', '', 'Initial CLI configuration'))
        ->toThrow(DomainException::class);
    $this->assertDatabaseCount('system_settings', 0);
    $this->assertDatabaseCount('audit_logs', 0);
});

it('captures a necessary baseline profile when a web save keeps complete legacy merchant credentials', function (): void {
    $ciphertext = Crypt::encryptString('LEGACY-KEY-SENTINEL');
    foreach (['epay_api_url' => 'https://epay-settings.test', 'epay_pid' => 'merchant', 'epay_key' => $ciphertext] as $key => $value) {
        DB::table('system_settings')->insert(['group' => 'payment', 'key' => $key, 'value' => $value, 'is_encrypted' => $key === 'epay_key']);
    }
    $actor = adminPaymentSettingsOperator();
    app(ConfigureEpayAction::class)->execute('https://epay-settings.test', 'merchant', '', 'Archive legacy merchant', $actor);
    $id = app(EpayProfiles::class)->currentId();
    expect($id)->toBeString()->and(app(EpayProfiles::class)->find($id)['key'])->toBe('LEGACY-KEY-SENTINEL')
        ->and(DB::table('system_settings')->where('group', 'payment')->where('key', 'epay_key')->value('value'))->toBe($ciphertext);
    app(ConfigureEpayAction::class)->execute('https://epay-settings.test', 'merchant', '', 'Unchanged web save', $actor);
    expect(app(EpayProfiles::class)->currentId())->toBe($id)
        ->and(DB::table('system_settings')->where('group', 'payment')->where('key', 'like', EpayProfiles::PROFILE_PREFIX.'%')->where('is_encrypted', true)->count())->toBe(1);
});

it('rejects unknown channel keys and untyped channel values before changing any settings', function (array $channels): void {
    expect(fn () => app(ConfigureEpayAction::class)->execute('https://epay-settings.test', 'merchant', 'KEY-SENTINEL', 'Save settings', adminPaymentSettingsOperator(), $channels))
        ->toThrow(DomainException::class, 'supported payment channels');
    $this->assertDatabaseCount('system_settings', 0);
    $this->assertDatabaseCount('audit_logs', 0);
})->with([
    'profile injection' => [['epay_profile_id' => true]],
    'arbitrary channel' => [['unknown' => true]],
    'string boolean' => [['epay_wechat' => 'false']],
    'array boolean' => [['epay_wechat' => [true]]],
]);

it('checks channel availability freshly before starting an attempt despite a cached enabled value', function (): void {
    app(ConfigureEpayAction::class)->execute('https://epay-settings.test', 'merchant', 'KEY-SENTINEL', 'Initial configuration');
    expect(app(PaymentChannels::class)->enabled('epay_wechat'))->toBeTrue();
    DB::table('system_settings')->insert(['group' => 'payment', 'key' => 'epay_wechat_enabled', 'value' => '0', 'is_encrypted' => false]);
    expect(app(SettingService::class)->getBool('payment.epay_wechat_enabled', true))->toBeTrue();
    $invoice = Invoice::factory()->create(['gateway' => 'epay_wechat']);
    expect(fn () => app(InitiatePaymentAction::class)->execute($invoice, $invoice->user))->toThrow(ValidationException::class);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::UNPAID);
    $this->assertDatabaseCount('payment_attempts', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
    Http::assertNothingSent();
});

it('still verifies and deduplicates an old merchant payment after disabling its channel and changing merchants', function (): void {
    $configure = app(ConfigureEpayAction::class);
    $configure->execute('https://old-epay-settings.test', 'old-merchant', 'OLD-EPAY-KEY-SENTINEL', 'Initial configuration');
    Http::fake(['https://old-epay-settings.test/*' => Http::response(['code' => 1, 'payurl' => 'https://old-epay-settings.test/pay'])]);
    $invoice = Invoice::factory()->create(['gateway' => 'epay_wechat']);
    $result = app(InitiatePaymentAction::class)->execute($invoice, $invoice->user);
    $profileId = PaymentAttempt::sole()->request_payload[EpayProfiles::ATTEMPT_KEY];
    $configure->execute('https://new-epay-settings.test', 'new-merchant', 'NEW-EPAY-KEY-SENTINEL', 'Switch merchant and disable WeChat', adminPaymentSettingsOperator(),
        ['epay_wechat' => false, 'epay_alipay' => true]);
    expect(fn () => app(InitiatePaymentAction::class)->execute($invoice, $invoice->user))->toThrow(ValidationException::class);
    $payload = adminPaymentSettingsReceipt($result->outTradeNo);
    $this->postJson(route('payments.notify', ['gateway' => 'epay_wechat']), $payload)->assertOk()->assertContent('success');
    $this->postJson(route('payments.notify', ['gateway' => 'epay_wechat']), $payload)->assertOk()->assertContent('success');
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and(PaymentAttempt::sole()->status)->toBe('paid')
        ->and(PaymentAttempt::sole()->request_payload[EpayProfiles::ATTEMPT_KEY])->toBe($profileId)
        ->and(PaymentCallback::sole()->outcome)->toBe(PaymentCallbackOutcome::PROCESSED_PAID)
        ->and(FulfillmentTask::count())->toBe(1);
    Http::assertSentCount(1);
});

it('rolls back payment credentials profiles and channel changes together if administrator audit fails', function (): void {
    app(ConfigureEpayAction::class)->execute('https://epay-settings.test', 'merchant', 'KEY-SENTINEL', 'Initial configuration');
    $before = DB::table('system_settings')->orderBy('id')->get()->toJson();
    DB::unprepared("CREATE TRIGGER reject_admin_payment_audit BEFORE INSERT ON audit_logs WHEN NEW.actor_id IS NOT NULL AND NEW.action = 'payment.epay_configure' BEGIN SELECT RAISE(ABORT, 'Payment configuration audit unavailable'); END");
    expect(fn () => app(ConfigureEpayAction::class)->execute('https://new-epay-settings.test', 'new-merchant', 'NEW-KEY-SENTINEL', 'Change configuration', adminPaymentSettingsOperator(),
        ['epay_wechat' => false, 'epay_alipay' => false]))->toThrow(QueryException::class, 'Payment configuration audit unavailable');
    expect(DB::table('system_settings')->orderBy('id')->get()->toJson())->toBe($before)
        ->and(app(PaymentChannels::class)->available())->toBe(['epay_wechat' => '微信', 'epay_alipay' => '支付宝']);
});

it('retains both payment channels by default and never admits an unsupported channel', function (): void {
    expect(app(PaymentChannels::class)->available())->toBe(['epay_wechat' => '微信', 'epay_alipay' => '支付宝'])
        ->and(app(PaymentChannels::class)->enabled('unknown'))->toBeFalse();
    expect(fn () => app(PaymentChannels::class)->assertEnabled('unknown'))->toThrow(ValidationException::class);
});
