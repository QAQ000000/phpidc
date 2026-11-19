<?php

declare(strict_types=1);

use App\Actions\ConfigureEpayAction;
use App\Actions\ConfigureIdentityAction;
use App\Actions\SetInstalledPluginStatusAction;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Services\SettingService;
use App\Support\EpayProfiles;
use App\Support\InstalledPlugins;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Http::fake();
    Mail::fake();
    Queue::fake();
});

afterEach(function (): void {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

function installedPluginOperator(array $permissions = ['settings.view', 'settings.payment.manage', 'settings.notification.manage', 'settings.identity.manage']): User
{
    $actor = User::factory()->create();
    foreach ($permissions as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $actor;
}

function installedPluginCredentials(): array
{
    return ['mail.transport' => 'log', 'mail.host' => 'smtp.plugin.test', 'mail.port' => 465,
        'mail.from_address' => 'admin@plugin.test', 'mail.password' => 'PLUGIN-SMTP-SECRET',
        'sms.driver' => 'log', 'sms.idcsmart_endpoint' => 'https://sms.plugin.test/api',
        'sms.idcsmart_api' => 'PLUGIN-SMS-API', 'sms.idcsmart_key' => 'PLUGIN-SMS-SECRET',
        'sms.idcsmart_sign' => '测试站点', 'sms.idcsmart_templates' => ['binding' => ['id' => 'bind', 'content' => '验证码 {code}']],
        'sms.binding_enabled' => false, 'sms.binding_template' => 'binding',
        'kyc.enabled' => false, 'kyc.driver' => 'disabled', 'kyc.idcsmartali_endpoint' => 'https://kyc.plugin.test/api',
        'kyc.idcsmartali_api' => 'PLUGIN-KYC-API', 'kyc.idcsmartali_key' => 'PLUGIN-KYC-SECRET', 'kyc.idcsmartali_biz_code' => 'FACE',
        'oauth.github_enabled' => false, 'oauth.github_client_id' => 'plugin-client', 'oauth.github_client_secret' => 'PLUGIN-OAUTH-SECRET'];
}

it('lists only built in adapters with safe fixed configuration links and truthful metadata', function (): void {
    app(ConfigureIdentityAction::class)->execute(installedPluginCredentials(), 'Fixture');
    expect(array_keys(InstalledPlugins::categories()))->toBe(['payment', 'mail', 'sms', 'kyc', 'login'])
        ->and(InstalledPlugins::categories()['mail']['exclusive'])->toBeTrue()
        ->and(InstalledPlugins::categories()['login']['exclusive'])->toBeFalse()
        ->and(array_keys(InstalledPlugins::definitions('payment')))->toBe(['epay'])
        ->and(InstalledPlugins::configurationContext('mail', 'smtp')['category'])->toBe('mail')
        ->and(InstalledPlugins::configurationContext('payment', 'smtp'))->toBeNull()
        ->and(InstalledPlugins::definitions('missing'))->toBe([]);
    $mail = InstalledPlugins::rows('mail');
    expect($mail[0]['enabled'])->toBeFalse()->and($mail[0]['configured'])->toBeTrue()
        ->and($mail[0]['configuration_url'])->toBe('/admin/settings?tab=mail&plugin=smtp')
        ->and($mail[1]['enabled'])->toBeTrue()->and($mail[1]['configuration_url'])->toBeNull()
        ->and($mail[0]['author'])->toBe('项目内置适配器')->and($mail[0]['version'])->toBe('随系统发布');
    foreach (InstalledPlugins::categories() as $category => $details) {
        expect(json_encode(InstalledPlugins::rows($category)))->not->toContain('PLUGIN-');
    }
});

it('switches exclusive notification plugins while retaining credentials and using log as fallback', function (string $category, string $plugin, string $setting): void {
    app(ConfigureIdentityAction::class)->execute(installedPluginCredentials(), 'Fixture');
    $secrets = DB::table('system_settings')->where('is_encrypted', true)->orderBy('id')->get()->toArray();
    $action = app(SetInstalledPluginStatusAction::class);
    $actor = installedPluginOperator();
    $action->execute($actor, $category, $plugin, true);
    expect(app(SettingService::class)->get($setting))->toBe($plugin)
        ->and(array_column(InstalledPlugins::rows($category), 'enabled'))->toBe([true, false]);
    $action->execute($actor, $category, $plugin, false);
    expect(app(SettingService::class)->get($setting))->toBe('log');
    $action->execute($actor, $category, $plugin, true);
    $action->execute($actor, $category, 'log', true);
    expect(array_column(InstalledPlugins::rows($category), 'enabled'))->toBe([false, true])
        ->and(DB::table('system_settings')->where('is_encrypted', true)->orderBy('id')->get()->toArray())->toEqual($secrets);
    expect(fn () => $action->execute($actor, $category, 'log', false))->toThrow(ValidationException::class);
})->with([['mail', 'smtp', 'mail.transport'], ['sms', 'idcsmart', 'sms.driver']]);

it('refuses incomplete activation before writing settings or audits', function (string $category, string $plugin): void {
    $actor = installedPluginOperator();
    $before = DB::table('system_settings')->orderBy('id')->get()->toArray();
    expect(fn () => app(SetInstalledPluginStatusAction::class)->execute($actor, $category, $plugin, true))->toThrow($category === 'payment' ? DomainException::class : ValidationException::class);
    expect(DB::table('system_settings')->orderBy('id')->get()->toArray())->toEqual($before)
        ->and(DB::table('audit_logs')->count())->toBe(0);
})->with([['mail', 'smtp'], ['sms', 'idcsmart'], ['kyc', 'idcsmartali'], ['login', 'github'], ['login', 'telegram'], ['payment', 'epay']]);

it('requires turning off phone binding before replacing the real SMS channel', function (string $plugin, bool $enabled): void {
    app(ConfigureIdentityAction::class)->execute([...installedPluginCredentials(), 'sms.driver' => 'idcsmart', 'sms.binding_enabled' => true], 'Fixture');
    $before = DB::table('system_settings')->orderBy('id')->get()->toArray();
    expect(fn () => app(SetInstalledPluginStatusAction::class)->execute(installedPluginOperator(), 'sms', $plugin, $enabled))
        ->toThrow(ValidationException::class, '请先关闭手机绑定');
    expect(DB::table('system_settings')->orderBy('id')->get()->toArray())->toEqual($before);
})->with([['idcsmart', false], ['log', true]]);

it('changes KYC driver together with its flag and permits independent login plugins', function (): void {
    app(ConfigureIdentityAction::class)->execute([...installedPluginCredentials(),
        'oauth.google_client_id' => 'google-client', 'oauth.google_client_secret' => 'PLUGIN-GOOGLE-SECRET',
        'telegram.bot_username' => 'PluginTestBot', 'telegram.bot_token' => '123456:'.str_repeat('t', 35), 'oauth.telegram_enabled' => true], 'Fixture');
    $token = DB::table('system_settings')->where('group', 'telegram')->where('key', 'bot_token')->value('value');
    $actor = installedPluginOperator();
    $action = app(SetInstalledPluginStatusAction::class);
    $action->execute($actor, 'kyc', 'idcsmartali', true);
    $action->execute($actor, 'login', 'github', true);
    $action->execute($actor, 'login', 'google', true);
    $action->execute($actor, 'login', 'telegram', false);
    expect(app(SettingService::class)->get('kyc.driver'))->toBe('idcsmartali')
        ->and(app(SettingService::class)->getBool('kyc.enabled'))->toBeTrue()
        ->and(app(SettingService::class)->getBool('oauth.github_enabled'))->toBeTrue()
        ->and(app(SettingService::class)->getBool('oauth.google_enabled'))->toBeTrue()
        ->and(app(SettingService::class)->getBool('oauth.telegram_enabled'))->toBeFalse()
        ->and(DB::table('system_settings')->where('group', 'telegram')->where('key', 'bot_token')->value('value'))->toBe($token);
    $action->execute($actor, 'kyc', 'idcsmartali', false);
    expect(app(SettingService::class)->get('kyc.driver'))->toBe('disabled')->and(app(SettingService::class)->getBool('kyc.enabled'))->toBeFalse()
        ->and(app(SettingService::class)->getBool('oauth.github_enabled'))->toBeTrue();
});

it('rechecks current operator account and permissions for plugin state changes', function (string $change, string $category, string $plugin): void {
    $actor = installedPluginOperator();
    expect($actor->can('settings.notification.manage'))->toBeTrue();
    if ($change === 'revoked') {
        $actor->revokePermissionTo(InstalledPlugins::categories()[$category]['permission']);
    } elseif ($change === 'no-view') {
        $actor->revokePermissionTo('settings.view');
    } else {
        DB::table('users')->where('id', $actor->id)->update([$change => now()]);
    }
    expect(fn () => app(SetInstalledPluginStatusAction::class)->execute($actor, $category, $plugin, false))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('system_settings', 0);
    $this->assertDatabaseCount('audit_logs', 0);
})->with([
    ['revoked', 'mail', 'smtp'], ['no-view', 'mail', 'smtp'], ['deleted_at', 'mail', 'smtp'], ['anonymized_at', 'mail', 'smtp'],
    ['revoked', 'payment', 'epay'], ['no-view', 'payment', 'epay'], ['deleted_at', 'payment', 'epay'], ['anonymized_at', 'payment', 'epay'],
]);

it('toggles payment admission without rewriting credentials profiles or attempts', function (): void {
    $actor = installedPluginOperator();
    $action = app(SetInstalledPluginStatusAction::class);
    $action->execute($actor, 'payment', 'epay', false);
    expect(app(SettingService::class)->getBool('payment.epay_alipay_enabled', true))->toBeFalse();
    app(ConfigureEpayAction::class)->execute('https://epay.plugin.test', 'merchant', 'PLUGIN-PAYMENT-SECRET', 'Fixture');
    $attempt = PaymentAttempt::create(['invoice_id' => Invoice::factory()->create()->id, 'gateway' => 'epay_wechat',
        'out_trade_no' => 'PLUGIN-PENDING-ATTEMPT', 'amount' => '10.00', 'status' => 'created',
        'request_payload' => [EpayProfiles::ATTEMPT_KEY => app(EpayProfiles::class)->currentId()]]);
    $attemptBefore = $attempt->fresh()->getRawOriginal();
    $before = DB::table('system_settings')->where('group', 'payment')->whereNotIn('key', ['epay_alipay_enabled', 'epay_wechat_enabled'])->orderBy('id')->get()->toArray();
    $action->execute($actor, 'payment', 'epay', true, '0');
    expect(app(SettingService::class)->getBool('payment.epay_alipay_enabled'))->toBeTrue()
        ->and(app(SettingService::class)->getBool('payment.epay_wechat_enabled'))->toBeTrue();
    $action->execute($actor, 'payment', 'epay', false);
    expect(DB::table('system_settings')->where('group', 'payment')->whereNotIn('key', ['epay_alipay_enabled', 'epay_wechat_enabled'])->orderBy('id')->get()->toArray())->toEqual($before)
        ->and($attempt->fresh()->getRawOriginal())->toEqual($attemptBefore)
        ->and(DB::table('payment_attempts')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('reason', '0')->sole()->after)->not->toContain('PLUGIN-PAYMENT-SECRET');
    expect(json_decode(DB::table('audit_logs')->where('reason', '0')->sole()->after, true))->toBe(['keys' => ['payment.epay_wechat_enabled', 'payment.epay_alipay_enabled']]);
    expect(fn () => $action->execute($actor, 'payment', 'epay', false, str_repeat('x', 256)))->toThrow(ValidationException::class);
});

it('does not synthesize a merchant profile from a legacy configuration when toggling channels', function (): void {
    foreach (['epay_api_url' => 'https://epay.plugin.test', 'epay_pid' => 'merchant', 'epay_key' => 'LEGACY-KEY'] as $key => $value) {
        DB::table('system_settings')->insert(['group' => 'payment', 'key' => $key, 'value' => $value, 'is_encrypted' => false]);
    }
    app(SetInstalledPluginStatusAction::class)->execute(installedPluginOperator(), 'payment', 'epay', true);
    expect(app(EpayProfiles::class)->currentId())->toBeNull()
        ->and(DB::table('system_settings')->where('key', 'like', EpayProfiles::PROFILE_PREFIX.'%')->where('is_encrypted', true)->count())->toBe(0);
});

it('protects plugin status routes and redirects only to their fixed category', function (): void {
    $this->post('/admin/settings/plugins/mail/smtp/status', ['enabled' => false])->assertRedirect('/admin/login');
    $actor = installedPluginOperator(['settings.view']);
    $this->actingAs($actor)->post('/admin/settings/plugins/mail/smtp/status', ['enabled' => false])->assertForbidden();
    $actor = installedPluginOperator();
    $this->actingAs($actor)->post('/admin/settings/plugins/mail/smtp/status', ['enabled' => false])->assertRedirect('/admin/settings?tab=mail-plugins');
    $this->post('/admin/settings/plugins/payment/yansongda/status', ['enabled' => true])->assertNotFound();
    $this->post('/admin/settings/plugins/unknown/smtp/status', ['enabled' => true])->assertNotFound();
    $this->post('/admin/settings/plugins/mail/smtp/status', ['enabled' => 'yes'])->assertSessionHasErrors('enabled');
});

it('keeps configuration saves in fixed plugin context without activating inactive plugins', function (): void {
    app(ConfigureIdentityAction::class)->execute(installedPluginCredentials(), 'Fixture');
    $this->actingAs(installedPluginOperator());
    $this->post('/admin/settings/identity/mail', ['plugin_context' => 'smtp', 'settings' => ['mail.transport' => 'smtp', 'mail.from_name' => 'New sender']])
        ->assertRedirect('/admin/settings?tab=mail&plugin=smtp');
    $this->post('/admin/settings/identity/github', ['plugin_context' => 'github', 'settings' => ['oauth.github_enabled' => true, 'oauth.github_client_id' => 'new-client']])
        ->assertRedirect('/admin/settings?tab=github&plugin=github');
    expect(app(SettingService::class)->get('mail.transport'))->toBe('log')
        ->and(app(SettingService::class)->getBool('oauth.github_enabled'))->toBeFalse();
    $this->post('/admin/settings/identity/mail', ['settings' => ['mail.from_name' => 'Legacy sender']])->assertRedirect('/admin/settings?tab=mail');
    $this->post('/admin/settings/test/mail', ['plugin_context' => 'smtp'])->assertRedirect('/admin/settings?tab=mail&plugin=smtp');
});

it('preserves disabled payment channels when saving merchant details in plugin context', function (): void {
    $actor = installedPluginOperator();
    app(SetInstalledPluginStatusAction::class)->execute($actor, 'payment', 'epay', false);
    $this->actingAs($actor)->post('/admin/settings/payment', ['plugin_context' => 'epay', 'url' => 'https://epay.plugin.test',
        'pid' => 'merchant', 'epay_key' => 'PLUGIN-KEY'])
        ->assertRedirect('/admin/settings?tab=payment&plugin=epay');
    expect(app(SettingService::class)->getBool('payment.epay_alipay_enabled', true))->toBeFalse()
        ->and(app(SettingService::class)->getBool('payment.epay_wechat_enabled', true))->toBeFalse();
});

it('accepts explicit complete channel choices inside payment plugin configuration but rejects partial choices', function (): void {
    $this->actingAs(installedPluginOperator());
    $data = ['plugin_context' => 'epay', 'url' => 'https://epay.plugin.test', 'pid' => 'merchant', 'epay_key' => 'PLUGIN-KEY'];
    $this->post('/admin/settings/payment', [...$data, 'alipay' => true])->assertSessionHasErrors('wechat');
    $this->assertDatabaseCount('system_settings', 0);
    $this->post('/admin/settings/payment', [...$data, 'alipay' => false, 'wechat' => true])
        ->assertRedirect('/admin/settings?tab=payment&plugin=epay');
    expect(app(SettingService::class)->getBool('payment.epay_alipay_enabled', true))->toBeFalse()
        ->and(app(SettingService::class)->getBool('payment.epay_wechat_enabled'))->toBeTrue();
});

it('rejects foreign or injected plugin contexts before writes or probes', function (string $url, array $data): void {
    $this->actingAs(installedPluginOperator())->post($url, $data)->assertSessionHasErrors('plugin_context');
    $this->assertDatabaseCount('system_settings', 0);
    $this->assertDatabaseCount('audit_logs', 0);
})->with([
    ['/admin/settings/identity/mail', ['plugin_context' => 'epay', 'settings' => ['mail.from_name' => 'tampered']]],
    ['/admin/settings/identity/mail', ['plugin_context' => 'https://evil.test', 'settings' => ['mail.from_name' => 'tampered']]],
    ['/admin/settings/payment', ['plugin_context' => 'smtp']],
    ['/admin/settings/test/mail', ['plugin_context' => 'idcsmart']],
]);
