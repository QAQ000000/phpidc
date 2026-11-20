<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
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

function navigationOperator(array $permissions = []): User
{
    $operator = User::factory()->create();
    foreach ($permissions as $permission) {
        $operator->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $operator;
}

function navigationViewPermissions(): array
{
    return ['inventory.view', 'upstream.view', 'finance.view', 'promotion.view', 'affiliate.view',
        'support.view', 'cms.view', 'download.view', 'notification.view', 'settings.view',
        'tasks.view', 'access.view', 'audit.view', 'customers.view'];
}

/** @return array<NavigationGroup> */
function navigationForPath(string $url): array
{
    $request = Request::create($url);
    app()->instance('request', $request);
    app()->instance('originalRequest', $request);

    return Filament::getPanel('admin')->buildNavigation();
}

/** @param array<NavigationGroup> $groups
 * @return list<NavigationItem>
 */
function navigationItems(array $groups): array
{
    return array_values(array_merge(...array_map(fn (NavigationGroup $group): array => $group->getItems(), $groups)));
}

it('keeps every operational area and settings destination in one flat global navigation', function (): void {
    $this->actingAs(navigationOperator(navigationViewPermissions()));
    $groups = navigationForPath('/admin/audit');
    $items = navigationItems($groups);
    $urls = array_map(fn (NavigationItem $item): ?string => $item->getUrl(), $items);

    expect($groups)->toHaveCount(6)
        ->and($urls)->toHaveCount(31)->toHaveCount(count(array_unique($urls)))
        ->toEqualCanonicalizing([
            '/admin/inventory', '/admin/inventory/cards', '/admin/fulfillment', '/admin/upstreams',
            '/admin/upstream-instances', '/admin/finance', '/admin/finance/ledger', '/admin/finance/receivables',
            '/admin/wallet-clearances', '/admin/external-payouts', '/admin/payouts', '/admin/coupons',
            '/admin/affiliates', '/admin/support', '/admin/content', '/admin/downloads', '/admin/notifications', '/admin/customers',
            '/admin/notification-outbox', '/admin/tasks', '/admin/reconciliations', '/admin/access', '/admin/audit',
            '/admin/settings?tab=site', '/admin/settings?tab=payment-plugins', '/admin/settings?tab=billing',
            '/admin/settings?tab=mail-plugins', '/admin/settings?tab=sms-plugins', '/admin/settings?tab=kyc-plugins',
            '/admin/settings?tab=login-plugins', '/admin/settings?tab=automation',
        ]);

    foreach ($items as $item) {
        expect($item->getChildItems())->toBeEmpty();
    }
    foreach ($groups as $group) {
        expect($group->isCollapsible())->toBeTrue()
            ->and($group->isCollapsed())->toBe(! $group->isActive());
    }
});

it('uses existing page authorization and removes groups with no accessible options', function (): void {
    $this->actingAs(navigationOperator(['upstream.view']));
    $groups = navigationForPath('/admin/upstreams');
    expect(array_map(fn (NavigationGroup $group): ?string => $group->getLabel(), $groups))
        ->toEqualCanonicalizing(['商品与服务', '系统运维']);
    expect(array_map(fn (NavigationItem $item): ?string => $item->getUrl(), navigationItems($groups)))
        ->toEqualCanonicalizing(['/admin/upstreams', '/admin/upstream-instances', '/admin/reconciliations']);

    $this->actingAs(navigationOperator());
    expect(navigationForPath('/admin/settings'))->toBeEmpty();
});

it('shows all settings sections to settings viewers without granting access to operational areas', function (): void {
    $this->actingAs(navigationOperator(['settings.view']));
    $groups = navigationForPath('/admin/settings?tab=site');
    $items = navigationItems($groups);
    expect($groups)->toHaveCount(3)->and($items)->toHaveCount(8);
    foreach ($items as $item) {
        expect($item->getUrl())->toStartWith('/admin/settings?tab=');
    }
    // The approved dashboard UI makes /admin the panel home for every admitted role.
    expect(Filament::getPanel('admin')->getHomeUrl())->toBe('/admin');
});

it('highlights only the owning menu item for configuration and record details', function (string $request, string $activeUrl): void {
    $this->actingAs(navigationOperator(navigationViewPermissions()));
    $groups = navigationForPath($request);
    $active = array_values(array_filter(navigationItems($groups), fn (NavigationItem $item): bool => $item->isActive()));
    expect($active)->toHaveCount(1)->and($active[0]->getUrl())->toBe($activeUrl);
    foreach ($groups as $group) {
        expect($group->isCollapsible())->toBeTrue()
            ->and($group->isCollapsed())->toBe(! $group->isActive());
    }
})->with([
    ['/admin/settings', '/admin/settings?tab=payment-plugins'],
    ['/admin/settings?tab=site', '/admin/settings?tab=site'],
    ['/admin/settings?tab=payment', '/admin/settings?tab=payment-plugins'],
    ['/admin/settings?tab=payment-plugins', '/admin/settings?tab=payment-plugins'],
    ['/admin/settings?tab=mail', '/admin/settings?tab=mail-plugins'],
    ['/admin/settings?tab=mail-plugins', '/admin/settings?tab=mail-plugins'],
    ['/admin/settings?tab=sms', '/admin/settings?tab=sms-plugins'],
    ['/admin/settings?tab=sms-plugins', '/admin/settings?tab=sms-plugins'],
    ['/admin/settings?tab=kyc', '/admin/settings?tab=kyc-plugins'],
    ['/admin/settings?tab=kyc-plugins', '/admin/settings?tab=kyc-plugins'],
    ['/admin/settings?tab=github', '/admin/settings?tab=login-plugins'],
    ['/admin/settings?tab=google', '/admin/settings?tab=login-plugins'],
    ['/admin/settings?tab=qq', '/admin/settings?tab=login-plugins'],
    ['/admin/settings?tab=wechat', '/admin/settings?tab=login-plugins'],
    ['/admin/settings?tab=telegram', '/admin/settings?tab=login-plugins'],
    ['/admin/settings?tab=login-plugins', '/admin/settings?tab=login-plugins'],
    ['/admin/settings?tab=billing', '/admin/settings?tab=billing'],
    ['/admin/settings?tab=automation', '/admin/settings?tab=automation'],
    ['/admin/settings?tab=upstreams', '/admin/upstreams'],
    ['/admin/finance?status=Paid', '/admin/finance'],
    ['/admin/finance/ledger?invoice_id=7', '/admin/finance/ledger'],
    ['/admin/finance/receivables?clawback=3', '/admin/finance/receivables'],
    ['/admin/finance/invoice?invoice_id=7', '/admin/finance'],
    ['/admin/customers/7', '/admin/customers'],
    ['/admin/machine-operation-review?instance_id=7', '/admin/upstream-instances'],
    ['/admin/upstream-instances/7/mapping-review', '/admin/upstream-instances'],
    ['/admin/upstream-instances/7/mapping-review/check', '/admin/upstream-instances'],
]);

it('renders the registered menu once in the panel sidebar without exposing record-only routes', function (): void {
    $this->actingAs(navigationOperator(navigationViewPermissions()));
    $response = $this->get('/admin/audit')->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    $xpath = new DOMXPath($document);
    $navigation = $xpath->query('//nav[contains(concat(" ", normalize-space(@class), " "), " fi-sidebar-nav ")]');
    expect($navigation)->toHaveCount(1);
    // Filament pre-renders the same approved menu for expanded and collapsed desktop modes.
    foreach ([
        './/ul[contains(concat(" ", normalize-space(@class), " "), " fi-sidebar-group-items ")]//a[@href]',
        './/div[contains(concat(" ", normalize-space(@class), " "), " fi-dropdown-panel ")]//a[@href]',
    ] as $mode) {
        $links = $xpath->query($mode, $navigation->item(0));
        $urls = [];
        foreach ($links as $link) {
            $urls[] = $link->getAttribute('href');
        }
        expect($urls)->toHaveCount(31)->toHaveCount(count(array_unique($urls)))
            ->not->toContain('/admin/finance/invoice', '/admin/machine-operation-review');
    }
    Http::assertNothingSent();
});
