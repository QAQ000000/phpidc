<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Actions\ConfigureEpayAction;
use App\Actions\ConfigureIdentityAction;
use App\Filament\Pages\SystemSettings;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Http::preventStrayRequests();
    Mail::fake();
    app(ConfigureIdentityAction::class)->execute([
        'mail.transport' => 'log', 'mail.host' => 'smtp.presentation.test', 'mail.port' => 465,
        'mail.from_address' => 'sender@presentation.test', 'mail.password' => 'PRIVATE-PRESENTATION-MAIL',
        'sms.driver' => 'log', 'sms.idcsmart_api' => 'PRIVATE-PRESENTATION-SMS',
        'kyc.idcsmartali_key' => 'PRIVATE-PRESENTATION-KYC',
        'oauth.github_client_secret' => 'PRIVATE-PRESENTATION-OAUTH',
    ], 'Isolated UI fixture');
    app(ConfigureEpayAction::class)->execute('https://pay.presentation.test', '1000', 'PRIVATE-PRESENTATION-EPAY', 'Isolated UI fixture');
});

afterEach(function (): void {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
});

function pluginPresentationOperator(bool $readOnly = false): User
{
    $actor = User::factory()->create();
    foreach ($readOnly ? ['settings-viewer'] : ['settings-payment', 'settings-notification', 'settings-identity', 'settings-site', 'settings-billing', 'upstream-operator'] as $role) {
        app(AssignAdminRoleAction::class)->execute($actor, $role, 'Isolated plugin UI role');
    }

    return $actor;
}

it('renders each installed plugin category as a table without a second navigation', function (string $category, array $identifiers): void {
    $response = $this->actingAs(pluginPresentationOperator())->get('/admin/settings?tab='.$category.'-plugins')->assertOk();
    foreach ($identifiers as $identifier) {
        $response->assertSee('<code>'.$identifier.'</code>', false);
    }
    $response->assertDontSee('PRIVATE-PRESENTATION')->assertDontSee('Yansongda')->assertDontSee('彩虹 / 1.0')
        ->assertDontSee('class="settings-nav"', false)->assertDontSee('id="settings-section-select"', false)
        ->assertSee('项目内置适配器')->assertSee('随系统发布');
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $rows = (new DOMXPath($document))->query('//table[@class="settings-plugin-table"]/tbody/tr');
    expect($rows->length)->toBe(count($identifiers));
})->with([
    'payment' => ['payment', ['payment.epay']],
    'mail' => ['mail', ['mail.smtp', 'mail.log']],
    'sms' => ['sms', ['sms.idcsmart', 'sms.log']],
    'kyc' => ['kyc', ['kyc.idcsmartali']],
    'login' => ['login', ['login.github', 'login.google', 'login.qq', 'login.wechat', 'login.telegram']],
]);

it('shows safe read only plugin lists and configuration without state or save forms', function (): void {
    $this->actingAs(pluginPresentationOperator(true));
    foreach (['payment', 'mail', 'sms', 'kyc', 'login'] as $category) {
        $this->get('/admin/settings?tab='.$category.'-plugins')->assertOk()->assertSee('只读')
            ->assertDontSee('action="/admin/settings/plugins/', false)->assertDontSee('PRIVATE-PRESENTATION');
    }
    $this->get('/admin/settings?tab=mail&plugin=smtp')->assertOk()->assertSee('已配置')
        ->assertDontSee('action="/admin/settings/identity/mail"', false)
        ->assertDontSee('action="/admin/settings/test/mail"', false)->assertDontSee('PRIVATE-PRESENTATION');
});

it('keeps activation on plugin lists and projects only blank secret fields into configuration', function (string $tab, string $plugin, array $hiddenSelectors): void {
    $this->actingAs(pluginPresentationOperator());
    $response = $this->get('/admin/settings?tab='.$tab.'&plugin='.$plugin)->assertOk()
        ->assertSee('name="plugin_context" value="'.$plugin.'"', false)->assertSee('返回插件列表')
        ->assertDontSee('PRIVATE-PRESENTATION')->assertDontSee('class="settings-nav"', false);
    foreach ($hiddenSelectors as $selector) {
        $response->assertDontSee('name="settings['.$selector.']"', false);
    }
    $snapshot = Livewire::test(SystemSettings::class)->set('tab', $tab)->set('plugin', $plugin)->snapshot;
    expect(json_encode($snapshot, JSON_THROW_ON_ERROR))->not->toContain('PRIVATE-PRESENTATION');
})->with([
    ['mail', 'smtp', ['mail.transport']],
    ['sms', 'idcsmart', ['sms.driver']],
    ['kyc', 'idcsmartali', ['kyc.driver', 'kyc.enabled']],
    ['github', 'github', ['oauth.github_enabled']],
    ['telegram', 'telegram', ['oauth.telegram_enabled']],
    ['payment', 'epay', []],
]);

it('rejects mismatched unknown and list-level plugin configuration contexts', function (string $query): void {
    $this->actingAs(pluginPresentationOperator())->get('/admin/settings?'.$query)->assertNotFound();
})->with(['tab=mail&plugin=epay', 'tab=payment&plugin=missing', 'tab=mail-plugins&plugin=smtp', 'tab=site&plugin=smtp']);

it('keeps all bookmarked configuration sections readable without inner menus', function (string $tab): void {
    $this->actingAs(pluginPresentationOperator())->get('/admin/settings?tab='.$tab)->assertOk()
        ->assertDontSee('class="settings-nav"', false)->assertDontSee('id="settings-section-select"', false)
        ->assertDontSee('PRIVATE-PRESENTATION');
})->with(['payment', 'site', 'mail', 'sms', 'kyc', 'github', 'google', 'qq', 'wechat', 'telegram', 'upstreams', 'billing', 'automation']);
