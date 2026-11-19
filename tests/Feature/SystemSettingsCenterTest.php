<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Actions\ConfigureEpayAction;
use App\Actions\ConfigureIdentityAction;
use App\Actions\ConfigureWalletTopupAction;
use App\Actions\CreateComboInvoiceAction;
use App\Actions\CreateWalletTopupInvoiceAction;
use App\Drivers\Notification\LogMailDriver;
use App\Enums\BillingCycle;
use App\Filament\Pages\SystemSettings;
use App\Models\Product;
use App\Models\User;
use App\Models\UserNotificationBinding;
use App\Support\PaymentChannels;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Http::preventStrayRequests();
    Queue::fake();
});

function systemSettingsOperator(string $role): User
{
    $actor = User::factory()->create();
    app(AssignAdminRoleAction::class)->execute($actor, $role, 'Isolated settings role');

    return $actor;
}

it('authenticates settings pages saves and probes before their business authorization', function (): void {
    $this->get('/admin/settings')->assertRedirect('/admin/login');
    foreach (['identity/mail', 'payment', 'test/mail'] as $path) {
        $this->post('/admin/settings/'.$path)->assertRedirect('/admin/login');
    }
    $customer = User::factory()->create();
    $this->actingAs($customer)->get('/admin/settings')->assertForbidden();
    foreach (['identity/mail', 'payment', 'test/mail'] as $path) {
        $this->post('/admin/settings/'.$path)->assertForbidden();
    }
    Http::assertNothingSent();
});

it('admits settings-only administrators and projects secrets out of HTML and Livewire snapshots', function (): void {
    $viewer = systemSettingsOperator('settings-viewer');
    app(ConfigureIdentityAction::class)->execute(['mail.username' => 'PRIVATE-SMTP-ACCOUNT', 'mail.password' => 'PRIVATE-SMTP-PASSWORD'], 'Secret fixture');
    $this->actingAs($viewer)->get('/admin/settings?tab=mail')->assertOk()
        ->assertSee('系统配置')->assertSee('已配置')->assertDontSee('PRIVATE-SMTP')
        ->assertDontSee('action="/admin/settings/identity/mail"', false);
    Livewire::test(SystemSettings::class)->set('tab', 'mail')->assertDontSee('PRIVATE-SMTP');
    $snapshot = Livewire::test(SystemSettings::class)->set('tab', 'mail')->snapshot;
    expect(json_encode($snapshot, JSON_THROW_ON_ERROR))->not->toContain('PRIVATE-SMTP');
    expect($viewer->canAccessPanel(Filament\Facades\Filament::getPanel('admin')))->toBeTrue();
    $this->post('/admin/settings/identity/mail', ['settings' => ['mail.transport' => 'log']])->assertForbidden();
    $this->post('/admin/settings/test/mail')->assertForbidden();
    Http::assertNothingSent();
});

it('keeps configuration domains separate and rejects foreign setting keys atomically', function (): void {
    $operator = systemSettingsOperator('settings-notification');
    $this->actingAs($operator)->post('/admin/settings/identity/github', ['settings' => ['oauth.github_enabled' => false]])->assertForbidden();
    $this->post('/admin/settings/payment')->assertForbidden();
    $this->from('/admin/settings?tab=mail')->post('/admin/settings/identity/mail', ['settings' => [
        'mail.transport' => 'log', 'payment.epay_key' => 'PRIVATE-INJECTED-KEY',
    ]])->assertRedirect('/admin/settings?tab=mail')->assertSessionHasErrors('settings');
    $this->assertDatabaseMissing('system_settings', ['group' => 'mail', 'key' => 'transport']);
    $this->assertDatabaseMissing('system_settings', ['group' => 'payment', 'key' => 'epay_key']);
    expect(json_encode(session('_old_input'), JSON_THROW_ON_ERROR))->not->toContain('PRIVATE-INJECTED-KEY');
});

it('saves notification settings with optional notes and never flashes failed secret input', function (): void {
    $operator = systemSettingsOperator('settings-notification');
    $this->actingAs($operator)->post('/admin/settings/identity/mail', ['settings' => [
        'mail.transport' => 'smtp', 'mail.host' => 'smtp.example.test', 'mail.port' => '465',
        'mail.username' => 'PRIVATE-SMTP-USERNAME', 'mail.password' => 'PRIVATE-SMTP-PASSWORD',
        'mail.from_address' => 'sender@example.test', 'mail.from_name' => '测试主机',
    ]])->assertRedirect('/admin/settings?tab=mail')->assertSessionHasNoErrors();
    $cipher = DB::table('system_settings')->where('group', 'mail')->where('key', 'password')->value('value');
    expect(Crypt::decryptString($cipher))->toBe('PRIVATE-SMTP-PASSWORD');
    $audit = DB::table('audit_logs')->where('action', 'identity.configure')->latest('id')->first();
    expect($audit->actor_id)->toBe($operator->id)->and($audit->actor_type)->toBe('user')
        ->and($audit->reason)->not->toBe('')->and($audit->ip)->toBe('127.0.0.1')->and($audit->request_id)->not->toBeNull()
        ->and($audit->after)->not->toContain('PRIVATE-SMTP');
    $this->post('/admin/settings/identity/mail', ['settings' => ['mail.password' => '', 'mail.username' => '', 'mail.from_name' => '']])
        ->assertSessionHasNoErrors();
    expect(DB::table('system_settings')->where('group', 'mail')->where('key', 'password')->value('value'))->toBe($cipher);
    $this->from('/admin/settings?tab=mail')->post('/admin/settings/identity/mail', ['settings' => [
        'mail.password' => 'PRIVATE-FAILED-SECRET', 'mail.port' => '99999',
    ]])->assertRedirect('/admin/settings?tab=mail')->assertSessionHasErrors();
    expect(session('_old_input.settings'))->toBeNull();
    $this->get('/admin/settings?tab=mail')->assertOk()->assertDontSee('PRIVATE-FAILED-SECRET')->assertDontSee('PRIVATE-SMTP');
    Http::assertNothingSent();
});

it('accepts structured SMS templates while rejecting malformed JSON without leaking its content', function (): void {
    $operator = systemSettingsOperator('settings-notification');
    $templates = ['system.settings_test' => ['id' => 'T100', 'content' => '@var(message)']];
    $this->actingAs($operator)->post('/admin/settings/identity/sms', ['settings' => [
        'sms.driver' => 'log', 'sms.idcsmart_templates' => json_encode($templates, JSON_THROW_ON_ERROR),
    ]])->assertSessionHasNoErrors();
    expect(json_decode(DB::table('system_settings')->where('group', 'sms')->where('key', 'idcsmart_templates')->value('value'), true))->toBe($templates);
    $this->from('/admin/settings?tab=sms')->post('/admin/settings/identity/sms', ['settings' => ['sms.idcsmart_templates' => '{PRIVATE-INVALID-JSON']])
        ->assertSessionHasErrors('settings');
    expect(session('_old_input.settings'))->toBeNull();
    $this->get('/admin/settings?tab=sms')->assertDontSee('PRIVATE-INVALID-JSON');
});

it('retains the current Epay secret on a blank Web save and keeps new channel controls separate from profiles', function (): void {
    $operator = systemSettingsOperator('settings-payment');
    app(ConfigureEpayAction::class)->execute('https://pay.example.test', '1000', 'PRIVATE-EPAY-SECRET', 'Original merchant');
    $cipher = DB::table('system_settings')->where('group', 'payment')->where('key', 'epay_key')->value('value');
    $profiles = DB::table('system_settings')->where('group', 'payment')->where('key', 'like', 'epay_profile_%')->count();
    $this->actingAs($operator)->post('/admin/settings/payment', ['url' => 'https://pay.example.test', 'pid' => '1000',
        'epay_key' => '', 'alipay' => '1', 'wechat' => '0'])->assertSessionHasNoErrors();
    expect(DB::table('system_settings')->where('group', 'payment')->where('key', 'epay_key')->value('value'))->toBe($cipher)
        ->and(DB::table('system_settings')->where('group', 'payment')->where('key', 'like', 'epay_profile_%')->count())->toBe($profiles);
    $this->get('/admin/settings')->assertOk()->assertDontSee('PRIVATE-EPAY-SECRET');
    expect(app(PaymentChannels::class)->available())->toBe(['epay_alipay' => '支付宝']);
    $this->from('/admin/settings')->post('/admin/settings/payment', ['url' => 'not-a-url', 'pid' => '1000',
        'epay_key' => 'PRIVATE-FAILED-EPAY', 'alipay' => '1', 'wechat' => '1'])->assertSessionHasErrors();
    expect(session('_old_input.epay_key'))->toBeNull();
    Http::assertNothingSent();
});

it('sends one test email only to the operators verified email outside the admission transaction', function (): void {
    $operator = systemSettingsOperator('settings-notification');
    app(ConfigureIdentityAction::class)->execute(['mail.transport' => 'smtp', 'mail.host' => 'smtp.example.test', 'mail.from_address' => 'sender@example.test'], 'SMTP fixture');
    $mailer = (new MailManager(app()))->build(['transport' => 'array']);
    Mail::shouldReceive('build')->once()->andReturnUsing(function () use ($mailer) {
        // RefreshDatabase owns one outer test transaction; the Action's short admission has ended.
        expect(DB::transactionLevel())->toBe(1);

        return $mailer;
    });
    $this->actingAs($operator)->post('/admin/settings/test/mail', ['recipient' => 'other@example.test'])
        ->assertRedirect('/admin/settings?tab=mail')->assertSessionHas('inventory_status', '测试已提交发送，请检查本人接收账户。');
    $messages = $mailer->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1)->and($messages->sole()->getOriginalMessage()->getTo()[0]->getAddress())->toBe($operator->email);
    Queue::assertNothingPushed();
});

it('requires verified own targets and reports log probes as simulations', function (): void {
    $operator = systemSettingsOperator('settings-notification');
    $operator->forceFill(['email_verified_at' => null])->save();
    $this->actingAs($operator)->post('/admin/settings/test/mail')->assertSessionHasErrors('test');
    $this->post('/admin/settings/test/sms', ['phone' => '+8613800000000'])->assertSessionHasErrors('test');
    $operator->forceFill(['email_verified_at' => now()])->save();
    $this->post('/admin/settings/test/mail')->assertSessionHas('inventory_status', '当前为日志通道，已模拟测试，没有真实发送。');
    Http::assertNothingSent();
});

it('sends a single SMS test using the dedicated template and the operators verified binding', function (): void {
    $operator = systemSettingsOperator('settings-notification');
    $operator->update(['phone' => '+8613800000000']);
    UserNotificationBinding::create(['user_id' => $operator->id, 'channel' => 'sms', 'target' => $operator->phone, 'verified_at' => now()]);
    app(ConfigureIdentityAction::class)->execute(['sms.driver' => 'idcsmart', 'sms.idcsmart_endpoint' => 'https://sms.example.test/send.php',
        'sms.idcsmart_api' => 'PRIVATE-SMS-API', 'sms.idcsmart_key' => 'PRIVATE-SMS-KEY', 'sms.idcsmart_sign' => '测试主机',
        'sms.idcsmart_templates' => ['system.settings_test' => ['id' => 'T100', 'content' => '@var(message)']]], 'SMS fixture');
    Http::fake(['sms.example.test/*' => function ($request) {
        expect(DB::transactionLevel())->toBe(1)->and($request['to'])->toBe('13800000000')
            ->and($request['template_id'])->toBe('T100')->and($request['content'])->toBe('【测试主机】系统配置测试');

        return Http::response(['status' => 200]);
    }]);
    $this->actingAs($operator)->post('/admin/settings/test/sms', ['recipient' => '+8613900000000'])->assertSessionHasNoErrors();
    Http::assertSentCount(1);
    $audit = DB::table('audit_logs')->where('action', 'settings.connection_test')->sole();
    expect($audit->after)->toBe('{"channel":"sms","status":"sent"}')->not->toContain('PRIVATE-', '13800000000');
});

it('does not retry uncertain mail delivery or expose a transport transcript', function (): void {
    $operator = systemSettingsOperator('settings-notification');
    $driver = Mockery::mock(LogMailDriver::class);
    $driver->shouldReceive('send')->once()->andThrow(new TransportException('PRIVATE-SMTP-TRANSCRIPT'));
    app()->instance(LogMailDriver::class, $driver);
    $this->actingAs($operator)->post('/admin/settings/test/mail')->assertSessionHasNoErrors()
        ->assertSessionHas('inventory_status', '发送结果不明，请先检查接收账户或平台记录，系统不会自动重发。');
    $this->get('/admin/settings?tab=mail')->assertDontSee('PRIVATE-SMTP-TRANSCRIPT');
    expect(DB::table('audit_logs')->where('action', 'settings.connection_test')->sole()->after)->toContain('uncertain')->not->toContain('PRIVATE-');
    Queue::assertNothingPushed();
});

it('checks Telegram identity once without sending a message or reflecting the saved bot token', function (): void {
    $operator = systemSettingsOperator('settings-identity');
    $token = '123456:'.str_repeat('t', 35);
    app(ConfigureIdentityAction::class)->execute(['telegram.bot_token' => $token, 'telegram.bot_username' => 'SettingsTestBot'], 'Bot fixture');
    Http::fake(['api.telegram.org/*/getMe' => Http::response(['ok' => true,
        'result' => ['is_bot' => true, 'username' => 'SettingsTestBot', 'PRIVATE-FIELD' => 'PRIVATE-BOT-DATA']])]);
    $this->actingAs($operator)->get('/admin/settings?tab=telegram')->assertOk()->assertDontSee($token);
    Http::assertNothingSent();
    $this->post('/admin/settings/test/telegram')->assertSessionHas('inventory_status', 'Telegram 连接成功：@SettingsTestBot');
    Http::assertSentCount(1);
    $this->get('/admin/settings?tab=telegram')->assertDontSee($token)->assertDontSee('PRIVATE-BOT-DATA');
    expect(DB::table('audit_logs')->where('action', 'settings.connection_test')->sole()->after)->not->toContain($token, 'PRIVATE-');
});

it('hides Telegram transport errors containing the token-bearing request URL', function (): void {
    $operator = systemSettingsOperator('settings-identity');
    $token = '123456:'.str_repeat('x', 35);
    app(ConfigureIdentityAction::class)->execute(['telegram.bot_token' => $token], 'Bot fixture');
    $calls = 0;
    Http::fake(function () use ($token, &$calls) {
        $calls++;
        throw new ConnectionException('PRIVATE-TOKEN-URL '.$token);
    });
    $this->actingAs($operator)->post('/admin/settings/test/telegram')->assertSessionHasNoErrors()
        ->assertSessionHas('inventory_status', '本次连接失败，可稍后手动重试。');
    expect($calls)->toBe(1);
    expect(DB::table('audit_logs')->where('action', 'settings.connection_test')->sole()->after)->not->toContain($token, 'PRIVATE-');
});

it('limits explicit probe requests independently of normal settings saves', function (): void {
    $operator = systemSettingsOperator('settings-notification');
    $this->actingAs($operator);
    for ($i = 0; $i < 5; $i++) {
        $this->post('/admin/settings/test/mail')->assertRedirect();
    }
    $this->post('/admin/settings/test/mail')->assertStatus(429);
    expect(DB::table('audit_logs')->where('action', 'settings.connection_test')->count())->toBe(5);
    $this->post('/admin/settings/identity/mail', ['settings' => ['mail.transport' => 'log']])->assertSessionHasNoErrors();
});

it('applies channel controls to new invoices and HTML while preserving old request replay and wallet-only purchases', function (): void {
    $operator = systemSettingsOperator('settings-payment');
    $customer = User::factory()->create(['credit' => '50.00']);
    app(ConfigureEpayAction::class)->execute('https://pay.example.test', '1000', 'PRIVATE-PAYMENT-KEY', 'Merchant fixture');
    $product = Product::factory()->create(['local_prices' => ['onetime' => ['price' => '10.00', 'setup_fee' => '0.00', 'enabled' => true]], 'provisioning_rules' => []]);
    $requestId = (string) Str::ulid();
    $original = app(CreateComboInvoiceAction::class)->execute($customer, $product, BillingCycle::ONETIME, [], false, 'epay_wechat', ['request_id' => $requestId]);
    app(ConfigureEpayAction::class)->execute('https://pay.example.test', '1000', '', 'Pause channels', $operator, ['epay_wechat' => false, 'epay_alipay' => false]);
    $replay = app(CreateComboInvoiceAction::class)->execute($customer, $product, BillingCycle::ONETIME, [], false, 'epay_wechat', ['request_id' => $requestId]);
    expect($replay->id)->toBe($original->id);
    expect(fn () => app(CreateComboInvoiceAction::class)->execute($customer, $product, BillingCycle::ONETIME, [], false, 'epay_wechat', ['request_id' => (string) Str::ulid()]))
        ->toThrow(ValidationException::class);
    $paid = app(CreateComboInvoiceAction::class)->execute($customer, $product, BillingCycle::ONETIME, [], true, null, ['request_id' => (string) Str::ulid()]);
    expect($paid->gateway_amount)->toBe('0.00')->and($customer->fresh()->credit)->toBe('40.00');
    $this->actingAs($customer)->get('/console')->assertOk()->assertDontSee('value="epay_wechat"', false)->assertDontSee('value="epay_alipay"', false);
    $this->get('/console/invoices/'.$original->id)->assertSee('此账单的支付渠道已暂停')->assertDontSee('继续支付');
});

it('blocks new topups through disabled channels while retaining the original topup request', function (): void {
    $walletOperator = systemSettingsOperator('finance-wallet-settings');
    $customer = User::factory()->create();
    $paymentOperator = systemSettingsOperator('settings-payment');
    app(ConfigureEpayAction::class)->execute('https://pay.example.test', '1000', 'PRIVATE-TOPUP-KEY', 'Merchant fixture');
    app(ConfigureWalletTopupAction::class)->execute($walletOperator, true, 'Enable fixture topups');
    $input = ['request_id' => (string) Str::ulid(), 'amount' => '10.00', 'gateway' => 'epay_wechat'];
    $invoice = app(CreateWalletTopupInvoiceAction::class)->execute($customer, $input);
    app(ConfigureEpayAction::class)->execute('https://pay.example.test', '1000', '', 'Pause WeChat', $paymentOperator, ['epay_wechat' => false]);
    expect(app(CreateWalletTopupInvoiceAction::class)->execute($customer, $input)->id)->toBe($invoice->id);
    $this->actingAs($customer)->postJson('/console/wallet/topups', ['request_id' => (string) Str::ulid(), 'amount' => '10.00', 'gateway' => 'epay_wechat'])
        ->assertUnprocessable()->assertJsonValidationErrors('gateway');
    $this->get('/console/wallet')->assertOk()->assertDontSee('value="epay_wechat"', false)->assertSee('value="epay_alipay"', false);
});
