<?php

declare(strict_types=1);

use App\Actions\ConfigureIdentityAction;
use App\Actions\PhoneBindingAction;
use App\Http\Middleware\EnsureAccountAccess;
use App\Models\User;
use App\Models\UserNotificationBinding;
use App\Models\UserOauthAccount;
use Filament\Facades\Filament;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Http::preventStrayRequests();
});

afterEach(function (): void {
    Http::assertNothingSent();
});

it('refuses disabled password credentials and accepts a new login after access is restored', function (): void {
    $owner = User::factory()->create(['password' => 'Customer-Password-123', 'disabled_at' => now(), 'auth_revision' => 1]);
    $this->post('/login', ['email' => $owner->email, 'password' => 'Customer-Password-123'])->assertSessionHasErrors(['email' => '邮箱或密码错误。']);
    $this->assertGuest();
    $owner->forceFill(['disabled_at' => null])->save();
    $this->post('/login', ['email' => $owner->email, 'password' => 'Customer-Password-123'])->assertRedirect('/console')
        ->assertSessionHas(EnsureAccountAccess::REVISION_KEY, 1);
    $this->assertAuthenticatedAs($owner);
    $this->get('/console')->assertOk();
});

it('revokes an old HTTP session even when access was restored before its next request', function (bool $restored): void {
    $owner = User::factory()->create(['password' => 'Customer-Password-123']);
    $this->post('/login', ['email' => $owner->email, 'password' => 'Customer-Password-123'])->assertRedirect('/console')
        ->assertSessionHas(EnsureAccountAccess::REVISION_KEY, 0);
    $owner->forceFill(['disabled_at' => $restored ? null : now(), 'auth_revision' => 1])->save();
    // Start the next HTTP request with the normal provider lookup, rather than a retained test guard object.
    Auth::forgetGuards();
    $this->get('/console')->assertRedirect('/login');
    $this->assertGuest();
})->with([false, true]);

it('rejects stale revisions with real file and database session stores without relying on session row deletion', function (string $driver, bool $disabled): void {
    config(['session.driver' => $driver]);
    app('session')->forgetDrivers();
    $store = app('session')->driver($driver);
    $store->start();
    $store->put(EnsureAccountAccess::REVISION_KEY, 0);
    $store->put('retained-client-proof', 'must-be-invalidated');
    $owner = User::factory()->create(['auth_revision' => 1, 'disabled_at' => $disabled ? now() : null]);
    $request = Request::create('/console/invoices', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
    $request->setLaravelSession($store);
    $request->setUserResolver(fn () => $owner);
    Auth::guard('web')->setUser($owner);
    $response = app(EnsureAccountAccess::class)->handle($request, fn () => response('incorrectly-authorized'));
    expect($response->getStatusCode())->toBe(401)->and($store->has('retained-client-proof'))->toBeFalse();
    $this->assertGuest();
})->with(['file', 'database'])->with([false, true]);

it('preserves legacy revision-zero sessions until an actual access revocation', function (): void {
    $owner = User::factory()->create();
    $this->actingAs($owner)->get('/console')->assertOk();
    expect($owner->fresh()->auth_revision)->toBe(0)->and($owner->fresh()->disabled_at)->toBeNull()
        ->and($owner->fresh()->toArray())->not->toHaveKey('auth_revision');
});

it('refuses a disabled identity in Filament and keeps its session middleware persistent for Livewire', function (): void {
    $actor = User::factory()->create(['disabled_at' => now()]);
    $actor->givePermissionTo(Permission::findOrCreate('customers.view', 'web'));
    expect($actor->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
    $this->actingAs($actor)->get('/admin/customers')->assertRedirect('/admin/login');
    expect(Filament::getPanel('admin')->getMiddleware())->toContain(EnsureAccountAccess::class);
    expect(app(PersistentMiddleware::class)->getPersistentMiddleware())->toContain(EnsureAccountAccess::class);
});

it('rejects a valid OAuth callback whose bound owner was disabled before completion', function (): void {
    app(ConfigureIdentityAction::class)->execute(['oauth.google_enabled' => true,
        'oauth.google_client_id' => 'isolated-client', 'oauth.google_client_secret' => 'isolated-secret'], 'Isolated disabled OAuth test');
    $client = new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response(200, [], json_encode(['access_token' => 'isolated-opaque-token'])),
        new Response(200, [], json_encode(['sub' => 'disabled-bound-subject'])),
    ]))]);
    Socialite::shouldReceive('buildProvider')->andReturnUsing(fn ($class, $config) => (new $class(app('request'), $config['client_id'], $config['client_secret'], $config['redirect']))->setHttpClient($client));
    $owner = User::factory()->create();
    UserOauthAccount::create(['user_id' => $owner->id, 'provider' => 'google', 'provider_subject' => 'disabled-bound-subject']);
    $this->post('/auth/google/redirect')->assertRedirect();
    $owner->forceFill(['disabled_at' => now(), 'auth_revision' => 1])->save();
    $this->get('/auth/google/callback?'.http_build_query(['state' => session('state'), 'code' => 'isolated-code']))
        ->assertRedirect('/login')->assertSessionHasErrors('operation');
    $this->assertGuest();
    expect(UserOauthAccount::sole()->user_id)->toBe($owner->id);
});

it('rejects a valid Telegram login proof for a disabled bound account', function (): void {
    $token = '123456:'.str_repeat('A', 35);
    app(ConfigureIdentityAction::class)->execute(['telegram.bot_token' => $token,
        'telegram.bot_username' => 'isolated_c2_bot', 'oauth.telegram_enabled' => true], 'Isolated disabled Telegram test');
    $owner = User::factory()->create(['disabled_at' => now(), 'auth_revision' => 1]);
    UserOauthAccount::create(['user_id' => $owner->id, 'provider' => 'telegram', 'provider_subject' => '123456789']);
    $this->post('/auth/telegram/redirect')->assertRedirect();
    $data = ['id' => '123456789', 'first_name' => 'Isolated', 'auth_date' => (string) now()->timestamp];
    ksort($data, SORT_STRING);
    $lines = [];
    foreach ($data as $key => $value) {
        $lines[] = $key.'='.$value;
    }
    $data['hash'] = hash_hmac('sha256', implode("\n", $lines), hash('sha256', $token, true));
    $this->get('/auth/telegram/callback?'.http_build_query(['state' => session('telegram_state'), ...$data]))
        ->assertRedirect('/login')->assertSessionHasErrors('operation');
    $this->assertGuest();
    expect(UserOauthAccount::sole()->user_id)->toBe($owner->id);
});

it('fences an old cached OTP durably while allowing an unchanged legacy challenge', function (int $revision): void {
    $owner = User::factory()->create(['password' => 'Customer-Password-123', 'auth_revision' => $revision]);
    Cache::put('phone-binding:'.$owner->id, ['phone' => '+8613800138000', 'hash' => Hash::make('123456'),
        'password_hash' => $owner->getAuthPassword(), 'attempts' => 0, 'expires' => now()->addMinutes(10)->timestamp], 600);
    $phone = Mockery::mock(PhoneBindingAction::class)->makePartial();
    $phone->shouldReceive('available')->andReturnTrue();
    if ($revision !== 0) {
        expect(fn () => $phone->confirm($owner, '+8613800138000', '123456'))->toThrow(ValidationException::class);
        expect($owner->fresh()->phone)->toBeNull()->and(UserNotificationBinding::count())->toBe(0);
    } else {
        $phone->confirm($owner, '+8613800138000', '123456');
        expect($owner->fresh()->phone)->toBe('+8613800138000')->and(UserNotificationBinding::sole()->verified_at)->not->toBeNull();
    }
})->with([0, 1]);
