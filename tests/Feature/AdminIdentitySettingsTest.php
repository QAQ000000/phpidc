<?php

declare(strict_types=1);

use App\Actions\ConfigureIdentityAction;
use App\Models\User;
use App\Services\DriverManager;
use App\Services\SettingService;
use App\Support\IdentitySettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Http::fake();
    Mail::fake();
});

afterEach(function (): void {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
});

function identitySettingsAdmin(array $permissions = ['settings.view', 'settings.notification.manage', 'settings.identity.manage']): User
{
    $user = User::factory()->create();
    foreach ($permissions as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $user;
}

function identitySettingsCredentials(): array
{
    $settings = [
        'mail.transport' => 'smtp', 'mail.host' => 'smtp.example.test', 'mail.port' => 465,
        'mail.from_address' => 'admin@example.test', 'mail.from_name' => '测试站点',
        'mail.username' => 'PRIVATE-SMTP-USERNAME', 'mail.password' => 'PRIVATE-SMTP-PASSWORD',
        'sms.driver' => 'idcsmart', 'sms.binding_enabled' => true, 'sms.binding_template' => 'binding',
        'sms.idcsmart_endpoint' => 'https://sms.example.test/sms.php', 'sms.idcsmart_api' => 'PRIVATE-SMS-API',
        'sms.idcsmart_key' => 'PRIVATE-SMS-KEY', 'sms.idcsmart_sign' => '测试站点',
        'sms.idcsmart_templates' => ['binding' => ['id' => 'approved-binding', 'content' => '验证码：{code}，{minutes}分钟内有效。']],
        'kyc.enabled' => true, 'kyc.driver' => 'idcsmartali', 'kyc.idcsmartali_endpoint' => 'https://kyc.example.test/certapi.php',
        'kyc.idcsmartali_api' => 'PRIVATE-KYC-API', 'kyc.idcsmartali_key' => 'PRIVATE-KYC-KEY', 'kyc.idcsmartali_biz_code' => 'FACE',
        'telegram.bot_username' => 'isolated_test_bot', 'telegram.bot_token' => '123456:'.str_repeat('t', 35),
        'oauth.telegram_enabled' => true,
    ];
    foreach (['github', 'google', 'qq', 'wechat'] as $provider) {
        $settings['oauth.'.$provider.'_enabled'] = true;
        $settings['oauth.'.$provider.'_client_id'] = $provider.'-client';
        $settings['oauth.'.$provider.'_client_secret'] = 'PRIVATE-'.strtoupper($provider).'-SECRET';
    }

    return $settings;
}

it('shares only the existing identity keys and encrypted credential classification with the admin forms', function (): void {
    expect(array_keys(IdentitySettings::sections()))->toBe(['mail', 'sms', 'kyc', 'github', 'google', 'qq', 'wechat', 'telegram'])
        ->and(IdentitySettings::keysForSection('telegram'))->toBe(['telegram.bot_username', 'telegram.bot_token', 'oauth.telegram_enabled'])
        ->and(IdentitySettings::isSecret('mail.username'))->toBeTrue()
        ->and(IdentitySettings::isSecret('sms.idcsmart_api'))->toBeTrue()
        ->and(IdentitySettings::isSecret('kyc.idcsmartali_api'))->toBeTrue()
        ->and(IdentitySettings::isSecret('oauth.github_client_secret'))->toBeTrue()
        ->and(IdentitySettings::isSecret('mail.host'))->toBeFalse();
    expect(fn () => IdentitySettings::keysForSection('payment'))->toThrow(ValidationException::class);
    $actor = identitySettingsAdmin();
    $before = DB::table('system_settings')->count();
    expect(fn () => app(ConfigureIdentityAction::class)->execute(['payment.epay_key' => 'PRIVATE-UNSUPPORTED'], '', $actor))
        ->toThrow(ValidationException::class);
    expect(DB::table('system_settings')->count())->toBe($before)
        ->and(DB::table('audit_logs')->where('action', 'identity.configure')->count())->toBe(0);
});

it('checks the fresh actor and every submitted domain before any identity writes', function (string $case): void {
    $permissions = match ($case) {
        'no-view' => ['settings.notification.manage'],
        'no-manage' => ['settings.view'],
        'wrong-domain' => ['settings.view', 'settings.notification.manage'],
        default => ['settings.view', 'settings.notification.manage', 'settings.identity.manage'],
    };
    $actor = identitySettingsAdmin($permissions);
    if ($case === 'deleted') {
        $actor->delete();
    } elseif ($case === 'anonymized') {
        User::whereKey($actor->id)->update(['anonymized_at' => now()]);
    } elseif ($case === 'revoked') {
        $actor->revokePermissionTo('settings.notification.manage');
    }
    $settings = ['mail.from_name' => '不得保存'];
    if ($case === 'wrong-domain') {
        $settings['oauth.github_enabled'] = false;
    }
    $before = DB::table('system_settings')->orderBy('id')->get()->toArray();
    expect(fn () => app(ConfigureIdentityAction::class)->execute($settings, '', $actor))->toThrow(AuthorizationException::class);
    expect(DB::table('system_settings')->orderBy('id')->get()->toArray())->toEqual($before)
        ->and(DB::table('audit_logs')->where('action', 'identity.configure')->count())->toBe(0);
})->with(['no-view', 'no-manage', 'wrong-domain', 'deleted', 'anonymized', 'revoked']);

it('saves complete integrations with an administrator audit containing only key names', function (): void {
    $actor = identitySettingsAdmin();
    $settings = identitySettingsCredentials();
    app(ConfigureIdentityAction::class)->execute($settings, '', $actor);
    foreach ($settings as $key => $value) {
        if (IdentitySettings::isSecret($key)) {
            [$group, $name] = explode('.', $key, 2);
            $row = DB::table('system_settings')->where('group', $group)->where('key', $name)->first();
            expect((bool) $row->is_encrypted)->toBeTrue()->and($row->value)->not->toContain($value)
                ->and(Crypt::decryptString($row->value))->toBe($value);
        }
    }
    $audit = DB::table('audit_logs')->where('action', 'identity.configure')->sole();
    expect($audit->actor_type)->toBe('user')->and($audit->actor_id)->toBe($actor->id)
        ->and($audit->reason)->toBe('更新账户与通知接口配置')->and($audit->request_id)->not->toBeNull()
        ->and(json_decode($audit->after, true))->toBe(['keys' => array_keys($settings)]);
    foreach (array_filter($settings, fn (mixed $value, string $key): bool => IdentitySettings::isSecret($key), ARRAY_FILTER_USE_BOTH) as $value) {
        expect(json_encode($audit))->not->toContain($value);
    }
    expect(app(DriverManager::class)->socialProviders()['github']['enabled'])->toBeTrue()
        ->and(app(DriverManager::class)->telegramLogin()->ready())->toBeTrue();
});

it('retains every existing encrypted credential byte for empty web inputs', function (mixed $empty): void {
    $settings = identitySettingsCredentials();
    app(ConfigureIdentityAction::class)->execute($settings, 'Trusted initial configuration');
    $before = DB::table('system_settings')->where('is_encrypted', true)->orderBy('id')->get()->toArray();
    $secrets = array_fill_keys(array_keys(array_filter($settings, fn (mixed $value, string $key): bool => IdentitySettings::isSecret($key), ARRAY_FILTER_USE_BOTH)), $empty);
    app(ConfigureIdentityAction::class)->execute($secrets, '', identitySettingsAdmin());
    expect(DB::table('system_settings')->where('is_encrypted', true)->orderBy('id')->get()->toArray())->toEqual($before)
        ->and(json_decode(DB::table('audit_logs')->where('action', 'identity.configure')->orderByDesc('id')->value('after'), true))->toBe(['keys' => []]);
})->with(['empty' => [''], 'null' => [null], 'spaces' => ['   ']]);

it('rejects incomplete web enabling atomically while keeping existing CLI partial configuration available', function (array $settings): void {
    $actor = identitySettingsAdmin();
    $before = DB::table('system_settings')->orderBy('id')->get()->toArray();
    expect(fn () => app(ConfigureIdentityAction::class)->execute($settings, '', $actor))->toThrow(ValidationException::class);
    expect(DB::table('system_settings')->orderBy('id')->get()->toArray())->toEqual($before)
        ->and(DB::table('audit_logs')->where('action', 'identity.configure')->count())->toBe(0);
    app(ConfigureIdentityAction::class)->execute($settings, 'Trusted partial configuration');
    expect(DB::table('audit_logs')->where('action', 'identity.configure')->sole()->actor_type)->toBe('system');
})->with([
    'mail' => [['mail.transport' => 'smtp']],
    'sms-driver' => [['sms.driver' => 'idcsmart']],
    'sms-binding' => [['sms.binding_enabled' => true, 'sms.binding_template' => 'binding']],
    'kyc' => [['kyc.enabled' => true, 'kyc.driver' => 'idcsmartali']],
    'github' => [['oauth.github_enabled' => true]],
    'google' => [['oauth.google_enabled' => true]],
    'qq' => [['oauth.qq_enabled' => true]],
    'wechat' => [['oauth.wechat_enabled' => true]],
    'telegram' => [['oauth.telegram_enabled' => true]],
]);

it('uses committed stored credentials when enabling and rejects a mismatched binding template', function (): void {
    $settings = identitySettingsCredentials();
    $settings['oauth.github_enabled'] = false;
    $settings['sms.binding_enabled'] = false;
    app(ConfigureIdentityAction::class)->execute($settings, 'Trusted initial configuration');
    $actor = identitySettingsAdmin();
    app(ConfigureIdentityAction::class)->execute(['oauth.github_enabled' => true, 'oauth.github_client_secret' => ''], '', $actor);
    expect(app(DriverManager::class)->socialProviders()['github']['enabled'])->toBeTrue();
    $before = DB::table('system_settings')->orderBy('id')->get()->toArray();
    expect(fn () => app(ConfigureIdentityAction::class)->execute(['sms.binding_enabled' => true, 'sms.binding_template' => 'missing'], '', $actor))
        ->toThrow(ValidationException::class);
    expect(DB::table('system_settings')->orderBy('id')->get()->toArray())->toEqual($before);
});

it('allows disabling integrations without resupplying credentials and preserves CLI explicit mail clearing', function (): void {
    $actor = identitySettingsAdmin();
    app(ConfigureIdentityAction::class)->execute(['mail.transport' => 'log', 'sms.driver' => 'log', 'sms.binding_enabled' => false,
        'kyc.enabled' => false, 'oauth.github_enabled' => false, 'oauth.telegram_enabled' => false], '', $actor);
    app(ConfigureIdentityAction::class)->execute(['mail.password' => 'PRIVATE-ORIGINAL', 'mail.username' => 'PRIVATE-USERNAME'], 'Original credentials');
    app(ConfigureIdentityAction::class)->execute(['mail.password' => '', 'mail.username' => null], 'Explicit trusted clearing');
    expect(app(SettingService::class)->get('mail.password'))->toBe('')->and(app(SettingService::class)->get('mail.username'))->toBe('');
    expect(fn () => app(ConfigureIdentityAction::class)->execute(['mail.from_name' => 'No reason'], ''))->toThrow(ValidationException::class);
});

it('keeps saved settings and warm caches unchanged on rollback then invalidates after commit', function (): void {
    $action = app(ConfigureIdentityAction::class);
    $actor = identitySettingsAdmin();
    $action->execute(['mail.from_name' => 'Original', 'mail.password' => 'PRIVATE-CACHE-ORIGINAL'], 'Initial configuration');
    $settings = app(SettingService::class);
    expect($settings->get('mail.from_name'))->toBe('Original')->and($settings->get('mail.password'))->toBe('PRIVATE-CACHE-ORIGINAL');
    $beforeAudit = DB::table('audit_logs')->count();
    DB::beginTransaction();
    $action->execute(['mail.from_name' => 'Tentative'], '', $actor);
    expect($settings->get('mail.from_name'))->toBe('Original');
    DB::rollBack();
    expect(DB::table('system_settings')->where('group', 'mail')->where('key', 'from_name')->value('value'))->toBe('Original')
        ->and(DB::table('audit_logs')->count())->toBe($beforeAudit)->and($settings->get('mail.from_name'))->toBe('Original');
    $action->execute(['mail.from_name' => 'Committed', 'mail.password' => 'PRIVATE-CACHE-UPDATED'], '', $actor);
    expect($settings->get('mail.from_name'))->toBe('Committed')->and($settings->get('mail.password'))->toBe('PRIVATE-CACHE-UPDATED');
    $cached = Cache::get('setting:mail:password');
    expect($cached['value'])->not->toContain('PRIVATE-CACHE-UPDATED')->and(Crypt::decryptString($cached['value']))->toBe('PRIVATE-CACHE-UPDATED');
});
