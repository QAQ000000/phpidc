<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Actions\SaveSiteSettingsAction;
use App\Models\CmsArticle;
use App\Models\CmsCategory;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Http::preventStrayRequests();
});

function presentationOperator(string $role): User
{
    $actor = User::factory()->create();
    app(AssignAdminRoleAction::class)->execute($actor, $role, 'Isolated presentation role');

    return $actor;
}

it('renders saved branding and plain announcements in storefront and customer layouts', function (): void {
    $operator = presentationOperator('settings-site');
    $this->actingAs($operator)->post('/admin/settings/site', ['settings' => [
        'site.name' => '配置验收商城', 'site.logo_url' => '/favicon.svg', 'site.icp_number' => '测试备123号',
        'site.copyright' => '测试版权 2026', 'site.support_url' => '/help', 'site.announcement' => '<script>alert("plain")</script>',
        'site.seo_title' => '首页专用标题', 'site.seo_description' => '首页专用简介', 'site.llms_description' => '本站公开资料与选购指南。',
    ]])->assertRedirect('/admin/settings?tab=site')->assertSessionHasNoErrors();
    $this->get('/')->assertOk()->assertSee('<title>首页专用标题 · 配置验收商城</title>', false)
        ->assertSee('<meta name="description" content="首页专用简介">', false)
        ->assertSee('src="/favicon.svg"', false)->assertSee('测试备123号')->assertSee('测试版权 2026')
        ->assertSee('href="/help"', false)->assertSee('<script>alert("plain")</script>')->assertDontSee('<script>alert("plain")</script>', false);
    $this->get('/console')->assertOk()->assertSee('配置验收商城')->assertSee('测试版权 2026')->assertSee('测试备123号')
        ->assertDontSee('<script>alert("plain")</script>', false);
    $this->get('/llms.txt')->assertOk()->assertSee('# 配置验收商城', false)->assertSee('本站公开资料与选购指南。');
    Http::assertNothingSent();
});

it('uses saved site defaults without overriding product article SEO or canonical URLs', function (): void {
    config(['app.url' => 'https://canonical.example.test']);
    $operator = presentationOperator('settings-site');
    app(SaveSiteSettingsAction::class)->execute($operator, ['site.name' => '商城名称', 'site.seo_title' => '默认标题', 'site.seo_description' => '默认简介']);
    $category = CmsCategory::create(['type' => 'help', 'name' => '使用帮助', 'slug' => 'settings-guides']);
    $article = CmsArticle::create(['cms_category_id' => $category->id, 'title' => '文章标题', 'slug' => 'settings-guide',
        'body' => '使用方法', 'content_format' => 'markdown', 'seo_title' => '文章专属标题', 'seo_description' => '文章专属简介',
        'is_published' => true, 'published_at' => now()]);
    $this->get($article->publicPath())->assertOk()->assertSee('<title>文章专属标题 · 商城名称</title>', false)
        ->assertSee('<meta name="description" content="文章专属简介">', false)
        ->assertSee('href="https://canonical.example.test'.$article->publicPath().'"', false)->assertDontSee('<title>默认标题', false);
    $product = Product::factory()->create(['name' => '产品专属名称', 'description' => '产品专属简介']);
    $this->get($product->publicPath())->assertOk()->assertSee('<title>产品专属名称 · 商城名称</title>', false)
        ->assertSee('<meta name="description" content="产品专属简介">', false);
    $this->get('/sitemap.xml')->assertOk()->assertSee('https://canonical.example.test/', false);
    $this->get($article->publicPath().'.md')->assertOk()->assertSee('站点：商城名称', false)->assertSee('使用方法');
});

it('falls back to existing site text after clearing optional display settings', function (): void {
    $operator = presentationOperator('settings-site');
    app(SaveSiteSettingsAction::class)->execute($operator, ['site.name' => '测试站点', 'site.seo_title' => '临时标题', 'site.seo_description' => '临时简介', 'site.llms_description' => '临时索引']);
    $this->actingAs($operator)->post('/admin/settings/site', ['settings' => [
        'site.seo_title' => '', 'site.seo_description' => '', 'site.llms_description' => '', 'site.announcement' => '',
    ]])->assertSessionHasNoErrors();
    $this->get('/')->assertOk()->assertSee('<title>云产品与使用指南 · 测试站点</title>', false)
        ->assertSee('发现云服务、数字产品与实用的产品使用指南。');
    $this->get('/llms.txt')->assertSee('公开产品、新闻与使用指南。价格以创建账单时的服务端报价为准。');
});

it('provides independent site and strategy roles without exposing cross-domain save authority', function (): void {
    $site = presentationOperator('settings-site');
    $billing = presentationOperator('settings-billing');
    $this->actingAs($site)->get('/admin/settings?tab=site')->assertOk()->assertSee('action="/admin/settings/site"', false);
    $this->get('/admin/settings?tab=billing')->assertOk()->assertDontSee('action="/admin/settings/policy/billing"', false);
    $this->post('/admin/settings/policy/billing', ['settings' => ['billing.invoice_expiry_minutes' => '60']])->assertForbidden();
    $this->actingAs($billing)->get('/admin/settings?tab=billing')->assertOk()
        ->assertSee('action="/admin/settings/policy/billing"', false)->assertSee('自动续费账单提前量');
    $this->get('/admin/settings?tab=automation')->assertOk()->assertSee('action="/admin/settings/policy/automation"', false);
    $this->post('/admin/settings/site', ['settings' => ['site.name' => '越权商城']])->assertForbidden();
});

it('saves real policy keys through their dedicated forms and rejects invented or cross-section keys', function (): void {
    $operator = presentationOperator('settings-billing');
    $this->actingAs($operator)->post('/admin/settings/policy/billing', ['settings' => [
        'billing.invoice_expiry_minutes' => '60', 'billing.renewal_notice_days' => '0',
        'billing.renewal_grace_days' => '3', 'affiliate.freeze_days' => '10',
    ]])->assertRedirect('/admin/settings?tab=billing')->assertSessionHasNoErrors();
    $this->assertDatabaseHas('system_settings', ['group' => 'billing', 'key' => 'invoice_expiry_minutes', 'value' => '60']);
    $this->post('/admin/settings/policy/automation', ['settings' => ['lifecycle.urgent_batch' => '20', 'lifecycle.rotation_batch' => '30', 'lifecycle.catalog_batch' => '40']])
        ->assertRedirect('/admin/settings?tab=automation')->assertSessionHasNoErrors();
    $this->post('/admin/settings/policy/billing', ['settings' => ['billing.unpaid_expire_minutes' => '10']])->assertSessionHasErrors('settings');
    $this->post('/admin/settings/policy/automation', ['settings' => ['affiliate.freeze_days' => '1']])->assertSessionHasErrors('settings');
    $this->assertDatabaseMissing('system_settings', ['group' => 'billing', 'key' => 'unpaid_expire_minutes']);
    expect(DB::table('system_settings')->where('group', 'affiliate')->where('key', 'freeze_days')->value('value'))->toBe('10');
});

it('does not expose deferred money admission maintenance or alert-recipient settings as writable fields', function (): void {
    $operator = presentationOperator('settings-billing');
    foreach (['wallet.topup_min_amount', 'billing.require_kyc', 'site.maintenance_enabled', 'operations.email'] as $key) {
        $this->actingAs($operator)->post('/admin/settings/policy/billing', ['settings' => [$key => '1']])->assertSessionHasErrors('settings');
    }
    $this->get('/admin/settings?tab=billing')->assertOk()->assertDontSee('name="settings[wallet.topup_min_amount]"', false)
        ->assertDontSee('name="settings[billing.require_kyc]"', false);
});

it('rejects oversized new settings audit notes before writing to MySQL-sized reason columns', function (string $role, string $path, array $settings): void {
    $operator = presentationOperator($role);
    $this->actingAs($operator)->post($path, ['settings' => $settings, 'reason' => str_repeat('备注', 128)])
        ->assertSessionHasErrors('reason');
    expect(DB::table('audit_logs')->where('actor_id', $operator->id)->count())->toBe(0);
})->with([
    ['settings-site', '/admin/settings/site', ['site.name' => '备注长度测试']],
    ['settings-billing', '/admin/settings/policy/billing', ['billing.invoice_expiry_minutes' => '60']],
    ['settings-notification', '/admin/settings/identity/mail', ['mail.transport' => 'log']],
]);
