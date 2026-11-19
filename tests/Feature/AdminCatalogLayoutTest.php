<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Filament\Pages\ClassicCatalog;
use App\Models\UpstreamApi;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Http::preventStrayRequests();
});

function catalogLayoutOperator(bool $canImport = true): User
{
    $actor = User::factory()->create();
    app(AssignAdminRoleAction::class)->execute($actor, $canImport ? 'upstream-operator' : 'upstream-viewer', 'Isolated catalog UI role');

    return $actor;
}

function catalogLayoutResponse(array $catalog): void
{
    Http::fake(['*/zjmf_api_login' => Http::response(['status' => 200, 'jwt' => 'PRIVATE-CATALOG-JWT']),
        '*/cart/all*' => Http::response(['status' => 200, 'data' => $catalog])]);
}

it('keeps the native sidebar and existing import form when reading an authenticated supplier directory', function (): void {
    $actor = catalogLayoutOperator();
    $account = UpstreamApi::factory()->create();
    $catalog = ['private_note' => 'PRIVATE-CATALOG-TOP', 'products' => [['name' => 'Cloud group',
        'private_note' => 'PRIVATE-CATALOG-GROUP', 'products' => [['id' => 77, 'name' => 'Chosen cloud', 'private_note' => 'PRIVATE-CATALOG-PRODUCT']]]]];
    catalogLayoutResponse($catalog);
    $response = $this->actingAs($actor)->post('/admin/upstreams/catalog', ['account_id' => $account->id])->assertOk()
        ->assertViewHas('catalog', $catalog)->assertViewHas('accountId', $account->id)
        ->assertHeader('Cache-Control', 'no-store, private')->assertSee('上游连接 #'.$account->id.' 商品目录')
        ->assertSee('Chosen cloud')->assertSee('action="/admin/upstreams/import"', false)
        ->assertSee('name="account_id" value="'.$account->id.'"', false)->assertSee('name="pid" value="77"', false)
        ->assertSee('name="ui_dialog" value="upstream-import"', false)->assertSee('name="ui_form" value="classic-import"', false)
        ->assertSee('name="cycle"', false)->assertSee('name="currency" value="CNY"', false)
        ->assertSee('name="markup" value="20.00"', false)->assertSee('导入并同步此商品')
        ->assertDontSee('PRIVATE-CATALOG')->assertDontSee('产品商城')->assertDontSee('我的账单');
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    $xpath = new DOMXPath($document);
    $sidebars = $xpath->query('//nav[contains(concat(" ", normalize-space(@class), " "), " fi-sidebar-nav ")]');
    expect($sidebars)->toHaveCount(1);
    expect($xpath->query('.//li[contains(concat(" ", normalize-space(@class), " "), " fi-active ")]/a[@href="/admin/upstreams"]', $sidebars->item(0)))->toHaveCount(1);
    Http::assertSentCount(2);
    $this->assertDatabaseCount('products', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
});

it('returns a failed directory import to the main page with visible errors and submitted fields without another supplier request', function (): void {
    $account = UpstreamApi::factory()->create();
    $this->actingAs(catalogLayoutOperator())->from('/admin/upstreams/catalog')->post('/admin/upstreams/import', [
        'ui_dialog' => 'upstream-import', 'ui_form' => 'classic-import',
        'account_id' => $account->id, 'pid' => '77', 'cycle' => 'quarterly',
        'currency' => 'USD', 'markup' => 'invalid-markup', 'reason' => '待修正的导入参数',
    ])->assertRedirect('/admin/upstreams')->assertSessionHasErrors('markup');
    $markupError = session('errors')->first('markup');
    $response = $this->get('/admin/upstreams')->assertOk()->assertSee('upstream-import');
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    $xpath = new DOMXPath($document);
    $dialog = '//dialog[@id="upstream-import"]';
    $alerts = $xpath->query($dialog.'//*[@role="alert"]');
    expect($alerts)->toHaveCount(1)->and($alerts->item(0)->textContent)->toContain($markupError)
        ->and($xpath->query($dialog.'//input[@name="pid"][@value="77"]'))->toHaveCount(1)
        ->and($xpath->query($dialog.'//input[@name="currency"][@value="USD"]'))->toHaveCount(1)
        ->and($xpath->query($dialog.'//input[@name="markup"][@value="invalid-markup"]'))->toHaveCount(1)
        ->and($xpath->query($dialog.'//input[@name="reason"][@value="待修正的导入参数"]'))->toHaveCount(1)
        ->and($xpath->query($dialog.'//select[@name="cycle"]/option[@value="quarterly"][@selected]'))->toHaveCount(1)
        ->and($xpath->query($dialog.'//select[@name="account_id"]/option[@value="'.$account->id.'"][@selected]'))->toHaveCount(1);
    $this->assertDatabaseCount('products', 0);
    Http::assertNothingSent();
});

it('checks catalog permissions before remote requests and allows read only operators without import forms', function (): void {
    $account = UpstreamApi::factory()->create();
    $this->post('/admin/upstreams/catalog', ['account_id' => $account->id])->assertRedirect('/admin/login');
    $this->actingAs(User::factory()->create())->post('/admin/upstreams/catalog', ['account_id' => $account->id])->assertForbidden();
    Http::assertNothingSent();
    catalogLayoutResponse(['products' => [['groupname' => 'Read only group', 'products' => [['id' => 78, 'name' => 'Read only product']]]]]);
    $this->actingAs(catalogLayoutOperator(false))->post('/admin/upstreams/catalog', ['account_id' => $account->id])->assertOk()
        ->assertSee('Read only group')->assertSee('Read only product')->assertDontSee('action="/admin/upstreams/import"', false)
        ->assertDontSee('导入并同步此商品');
    Http::assertSentCount(2);
});

it('projects only visible directory metadata into Livewire state and renders an empty directory', function (): void {
    $this->actingAs(catalogLayoutOperator());
    $catalog = ['private_note' => 'PRIVATE-CATALOG-TOP', 'products' => [['name' => 'Only visible group',
        'private_note' => 'PRIVATE-CATALOG-GROUP', 'products' => [['id' => 79, 'name' => '<script>danger()</script>', 'secret' => 'PRIVATE-CATALOG-PRODUCT']]]]];
    $page = Livewire::test(ClassicCatalog::class, ['accountId' => 1, 'catalog' => $catalog])
        ->assertSet('groups', [['name' => 'Only visible group', 'products' => [['id' => 79, 'name' => '<script>danger()</script>']]]])
        ->assertSee('<script>danger()</script>')->assertDontSee('<script>danger()</script>', false)->assertDontSee('PRIVATE-CATALOG');
    expect(json_encode($page->snapshot, JSON_THROW_ON_ERROR))->not->toContain('PRIVATE-CATALOG');
    Livewire::test(ClassicCatalog::class, ['accountId' => 1, 'catalog' => ['products' => []]])->assertSee('上游目录没有商品。');
    Http::assertNothingSent();
});

it('registers the existing catalog post exactly once under native authenticated panel middleware', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route): bool => $route->uri() === 'admin/upstreams/catalog');
    expect($routes)->toHaveCount(1)->and($routes->sole()->methods())->toBe(['POST']);
    $middleware = app('router')->gatherRouteMiddleware($routes->sole());
    expect($middleware)->toContain('Filament\Http\Middleware\SetUpPanel:admin', 'Filament\Http\Middleware\Authenticate', 'Illuminate\Foundation\Http\Middleware\PreventRequestForgery');
    $this->get('/admin/upstreams/catalog')->assertNotFound();
    Http::assertNothingSent();
});
