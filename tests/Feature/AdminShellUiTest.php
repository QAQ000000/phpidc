<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Http::preventStrayRequests();
});

function shellUiOperator(array $permissions): User
{
    $actor = User::factory()->create();
    foreach ($permissions as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $actor;
}

it('uses the same blue brand with a full width and native collapsible admin shell', function (): void {
    $panel = Filament::getPanel('admin');

    expect($panel->getColors()['primary'])->toBe('#2f54ea')
        ->and($panel->getMaxContentWidth())->toBe('full')
        ->and($panel->isSidebarCollapsibleOnDesktop())->toBeTrue();
});

it('loads the shared shell stylesheet once on operational and configuration pages', function (string $permission, string $path, string $contentClass): void {
    $this->actingAs(shellUiOperator([$permission]));
    $response = $this->get($path)->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    $xpath = new DOMXPath($document);

    expect($xpath->query('//head/link[@rel="stylesheet"][@href="/css/admin-shell.css?v=20261120"]'))->toHaveCount(1)
        ->and($xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," '.$contentClass.' ")]'))->toHaveCount(1)
        ->and($xpath->query('//nav[contains(concat(" ",normalize-space(@class)," ")," fi-sidebar-nav ")]'))->toHaveCount(1);
    Http::assertNothingSent();
})->with([
    ['upstream.view', '/admin/upstreams', 'admin-ui'],
    ['settings.view', '/admin/settings?tab=site', 'settings-center'],
    ['settings.view', '/admin/settings?tab=payment-plugins', 'settings-center'],
]);

it('renders every native group and item icon while keeping navigation permissions scoped', function (): void {
    $this->actingAs(shellUiOperator(['inventory.view', 'upstream.view', 'finance.view', 'promotion.view', 'affiliate.view',
        'support.view', 'cms.view', 'download.view', 'notification.view', 'settings.view', 'tasks.view', 'access.view', 'audit.view']));
    $request = Request::create('/admin/audit');
    app()->instance('request', $request);
    app()->instance('originalRequest', $request);
    $groups = Filament::getPanel('admin')->buildNavigation();
    expect($groups)->toHaveCount(6);
    foreach ($groups as $group) {
        expect($group->getIcon())->toStartWith('heroicon-o-');
        foreach ($group->getItems() as $item) {
            expect($item->getIcon())->toStartWith('heroicon-o-');
        }
    }
    $this->get('/admin/audit')->assertOk();

    $this->actingAs(shellUiOperator(['settings.view']));
    $response = $this->get('/admin/settings?tab=site')->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    $xpath = new DOMXPath($document);
    $sidebars = $xpath->query('//nav[contains(concat(" ",normalize-space(@class)," ")," fi-sidebar-nav ")]');
    expect($sidebars)->toHaveCount(1);
    $urls = [];
    foreach ($xpath->query('.//a[@href]', $sidebars->item(0)) as $link) {
        $urls[] = $link->getAttribute('href');
    }
    expect(array_unique($urls))->toHaveCount(8);
    foreach ($urls as $url) {
        expect($url)->toStartWith('/admin/settings?tab=');
    }
    Http::assertNothingSent();
});
