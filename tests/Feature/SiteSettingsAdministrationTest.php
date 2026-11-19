<?php

declare(strict_types=1);

use App\Actions\SaveSiteSettingsAction;
use App\Models\User;
use App\Services\SettingService;
use App\Support\SiteSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
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

function siteSettingsAdmin(array $permissions = ['settings.view', 'settings.site.manage']): User
{
    $actor = User::factory()->create();
    foreach ($permissions as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $actor;
}

it('defines the existing public content defaults without reading settings or installing a second namespace', function (): void {
    config(['app.name' => 'Configured Bootstrap Name']);
    $fields = SiteSettings::fields();
    expect(array_keys($fields))->toBe(['site.name', 'site.logo_url', 'site.icp_number', 'site.copyright', 'site.support_url',
        'site.announcement', 'site.seo_title', 'site.seo_description', 'site.llms_description'])
        ->and($fields['site.name']['default'])->toBe('Configured Bootstrap Name')
        ->and($fields['site.seo_title']['default'])->toBe('云产品与使用指南')
        ->and($fields['site.seo_description']['default'])->toBe('发现云服务、数字产品与实用的产品使用指南。')
        ->and($fields['site.llms_description']['default'])->toBe('公开产品、新闻与使用指南。价格以创建账单时的服务端报价为准。');
    expect(DB::table('system_settings')->where('group', 'site')->count())->toBe(0);
});

it('requires both site permissions on a currently active administrator', function (string $case): void {
    $permissions = match ($case) {
        'no-view' => ['settings.site.manage'],
        'no-manage' => ['settings.view'],
        'wrong-domain' => ['settings.view', 'settings.payment.manage'],
        default => ['settings.view', 'settings.site.manage'],
    };
    $actor = siteSettingsAdmin($permissions);
    if ($case === 'deleted') {
        $actor->delete();
    } elseif ($case === 'anonymized') {
        User::whereKey($actor->id)->update(['anonymized_at' => now()]);
    } elseif ($case === 'revoked') {
        expect($actor->can('settings.site.manage'))->toBeTrue();
        DB::table('model_has_permissions')->where('model_id', $actor->id)
            ->where('permission_id', Permission::findByName('settings.site.manage', 'web')->id)->delete();
    }
    $beforeSettings = DB::table('system_settings')->orderBy('id')->get()->toArray();
    $beforeAudit = DB::table('audit_logs')->count();
    expect(fn () => app(SaveSiteSettingsAction::class)->execute($actor, ['site.name' => 'Forbidden']))->toThrow(AuthorizationException::class);
    expect(DB::table('system_settings')->orderBy('id')->get()->toArray())->toEqual($beforeSettings)
        ->and(DB::table('audit_logs')->count())->toBe($beforeAudit);
})->with(['no-view', 'no-manage', 'wrong-domain', 'deleted', 'anonymized', 'revoked']);

it('saves only submitted site values with a safe contextual key-only administrator audit', function (): void {
    $actor = siteSettingsAdmin();
    $settings = [
        'site.name' => ' 测试主机 ', 'site.logo_url' => '/assets/logo.svg', 'site.icp_number' => '测试ICP备123号',
        'site.copyright' => '© 测试主机', 'site.support_url' => 'https://support.example.test/contact',
        'site.announcement' => "维护 < 5 分钟 & 状态正常。\n请查看公告。",
        'site.seo_title' => '站点默认标题', 'site.seo_description' => '站点默认简介', 'site.llms_description' => '供公开索引使用的站点简介。',
    ];
    $originalUrl = config('app.url');
    app(SaveSiteSettingsAction::class)->execute($actor, $settings);
    foreach ($settings as $key => $value) {
        expect(app(SettingService::class)->get($key))->toBe($key === 'site.name' ? trim($value) : $value);
    }
    $audit = DB::table('audit_logs')->where('action', 'site.configure')->sole();
    expect($audit->actor_type)->toBe('user')->and($audit->actor_id)->toBe($actor->id)
        ->and($audit->reason)->toBe('更新站点信息与展示配置')->and($audit->request_id)->not->toBeNull()
        ->and(json_decode($audit->after, true))->toBe(['keys' => array_keys($settings)])
        ->and(json_encode($audit))->not->toContain('测试主机', '站点默认简介')
        ->and(DB::table('system_settings')->where('group', 'site')->where('is_encrypted', true)->count())->toBe(0)
        ->and(config('app.url'))->toBe($originalUrl);
});

it('keeps partial values and allows explicitly clearing optional display fields', function (): void {
    $actor = siteSettingsAdmin();
    $action = app(SaveSiteSettingsAction::class);
    $action->execute($actor, ['site.name' => '保留名称', 'site.announcement' => '原公告', 'site.logo_url' => '/logo.svg']);
    $action->execute($actor, ['site.announcement' => null, 'site.logo_url' => ''], '  清空公告与 Logo  ');
    expect(app(SettingService::class)->get('site.name'))->toBe('保留名称')
        ->and(app(SettingService::class)->get('site.announcement'))->toBe('')
        ->and(app(SettingService::class)->get('site.logo_url'))->toBe('')
        ->and(DB::table('audit_logs')->where('action', 'site.configure')->orderByDesc('id')->value('reason'))->toBe('清空公告与 Logo');
});

it('bounds optional site audit notes to the database column before any writes', function (): void {
    $actor = siteSettingsAdmin();
    $action = app(SaveSiteSettingsAction::class);
    $action->execute($actor, ['site.name' => 'Original'], str_repeat('n', 255));
    $beforeSettings = DB::table('system_settings')->orderBy('id')->get()->toArray();
    $beforeAudit = DB::table('audit_logs')->count();
    expect(fn () => $action->execute($actor, ['site.name' => 'Must not save'], str_repeat('n', 256)))
        ->toThrow(ValidationException::class);
    expect(DB::table('system_settings')->orderBy('id')->get()->toArray())->toEqual($beforeSettings)
        ->and(DB::table('audit_logs')->count())->toBe($beforeAudit)
        ->and(DB::table('audit_logs')->where('action', 'site.configure')->sole()->reason)->toBe(str_repeat('n', 255));
});

it('rejects unsupported keys atomically without changing payment, bootstrap or other site values', function (string $key): void {
    $actor = siteSettingsAdmin();
    $before = DB::table('system_settings')->orderBy('id')->get()->toArray();
    expect(fn () => app(SaveSiteSettingsAction::class)->execute($actor, ['site.name' => 'Must not save', $key => 'forbidden']))
        ->toThrow(ValidationException::class);
    expect(DB::table('system_settings')->orderBy('id')->get()->toArray())->toEqual($before)
        ->and(DB::table('audit_logs')->where('action', 'site.configure')->count())->toBe(0);
})->with(['site.unknown', 'payment.epay_key', 'APP_URL', 'site.app_url', 'wallet.topup_enabled']);

it('rejects blank names, invalid types and oversized content before any writes', function (array $settings): void {
    expect(fn () => app(SaveSiteSettingsAction::class)->execute(siteSettingsAdmin(), $settings))->toThrow(ValidationException::class);
    expect(DB::table('system_settings')->where('group', 'site')->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'site.configure')->count())->toBe(0);
})->with([
    'empty-name' => [['site.name' => '']],
    'blank-name' => [['site.name' => '   ']],
    'null-name' => [['site.name' => null]],
    'array-name' => [['site.name' => ['nested']]],
    'number-name' => [['site.name' => 123]],
    'oversized-title' => [['site.seo_title' => str_repeat('x', 256)]],
    'oversized-description' => [['site.seo_description' => str_repeat('x', 1001)]],
    'oversized-announcement' => [['site.announcement' => str_repeat('x', 5001)]],
]);

it('accepts absolute HTTP links and single-slash local paths for site assets and customer support', function (string $url): void {
    $actor = siteSettingsAdmin();
    app(SaveSiteSettingsAction::class)->execute($actor, ['site.logo_url' => $url, 'site.support_url' => $url]);
    expect(app(SettingService::class)->get('site.logo_url'))->toBe($url)
        ->and(app(SettingService::class)->get('site.support_url'))->toBe($url);
})->with(['https://assets.example.test/logo.svg', 'http://support.example.test/contact', '/assets/logo.svg', '/console/tickets?new=1', '/']);

it('refuses script schemes, network paths, backslashes and URL control characters atomically', function (string $url): void {
    $actor = siteSettingsAdmin();
    $before = DB::table('system_settings')->orderBy('id')->get()->toArray();
    foreach (['site.logo_url', 'site.support_url'] as $key) {
        expect(fn () => app(SaveSiteSettingsAction::class)->execute($actor, ['site.name' => 'Must not save', $key => $url]))
            ->toThrow(ValidationException::class);
    }
    expect(DB::table('system_settings')->orderBy('id')->get()->toArray())->toEqual($before)
        ->and(DB::table('audit_logs')->where('action', 'site.configure')->count())->toBe(0);
})->with([
    'javascript:alert(1)', 'data:text/html,<script>alert(1)</script>', '//other.example.test/path',
    '/\\other.example.test/path', 'https:\\other.example.test/path', 'relative/logo.svg', 'https://',
    "/\t/other.example.test/path", "https://safe.example.test/\npath", "https://safe.example.test/\0path",
    ' https://safe.example.test/path', '/logo image.svg',
]);

it('retains warm caches until commit and rolls back settings together with their audit', function (): void {
    $actor = siteSettingsAdmin();
    $action = app(SaveSiteSettingsAction::class);
    $action->execute($actor, ['site.name' => 'Original', 'site.announcement' => 'Original announcement']);
    $settings = app(SettingService::class);
    expect($settings->get('site.name'))->toBe('Original');
    $beforeAudit = DB::table('audit_logs')->count();
    DB::beginTransaction();
    $action->execute($actor, ['site.name' => 'Tentative', 'site.announcement' => 'Tentative announcement']);
    expect($settings->get('site.name'))->toBe('Original')->and(Cache::get('setting:site:name')['value'])->toBe('Original');
    DB::rollBack();
    expect(DB::table('system_settings')->where('group', 'site')->where('key', 'name')->value('value'))->toBe('Original')
        ->and(DB::table('audit_logs')->count())->toBe($beforeAudit)->and($settings->get('site.name'))->toBe('Original');
    $action->execute($actor, ['site.name' => 'Committed']);
    expect($settings->get('site.name'))->toBe('Committed')->and($settings->get('site.announcement'))->toBe('Original announcement')
        ->and(Cache::get('setting:site:name')['value'])->toBe('Committed');
});
