<?php

declare(strict_types=1);

use App\Actions\ApplyKycStatusAction;
use App\Actions\ConfigureIdentityAction;
use App\Actions\PhoneBindingAction;
use App\Contracts\MailDriverInterface;
use App\Contracts\SmsDriverInterface;
use App\Data\KycStatusResult;
use App\Models\KycVerification;
use App\Models\User;
use App\Models\UserNotificationBinding;
use App\Models\UserOauthAccount;
use App\Services\DriverManager;
use App\Services\SettingService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);
beforeEach(fn () => $this->installApplicationForTest());

function identityMail(array &$messages, bool $success = true): void
{
    app(ConfigureIdentityAction::class)->execute(['mail.transport' => 'smtp', 'mail.host' => 'smtp.example.test', 'mail.from_address' => 'accounts@example.test'], 'Isolated mail setup');
    $mail = Mockery::mock(MailDriverInterface::class);
    $level = DB::transactionLevel();
    $mail->shouldReceive('send')->andReturnUsing(function ($to, $subject, $body) use (&$messages, $success, $level): bool {
        expect(DB::transactionLevel())->toBe($level);
        $messages[] = compact('to', 'subject', 'body');

        return $success;
    });
    $manager = Mockery::mock(DriverManager::class)->makePartial();
    $manager->shouldReceive('mail')->andReturn($mail);
    app()->instance(DriverManager::class, $manager);
}

function identitySms(array &$codes, bool $success = true): void
{
    app(ConfigureIdentityAction::class)->execute(['sms.binding_enabled' => true, 'sms.binding_template' => 'binding'], 'Isolated SMS setup');
    $sms = Mockery::mock(SmsDriverInterface::class);
    $level = DB::transactionLevel();
    $sms->shouldReceive('sendTemplate')->andReturnUsing(function ($phone, $template, $params) use (&$codes, $success, $level): bool {
        expect(DB::transactionLevel())->toBe($level);
        $codes[$phone] = $params['code'];

        return $success;
    });
    $manager = Mockery::mock(DriverManager::class)->makePartial();
    $manager->shouldReceive('sms')->andReturn($sms);
    app()->instance(DriverManager::class, $manager);
}

function identityOAuth(string $subject, int $rounds = 1, string $email = 'same@example.test'): void
{
    app(ConfigureIdentityAction::class)->execute(['oauth.github_enabled' => true, 'oauth.github_client_id' => 'test-client', 'oauth.github_client_secret' => 'PRIVATE-OAUTH-SECRET'], 'Isolated OAuth setup');
    $responses = [];
    for ($i = 0; $i < $rounds; $i++) {
        $responses[] = new Response(200, [], json_encode(['access_token' => 'PRIVATE-ACCESS-TOKEN', 'token_type' => 'bearer']));
        $responses[] = new Response(200, [], json_encode(['id' => $subject, 'node_id' => 'node', 'login' => 'sample', 'avatar_url' => 'https://example.test/avatar']));
        $responses[] = new Response(200, [], json_encode([['email' => $email, 'primary' => true, 'verified' => true]]));
    }
    $client = new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);
    // Exercise the installed provider's real state/token/user parsing, replacing only transport.
    Socialite::shouldReceive('buildProvider')->andReturnUsing(fn ($class, $config) => (new $class(app('request'), $config['client_id'], $config['client_secret'], $config['redirect']))->setHttpClient($client));
}

it('shows explicit disabled identity channels and does not treat the log SMS driver as verification', function (): void {
    $user = User::factory()->unverified()->create(['phone' => '+8613800138000']);
    $this->get('/console/account')->assertRedirect('/login');
    $this->actingAs($user)->get('/console/account')->assertOk()->assertSee('账户安全')->assertSee('真实短信通道尚未接入')->assertSee('实名认证服务尚未启用')->assertSee('未验证');
    $this->post('/console/account/phone/send', ['phone' => '+8613800138000'])->assertSessionHasErrors('phone');
    $this->post('/email/verification-notification')->assertSessionHasErrors('operation');
    expect($user->fresh()->email_verified_at)->toBeNull()->and($user->fresh()->kyc_verified_at)->toBeNull();
    expect(UserNotificationBinding::count())->toBe(0);
});

it('encrypts identity secrets and rejects unsupported config keys without leaking credentials into audit', function (): void {
    app(ConfigureIdentityAction::class)->execute(['mail.password' => 'PRIVATE-SMTP-PASSWORD', 'oauth.github_client_secret' => 'PRIVATE-OAUTH-SECRET'], 'Operator credential setup');
    expect(DB::table('system_settings')->where('group', 'mail')->where('key', 'password')->value('value'))->not->toContain('PRIVATE-SMTP-PASSWORD');
    expect(app(SettingService::class)->get('mail.password'))->toBe('PRIVATE-SMTP-PASSWORD');
    expect(DB::table('audit_logs')->where('action', 'identity.configure')->value('after'))->not->toContain('PRIVATE-');
    expect(fn () => app(ConfigureIdentityAction::class)->execute(['kyc.guessed_endpoint' => 'https://example.test'], 'Unsupported protocol'))->toThrow(ValidationException::class);
});

it('sends hashed throttled recovery tokens with identical public responses and a trusted link origin', function (): void {
    $messages = [];
    identityMail($messages);
    config(['app.url' => 'https://trusted.example.test']);
    $user = User::factory()->create(['credit' => '12.34']);
    $known = $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email])->assertRedirect('/forgot-password')->assertSessionHasNoErrors();
    $status = session('status');
    $this->post('/forgot-password', ['email' => 'missing@example.test'])->assertSessionHas('status', $status);
    $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status', $status);
    expect($messages)->toHaveCount(1);
    expect($messages[0]['body'])->toContain('https://trusted.example.test/reset-password/');
    preg_match('~/reset-password/([A-Za-z0-9]+)~', $messages[0]['body'], $matches);
    $stored = DB::table('password_reset_tokens')->where('email', $user->email)->value('token');
    expect($stored)->not->toBe($matches[1])->and(Hash::check($matches[1], $stored))->toBeTrue();
    expect($user->fresh()->credit)->toBe('12.34');
});

it('records a failed recovery delivery without revealing whether the mailbox exists', function (): void {
    $messages = [];
    identityMail($messages, false);
    $user = User::factory()->create();
    $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors();
    $status = session('status');
    $this->post('/forgot-password', ['email' => 'missing@example.test'])->assertSessionHas('status', $status);
    $this->assertDatabaseHas('audit_logs', ['action' => 'identity.password_reset_delivery_failed', 'subject_id' => $user->id]);
});

it('consumes a recovery token once and revokes old database sessions and remember credentials', function (): void {
    $user = User::factory()->create(['password' => 'Original-Password-123', 'remember_token' => 'old-remember']);
    $token = Password::broker()->createToken($user);
    DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);
    $data = ['email' => $user->email, 'token' => $token, 'password' => 'Changed-Password-456', 'password_confirmation' => 'Changed-Password-456'];
    $this->post('/reset-password', $data)->assertRedirect('/login');
    expect(Hash::check('Changed-Password-456', $user->fresh()->password))->toBeTrue();
    expect($user->fresh()->remember_token)->not->toBe('old-remember');
    expect(DB::table('sessions')->where('id', 'old-session')->exists())->toBeFalse();
    $this->post('/reset-password', $data)->assertSessionHasErrors('token');
    $this->post('/login', ['email' => $user->email, 'password' => 'Original-Password-123'])->assertSessionHasErrors('email');
    $this->post('/login', ['email' => $user->email, 'password' => 'Changed-Password-456'])->assertRedirect('/console');
});

it('rejects expired and mismatched recovery tokens without changing the password', function (string $case): void {
    $user = User::factory()->create(['password' => 'Original-Password-123']);
    $other = User::factory()->create();
    $token = Password::broker()->createToken($user);
    if ($case === 'expired') {
        $this->travel(61)->minutes();
    }
    $this->post('/reset-password', ['email' => $case === 'wrong-user' ? $other->email : $user->email, 'token' => $token,
        'password' => 'Changed-Password-456', 'password_confirmation' => 'Changed-Password-456'])->assertSessionHasErrors('token');
    expect(Hash::check('Original-Password-123', $user->fresh()->password))->toBeTrue();
})->with(['expired', 'wrong-user']);

it('sends a signed verification link and atomically verifies the current mailbox and notification binding', function (): void {
    $messages = [];
    identityMail($messages);
    $user = User::factory()->unverified()->create();
    $this->actingAs($user)->post('/email/verification-notification')->assertSessionHasNoErrors();
    preg_match('~https?://[^\s]+~', $messages[0]['body'], $matches);
    $url = $matches[0];
    $this->get($url)->assertRedirect('/console/account');
    expect($user->fresh()->email_verified_at)->not->toBeNull();
    $this->assertDatabaseHas('user_notification_bindings', ['user_id' => $user->id, 'channel' => 'email', 'target' => $user->email]);
    $this->get($url)->assertRedirect('/console/account');
    expect(DB::table('audit_logs')->where('action', 'identity.email_verified')->count())->toBe(1);
});

it('rejects expired forged cross-account and obsolete mailbox verification links', function (string $case): void {
    $user = User::factory()->unverified()->create();
    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)], absolute: false);
    $actor = $user;
    if ($case === 'expired') {
        $this->travel(61)->minutes();
    } elseif ($case === 'forged') {
        $url .= '&unexpected=1';
    } elseif ($case === 'wrong-user') {
        $actor = User::factory()->unverified()->create();
    } else {
        $user->update(['email' => 'changed@example.test']);
    }
    $response = $this->actingAs($actor)->get($url);
    if (in_array($case, ['expired', 'forged'], true)) {
        $response->assertForbidden();
    } else {
        $response->assertSessionHasErrors('email');
    }
    expect($user->fresh()->email_verified_at)->toBeNull()->and(UserNotificationBinding::count())->toBe(0);
})->with(['expired', 'forged', 'wrong-user', 'changed-email']);

it('binds and then logs into the original OAuth subject while never persisting provider tokens', function (): void {
    identityOAuth('subject-123', 2);
    $user = User::factory()->create(['password' => 'Original-Password-123']);
    $this->actingAs($user)->post('/auth/github/redirect', ['link' => '1', 'current_password' => 'Original-Password-123'])->assertRedirect();
    $state = session('state');
    $this->get('/auth/github/callback?'.http_build_query(['state' => $state, 'code' => 'authorization-code']))->assertRedirect('/console/account');
    expect(UserOauthAccount::sole()->user_id)->toBe($user->id)->and(UserOauthAccount::sole()->provider_subject)->toBe('subject-123');
    expect(json_encode(DB::table('user_oauth_accounts')->get()))->not->toContain('PRIVATE-ACCESS-TOKEN');
    $this->post('/logout');
    $this->post('/auth/github/redirect')->assertRedirect();
    $this->get('/auth/github/callback?'.http_build_query(['state' => session('state'), 'code' => 'second-code']))->assertRedirect('/console/account');
    $this->assertAuthenticatedAs($user);
    $this->get('/auth/github/callback?state='.$state.'&code=replay')->assertSessionHasErrors('operation');
    expect(UserOauthAccount::count())->toBe(1);
});

it('does not merge an unbound OAuth identity by its verified provider email', function (): void {
    $user = User::factory()->create(['email' => 'same@example.test']);
    identityOAuth('unbound-subject', email: $user->email);
    $this->post('/auth/github/redirect')->assertRedirect();
    $this->get('/auth/github/callback?'.http_build_query(['state' => session('state'), 'code' => 'code']))->assertSessionHasErrors('operation');
    $this->assertGuest();
    expect(UserOauthAccount::count())->toBe(0)->and(User::count())->toBe(1);
});

it('rejects OAuth state provider purpose and expiry mismatches before binding', function (string $case): void {
    identityOAuth('subject-123');
    $this->post('/auth/github/redirect')->assertRedirect();
    $state = session('state');
    $provider = 'github';
    if ($case === 'expired') {
        $this->travel(11)->minutes();
    } elseif ($case === 'wrong-state') {
        $state = 'incorrect';
    } elseif ($case === 'provider') {
        $provider = 'google';
    } else {
        $this->actingAs(User::factory()->create());
    }
    $this->get('/auth/'.$provider.'/callback?'.http_build_query(['state' => $state, 'code' => 'code']))->assertSessionHasErrors('operation');
    expect(UserOauthAccount::count())->toBe(0);
})->with(['expired', 'wrong-state', 'provider', 'purpose']);

it('refuses OAuth rebinding and requires a verified local password for link and unlink', function (): void {
    identityOAuth('existing-subject');
    $owner = User::factory()->create();
    UserOauthAccount::create(['user_id' => $owner->id, 'provider' => 'github', 'provider_subject' => 'existing-subject']);
    $actor = User::factory()->unverified()->create(['password' => 'Original-Password-123']);
    $this->actingAs($actor)->post('/auth/github/redirect', ['link' => 1, 'current_password' => 'Original-Password-123'])->assertSessionHasErrors('operation');
    $actor->update(['email_verified_at' => now()]);
    $this->post('/auth/github/redirect', ['link' => 1, 'current_password' => 'wrong'])->assertSessionHasErrors('operation');
    $this->post('/auth/github/redirect', ['link' => 1, 'current_password' => 'Original-Password-123'])->assertRedirect();
    $this->get('/auth/github/callback?'.http_build_query(['state' => session('state'), 'code' => 'code']))->assertSessionHasErrors('operation');
    expect(UserOauthAccount::sole()->user_id)->toBe($owner->id);
    $this->post('/logout');
    $this->actingAs($owner)->post('/console/account/oauth/github/unlink', ['current_password' => 'wrong'])->assertSessionHasErrors('operation');
    $this->post('/console/account/oauth/github/unlink', ['current_password' => 'password'])->assertSessionHasNoErrors();
    expect(UserOauthAccount::count())->toBe(0);
});

it('disables unsupported social providers instead of making speculative protocol calls', function (): void {
    foreach (['github', 'telegram', 'unknown'] as $provider) {
        $this->post('/auth/'.$provider.'/redirect')->assertSessionHasErrors('operation');
    }
    expect(UserOauthAccount::count())->toBe(0);
});

it('uses the installed Google QQ and WeChat adapters with state and their actual subject mappings', function (string $provider): void {
    app(ConfigureIdentityAction::class)->execute(['oauth.'.$provider.'_enabled' => true, 'oauth.'.$provider.'_client_id' => 'client-id',
        'oauth.'.$provider.'_client_secret' => 'test-secret'], 'Provider protocol acceptance');
    $subject = $provider.'-subject';
    $bodies = match ($provider) {
        'google' => [['access_token' => 'opaque-access-token'], ['sub' => $subject, 'email' => 'provider@example.test']],
        'qq' => [['access_token' => 'opaque-access-token'], ['openid' => $subject], ['nickname' => 'QQ name', 'figureurl_qq_2' => 'https://example.test/avatar']],
        'wechat' => [['access_token' => 'opaque-access-token', 'openid' => $subject], ['openid' => $subject, 'nickname' => 'WeChat name']],
    };
    $client = new Client(['handler' => HandlerStack::create(new MockHandler(array_map(fn ($body) => new Response(200, [], json_encode($body)), $bodies)))]);
    Socialite::shouldReceive('buildProvider')->andReturnUsing(fn ($class, $config) => (new $class(app('request'), $config['client_id'], $config['client_secret'], $config['redirect']))->setHttpClient($client));
    $user = User::factory()->create();
    UserOauthAccount::create(['user_id' => $user->id, 'provider' => $provider, 'provider_subject' => $subject]);
    $redirect = $this->post('/auth/'.$provider.'/redirect')->assertRedirect();
    expect($redirect->headers->get('Location'))->toContain('state=');
    $this->get('/auth/'.$provider.'/callback?'.http_build_query(['state' => session('state'), 'code' => 'one-use-code']))->assertRedirect('/console/account');
    $this->assertAuthenticatedAs($user);
})->with(['google', 'qq', 'wechat']);

it('verifies phone ownership exactly once and revokes the previous SMS notification binding', function (): void {
    $codes = [];
    identitySms($codes);
    $user = User::factory()->create(['phone' => '+8613800000000']);
    UserNotificationBinding::create(['user_id' => $user->id, 'channel' => 'sms', 'target' => $user->phone, 'verified_at' => now()]);
    $phone = '+8613800138000';
    $this->actingAs($user)->post('/console/account/phone/send', ['phone' => $phone])->assertSessionHasNoErrors();
    expect($user->fresh()->phone)->toBe('+8613800000000');
    $this->post('/console/account/phone/confirm', ['phone' => $phone, 'code' => $codes[$phone]])->assertSessionHasNoErrors();
    expect($user->fresh()->phone)->toBe($phone);
    expect(UserNotificationBinding::where('target', '+8613800000000')->value('verified_at'))->toBeNull();
    expect(UserNotificationBinding::where('target', $phone)->value('verified_at'))->not->toBeNull();
    $this->post('/console/account/phone/confirm', ['phone' => $phone, 'code' => $codes[$phone]])->assertSessionHasErrors('code');
    expect(DB::table('audit_logs')->where('action', 'identity.phone_verified')->count())->toBe(1);
    expect(json_encode(DB::table('audit_logs')->get()))->not->toContain($codes[$phone]);
});

it('rejects expired wrong-user wrong-phone and exhausted SMS challenges', function (string $case): void {
    $codes = [];
    identitySms($codes);
    $user = User::factory()->create();
    $phone = '+8613800138000';
    $action = app(PhoneBindingAction::class);
    $action->send($user, $phone);
    $actor = $user;
    if ($case === 'expired') {
        $this->travel(11)->minutes();
    } elseif ($case === 'wrong-user') {
        $actor = User::factory()->create();
    } elseif ($case === 'exhausted') {
        for ($i = 0; $i < 5; $i++) {
            expect(fn () => $action->confirm($user, $phone, '000000'))->toThrow(ValidationException::class);
        }
    }
    expect(fn () => $action->confirm($actor, $case === 'wrong-phone' ? '+8613800999999' : $phone, $codes[$phone]))->toThrow(ValidationException::class);
    expect($user->fresh()->phone)->toBeNull()->and(UserNotificationBinding::count())->toBe(0);
})->with(['expired', 'wrong-user', 'wrong-phone', 'exhausted']);

it('does not extend OTP expiry on wrong attempts and will not take a phone from another account', function (): void {
    $codes = [];
    identitySms($codes);
    $user = User::factory()->create();
    $other = User::factory()->create(['phone' => '+8613800138000']);
    $action = app(PhoneBindingAction::class);
    $action->send($user, $other->phone);
    expect(fn () => $action->confirm($user, $other->phone, $codes[$other->phone]))->toThrow(ValidationException::class);
    expect($user->fresh()->phone)->toBeNull()->and($other->fresh()->phone)->toBe('+8613800138000');
    $this->travel(9)->minutes();
    expect(fn () => $action->confirm($user, $other->phone, '000000'))->toThrow(ValidationException::class);
    $this->travel(2)->minutes();
    expect(fn () => $action->confirm($user, $other->phone, $codes[$other->phone]))->toThrow(ValidationException::class);
});

it('leaves no usable phone challenge after SMS delivery fails', function (): void {
    $codes = [];
    identitySms($codes, false);
    $user = User::factory()->create();
    expect(fn () => app(PhoneBindingAction::class)->send($user, '+8613800138000'))->toThrow(ValidationException::class);
    expect(Cache::has('phone-binding:'.$user->id))->toBeFalse();
    expect($user->fresh()->phone)->toBeNull();
});

it('applies authenticated KYC results atomically and rejects old or contradictory terminal results', function (): void {
    $user = User::factory()->create();
    $old = KycVerification::create(['user_id' => $user->id, 'driver' => 'isolated', 'external_id' => 'old', 'status' => 'pending']);
    $current = KycVerification::create(['user_id' => $user->id, 'driver' => 'isolated', 'external_id' => 'current', 'status' => 'pending']);
    $action = app(ApplyKycStatusAction::class);
    expect($action->execute('isolated', new KycStatusResult('old', 'verified', ['name' => '*明', 'id_last4' => '1234'])))->toBeFalse();
    expect($user->fresh()->kyc_verified_at)->toBeNull()->and($old->fresh()->status)->toBe('pending');
    expect($action->execute('isolated', new KycStatusResult('current', 'verified', ['name' => '*明', 'id_last4' => '1234'])))->toBeTrue();
    expect($current->fresh()->status)->toBe('verified')->and($user->fresh()->kyc_verified_at)->not->toBeNull();
    expect($action->execute('isolated', new KycStatusResult('current', 'verified', [])))->toBeFalse();
    expect(fn () => $action->execute('isolated', new KycStatusResult('current', 'failed', [])))->toThrow(DomainException::class);
    expect($user->fresh()->kyc_verified_at)->not->toBeNull();
    expect(DB::table('audit_logs')->where('action', 'identity.kyc_status')->count())->toBe(1);
});

it('rejects ambiguous KYC correlations and unmasked identity data without any state write', function (): void {
    $user = User::factory()->create();
    $record = KycVerification::create(['user_id' => $user->id, 'driver' => 'isolated', 'external_id' => 'one', 'status' => 'pending']);
    $action = app(ApplyKycStatusAction::class);
    expect(fn () => $action->execute('isolated', new KycStatusResult('one', 'verified', ['id_number' => '110101199001011234'])))->toThrow(DomainException::class);
    expect(fn () => $action->execute('isolated', new KycStatusResult('one', 'verified', ['name' => '张三'])))->toThrow(DomainException::class);
    KycVerification::create(['user_id' => User::factory()->create()->id, 'driver' => 'isolated', 'external_id' => 'one', 'status' => 'pending']);
    expect(fn () => $action->execute('isolated', new KycStatusResult('one', 'verified', [])))->toThrow(DomainException::class);
    expect($record->fresh()->status)->toBe('pending')->and($user->fresh()->kyc_verified_at)->toBeNull();
});
