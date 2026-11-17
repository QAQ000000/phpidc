<?php

declare(strict_types=1);

use App\Actions\ConfigureIdentityAction;
use App\Actions\PhoneBindingAction;
use App\Models\User;
use App\Models\UserNotificationBinding;
use App\Models\UserOauthAccount;
use App\Services\DriverManager;
use App\Services\SettingService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);
beforeEach(fn () => $this->installApplicationForTest());

function configureIdcsmartSms(): void
{
    app(ConfigureIdentityAction::class)->execute([
        'sms.driver' => 'idcsmart', 'sms.idcsmart_endpoint' => 'https://sms.example.test/smsapi.php',
        'sms.idcsmart_api' => 'PRIVATE-SMS-API', 'sms.idcsmart_key' => 'PRIVATE-SMS-KEY', 'sms.idcsmart_sign' => '【验收站】',
        'sms.binding_enabled' => true, 'sms.binding_template' => 'binding',
        'sms.idcsmart_templates' => ['binding' => ['id' => 'approved-id', 'content' => '验证码@var(code)，@var(minutes)分钟内有效']],
    ], 'Isolated verified plugin protocol');
}

function configureTelegramLogin(): string
{
    $token = '123456:'.str_repeat('A', 35);
    app(ConfigureIdentityAction::class)->execute(['telegram.bot_token' => $token, 'telegram.bot_username' => 'isolated_test_bot',
        'oauth.telegram_enabled' => true], 'Isolated Telegram signature protocol');

    return $token;
}

function telegramSignedPayload(string $token, array $overrides = []): array
{
    $data = array_merge(['id' => '123456789', 'first_name' => ' Test User ', 'auth_date' => (string) now()->timestamp], $overrides);
    ksort($data, SORT_STRING);
    $lines = [];
    foreach ($data as $key => $value) {
        $lines[] = $key.'='.$value;
    }
    $data['hash'] = hash_hmac('sha256', implode("\n", $lines), hash('sha256', $token, true));

    return $data;
}

it('sends the source-verified domestic SMS form without holding database locks and verifies only after the code is entered', function (): void {
    configureIdcsmartSms();
    $level = DB::transactionLevel();
    $code = null;
    Http::fake(function (Request $request) use ($level, &$code) {
        expect(DB::transactionLevel())->toBe($level)->and($request->url())->toBe('https://sms.example.test/smsapi.php?action=send')
            ->and($request->method())->toBe('POST')->and($request->header('api'))->toBe(['PRIVATE-SMS-API'])
            ->and($request->header('key'))->toBe(['PRIVATE-SMS-KEY'])
            ->and($request->header('Content-Type'))->toBe(['application/x-www-form-urlencoded'])
            ->and($request['to'])->toBe('13800138000')->and($request['template_id'])->toBe('approved-id');
        $code = $request['vars']['code'];
        expect($request['content'])->toBe('【验收站】验证码'.$code.'，10分钟内有效');

        return Http::response(['status' => 200]);
    });
    $user = User::factory()->create();
    $this->actingAs($user)->post('/console/account/phone/send', ['phone' => '+8613800138000'])->assertSessionHasNoErrors();
    expect($user->fresh()->phone)->toBeNull()->and(UserNotificationBinding::count())->toBe(0);
    $this->post('/console/account/phone/confirm', ['phone' => '+8613800138000', 'code' => $code])->assertSessionHasNoErrors();
    expect($user->fresh()->phone)->toBe('+8613800138000')->and(UserNotificationBinding::sole()->verified_at)->not->toBeNull();
    $this->post('/console/account/phone/confirm', ['phone' => '+8613800138000', 'code' => $code])->assertSessionHasErrors('code');
    Http::assertSentCount(1);
    expect(json_encode(DB::table('audit_logs')->get()))->not->toContain($code, 'PRIVATE-SMS-');
});

it('does not create a usable phone challenge or replay an SMS after provider rejection or uncertain delivery', function (string $failure): void {
    configureIdcsmartSms();
    Http::fake(match ($failure) {
        'connection' => ['*' => Http::failedConnection()],
        'business' => ['*' => Http::response(['status' => 400, 'msg' => 'PRIVATE-SUPPLIER-MESSAGE'])],
        'http' => ['*' => Http::response(['status' => 200], 500)],
        'redirect' => ['*' => Http::response('', 302, ['Location' => 'https://other.example.test'])],
        'malformed' => ['*' => Http::response('<html>PRIVATE-SUPPLIER-MESSAGE</html>')],
    });
    $user = User::factory()->create();
    $this->actingAs($user)->post('/console/account/phone/send', ['phone' => '+8613800138000'])->assertSessionHasErrors('phone');
    expect(Cache::has('phone-binding:'.$user->id))->toBeFalse()->and($user->fresh()->phone)->toBeNull();
    expect(json_encode(session('errors')->getMessages()))->not->toContain('PRIVATE-SUPPLIER');
    // Laravel does not record failedConnection as a sent response; successful transports are recorded once.
    if ($failure !== 'connection') {
        Http::assertSentCount(1);
    }
})->with(['connection', 'business', 'http', 'redirect', 'malformed']);

it('refuses unknown templates missing variables and unverified international SMS routes without sending', function (): void {
    configureIdcsmartSms();
    Http::fake();
    $driver = app(DriverManager::class)->sms();
    expect($driver->sendTemplate('+8613800138000', 'unknown', []))->toBeFalse()
        ->and($driver->sendTemplate('+8613800138000', 'binding', ['code' => '123456']))->toBeFalse()
        ->and($driver->sendTemplate('+12025550123', 'binding', ['code' => '123456', 'minutes' => '10']))->toBeFalse();
    Http::assertNothingSent();
});

it('encrypts provider secrets and rejects insecure endpoints and invalid templates before storing anything', function (): void {
    configureIdcsmartSms();
    $token = configureTelegramLogin();
    expect(app(SettingService::class)->get('telegram.bot_token'))->toBe($token);
    $rows = DB::table('system_settings')->where('is_encrypted', true)->get();
    expect($rows->count())->toBe(3)->and(json_encode($rows))->not->toContain($token, 'PRIVATE-SMS-API', 'PRIVATE-SMS-KEY');
    $before = DB::table('system_settings')->count();
    foreach (['http://sms.example.test/api', 'https://user:pass@sms.example.test/api', 'https://sms.example.test/api?key=secret'] as $url) {
        expect(fn () => app(ConfigureIdentityAction::class)->execute(['sms.idcsmart_endpoint' => $url], 'Invalid endpoint'))->toThrow(ValidationException::class);
    }
    expect(fn () => app(ConfigureIdentityAction::class)->execute(['sms.idcsmart_templates' => ['binding' => ['id' => 'approved']]], 'Invalid template'))->toThrow(ValidationException::class);
    expect(DB::table('system_settings')->count())->toBe($before)->and(json_encode(DB::table('audit_logs')->get()))->not->toContain($token, 'PRIVATE-SMS-');
});

it('keeps phone binding unavailable when a selected real transport lacks required configuration', function (): void {
    app(ConfigureIdentityAction::class)->execute(['sms.driver' => 'idcsmart', 'sms.binding_enabled' => true, 'sms.binding_template' => 'binding'], 'Incomplete SMS configuration');
    expect(app(PhoneBindingAction::class)->available())->toBeFalse();
    $this->actingAs(User::factory()->create())->get('/console/account')->assertOk()->assertSee('真实短信通道尚未接入');
});

it('accepts the optional UTF-8 BOM handled by the source SMS plugin without accepting a malformed response', function (): void {
    configureIdcsmartSms();
    Http::fake(['*' => Http::response("\xEF\xBB\xBF".'{"status":"200"}')]);
    expect(app(DriverManager::class)->sms()->sendTemplate('+8613800138000', 'binding', ['code' => '123456', 'minutes' => '10']))->toBeTrue();
    Http::assertSentCount(1);
});

it('binds a Telegram subject using the official signature then logs in without saving profile data or automatically subscribing', function (): void {
    $token = configureTelegramLogin();
    Http::fake();
    config(['app.url' => 'https://trusted.example.test']);
    $user = User::factory()->create(['password' => 'Customer-Password-123']);
    $this->actingAs($user)->post('/auth/telegram/redirect', ['link' => 1, 'current_password' => 'Customer-Password-123'])->assertRedirect();
    $state = session('telegram_state');
    $this->get('/auth/telegram/widget?state='.$state)->assertOk()->assertSee('data-telegram-login="isolated_test_bot"', false)
        ->assertSee('https://trusted.example.test/auth/telegram/callback?state='.$state, false)->assertDontSee($token);
    $payload = telegramSignedPayload($token);
    $this->get('/auth/telegram/callback?'.http_build_query(['state' => $state, ...$payload]))->assertRedirect('/console/account')->assertSessionHasNoErrors();
    expect(UserOauthAccount::sole()->provider_subject)->toBe('123456789')->and(UserNotificationBinding::count())->toBe(0)
        ->and(UserOauthAccount::sole()->meta)->toBeNull();
    $this->post('/logout');
    $this->get('/console/invoices')->assertRedirect('/login');
    $this->post('/auth/telegram/redirect')->assertRedirect();
    // Each Telegram proof is single-use even across separate site sessions.
    $this->travel(1)->seconds();
    $this->get('/auth/telegram/callback?'.http_build_query(['state' => session('telegram_state'), ...telegramSignedPayload($token)]))
        ->assertRedirect('/console/invoices')->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($user);
    Http::assertNothingSent();
    expect(json_encode(DB::table('audit_logs')->get()))->not->toContain(' Test User ', $token);
});

it('rejects invalid expired future tampered nested or unknown-field Telegram proofs without authenticating', function (string $case): void {
    $token = configureTelegramLogin();
    UserOauthAccount::create(['user_id' => User::factory()->create()->id, 'provider' => 'telegram', 'provider_subject' => '123456789']);
    $this->post('/auth/telegram/redirect')->assertRedirect();
    $payload = telegramSignedPayload($token, match ($case) {
        'expired' => ['auth_date' => (string) (now()->timestamp - 301)],
        'future' => ['auth_date' => (string) (now()->timestamp + 31)],
        'unknown' => ['unexpected' => 'field'],
        default => [],
    });
    $state = session('telegram_state');
    if ($case === 'state') {
        $state = 'wrong-state';
    } elseif ($case === 'signature') {
        $payload['hash'] = str_repeat('0', 64);
    } elseif ($case === 'tampered') {
        $payload['id'] = '987654321';
    } elseif ($case === 'nested') {
        $payload['id'] = ['nested'];
    }
    $this->get('/auth/telegram/callback?'.http_build_query(['state' => $state, ...$payload]))->assertRedirect('/login')->assertSessionHasErrors('operation');
    $this->assertGuest();
    expect(UserOauthAccount::count())->toBe(1);
})->with(['state', 'signature', 'tampered', 'expired', 'future', 'nested', 'unknown']);

it('rejects a reused Telegram proof even when a new valid site intent is created', function (): void {
    $token = configureTelegramLogin();
    $user = User::factory()->create();
    UserOauthAccount::create(['user_id' => $user->id, 'provider' => 'telegram', 'provider_subject' => '123456789']);
    $payload = telegramSignedPayload($token);
    $this->post('/auth/telegram/redirect');
    $this->get('/auth/telegram/callback?'.http_build_query(['state' => session('telegram_state'), ...$payload]))->assertSessionHasNoErrors();
    $this->post('/logout');
    $this->post('/auth/telegram/redirect');
    $this->get('/auth/telegram/callback?'.http_build_query(['state' => session('telegram_state'), ...$payload]))->assertSessionHasErrors('operation');
    $this->assertGuest();
});

it('requires local mailbox and password confirmation and refuses binding another users Telegram subject', function (): void {
    $token = configureTelegramLogin();
    $owner = User::factory()->create();
    UserOauthAccount::create(['user_id' => $owner->id, 'provider' => 'telegram', 'provider_subject' => '123456789']);
    $actor = User::factory()->unverified()->create(['password' => 'Customer-Password-123']);
    $this->actingAs($actor)->post('/auth/telegram/redirect', ['link' => 1, 'current_password' => 'Customer-Password-123'])->assertSessionHasErrors('operation');
    $actor->update(['email_verified_at' => now()]);
    $this->post('/auth/telegram/redirect', ['link' => 1, 'current_password' => 'wrong'])->assertSessionHasErrors('operation');
    $this->post('/auth/telegram/redirect', ['link' => 1, 'current_password' => 'Customer-Password-123']);
    $this->get('/auth/telegram/callback?'.http_build_query(['state' => session('telegram_state'), ...telegramSignedPayload($token)]))->assertRedirect('/console/account')->assertSessionHasErrors('operation');
    expect(UserOauthAccount::sole()->user_id)->toBe($owner->id)->and(UserNotificationBinding::count())->toBe(0);
});

it('does not create a local account from an unbound Telegram authorization and rejects widget pages without site intent', function (): void {
    $token = configureTelegramLogin();
    $this->get('/auth/telegram/widget?state=missing')->assertRedirect('/login')->assertSessionHasErrors('operation');
    $this->post('/auth/telegram/redirect');
    $this->get('/auth/telegram/callback?'.http_build_query(['state' => session('telegram_state'), ...telegramSignedPayload($token)]))->assertRedirect('/login')->assertSessionHasErrors('operation');
    expect(User::count())->toBe(0)->and(UserOauthAccount::count())->toBe(0);
});

it('requires an explicit subscription derived from a bound Telegram identity and revokes notifications on unlink', function (): void {
    configureTelegramLogin();
    Http::fake();
    $user = User::factory()->create(['password' => 'Customer-Password-123']);
    $this->actingAs($user)->post('/console/account/telegram/notifications', ['current_password' => 'Customer-Password-123', 'enabled' => 1])->assertSessionHasErrors('operation');
    UserOauthAccount::create(['user_id' => $user->id, 'provider' => 'telegram', 'provider_subject' => '123456789']);
    $this->post('/console/account/telegram/notifications', ['current_password' => 'wrong', 'enabled' => 1])->assertSessionHasErrors('operation');
    $this->post('/console/account/telegram/notifications', ['current_password' => 'Customer-Password-123', 'enabled' => 1, 'target' => 'ATTACKER-CHAT'])->assertSessionHasNoErrors();
    expect(UserNotificationBinding::sole()->target)->toBe('123456789')->and(UserNotificationBinding::sole()->verified_at)->not->toBeNull();
    $this->post('/console/account/telegram/notifications', ['current_password' => 'Customer-Password-123', 'enabled' => 0])->assertSessionHasNoErrors();
    expect(UserNotificationBinding::sole()->verified_at)->toBeNull();
    $this->post('/console/account/telegram/notifications', ['current_password' => 'Customer-Password-123', 'enabled' => 1])->assertSessionHasNoErrors();
    $this->travel(61)->seconds(); // Existing numeric route throttles share a bucket for this user.
    $this->post('/console/account/oauth/telegram/unlink', ['current_password' => 'Customer-Password-123'])->assertRedirect()->assertSessionHasNoErrors();
    expect(UserNotificationBinding::sole()->verified_at)->toBeNull()->and(UserOauthAccount::count())->toBe(0);
    Http::assertNothingSent();
});

it('invalidates a Telegram binding intent after a password change and never promotes the account from a browser return', function (): void {
    $token = configureTelegramLogin();
    $user = User::factory()->create(['password' => 'Customer-Password-123']);
    $this->actingAs($user)->post('/auth/telegram/redirect', ['link' => 1, 'current_password' => 'Customer-Password-123']);
    $state = session('telegram_state');
    $user->update(['password' => 'Changed-Password-456']);
    // Refresh the session's authentication hash without changing the saved provider intent.
    $this->withSession(['password_hash_web' => $user->getAuthPassword()]);
    $this->get('/auth/telegram/callback?'.http_build_query(['state' => $state, ...telegramSignedPayload($token)]))->assertSessionHasErrors('operation');
    expect(UserOauthAccount::count())->toBe(0)->and($user->fresh()->kyc_verified_at)->toBeNull();
});

it('expires the local Telegram intent independently of fresh provider authentication data', function (): void {
    $token = configureTelegramLogin();
    UserOauthAccount::create(['user_id' => User::factory()->create()->id, 'provider' => 'telegram', 'provider_subject' => '123456789']);
    $this->post('/auth/telegram/redirect');
    $state = session('telegram_state');
    $this->travel(601)->seconds();
    $this->get('/auth/telegram/callback?'.http_build_query(['state' => $state, ...telegramSignedPayload($token)]))->assertRedirect('/login')->assertSessionHasErrors('operation');
    $this->assertGuest();
});
