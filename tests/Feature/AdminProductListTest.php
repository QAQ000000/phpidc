<?php

declare(strict_types=1);

use App\Actions\AssignUpstreamRoleAction;
use App\Enums\DriverType;
use App\Filament\Pages\UpstreamAccounts;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionItem;
use App\Models\UpstreamApi;
use App\Models\User;
use Illuminate\Http\Request;
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

afterEach(function (): void {
    Http::assertNothingSent();
});

function productListOperator(string $role = 'upstream-viewer'): User
{
    $actor = User::factory()->create();
    app(AssignUpstreamRoleAction::class)->execute($actor, $role, 'Isolated product list authority');

    return $actor;
}

function productListData(string $query = ''): array
{
    $request = Request::create('/admin/upstreams'.($query === '' ? '' : '?'.$query));
    app()->instance('request', $request);
    app()->instance('originalRequest', $request);

    return (new ReflectionMethod(UpstreamAccounts::class, 'getViewData'))->invoke(app(UpstreamAccounts::class));
}

function productListProduct(UpstreamApi $account, array $attributes = []): Product
{
    return Product::factory()->create($attributes + ['driver_type' => DriverType::ZJMF_API,
        'upstream_api_id' => $account->id, 'upstream_pid' => fake()->unique()->numberBetween(1000, 999999)]);
}

function productListXpath(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

    return new DOMXPath($document);
}

it('keeps listing queries lightweight and fetches option rows only for the selected classic product', function (): void {
    $account = UpstreamApi::factory()->create();
    $first = productListProduct($account, ['provisioning_rules' => ['private' => str_repeat('large-payload', 1000)]]);
    $second = productListProduct($account);
    $firstGroup = ProductOptionGroup::create(['product_id' => $first->id, 'name' => 'First group', 'type' => 'select']);
    ProductOptionItem::create(['option_group_id' => $firstGroup->id, 'label' => 'First option']);
    ProductOptionGroup::create(['product_id' => $second->id, 'name' => 'Second group', 'type' => 'quantity']);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $data = productListData();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($data['selectedProduct'])->toBeNull()->and($data['products']->pluck('id')->all())->toBe([$second->id, $first->id]);
    foreach ($data['products'] as $product) {
        expect($product->option_group_count)->toBe(1)->and($product->relationLoaded('optionGroups'))->toBeFalse()
            ->and(array_key_exists('provisioning_rules', $product->getAttributes()))->toBeFalse()
            ->and(array_key_exists('upstream_base_prices', $product->getAttributes()))->toBeFalse();
    }
    expect(array_filter($queries, fn (array $query): bool => str_contains($query['query'], 'product_option_items')))->toBeEmpty();

    $selected = productListData('q=does-not-match&product='.$first->id);
    expect($selected['products']->total())->toBe(0)->and($selected['selectedProduct']->id)->toBe($first->id)
        ->and($selected['selectedProduct']->optionGroups->pluck('id')->all())->toBe([$firstGroup->id])
        ->and($selected['selectedProduct']->optionGroups->first()->relationLoaded('items'))->toBeTrue()
        ->and($selected['selectedProduct']->optionGroups->first()->items->pluck('label')->all())->toBe(['First option']);
});

it('combines search connection and local visibility filters without including other drivers', function (): void {
    $one = UpstreamApi::factory()->create();
    $two = UpstreamApi::factory()->create();
    $match = productListProduct($one, ['name' => 'Needle cloud', 'is_hidden' => true]);
    productListProduct($one, ['name' => 'Needle visible', 'is_hidden' => false]);
    productListProduct($two, ['name' => 'Needle other account', 'is_hidden' => true]);
    productListProduct($one, ['name' => 'Another hidden cloud', 'is_hidden' => true]);
    Product::factory()->create(['name' => 'Needle local pool', 'is_hidden' => true]);

    $data = productListData('q=Needle&account_id='.$one->id.'&visibility=hidden');
    expect($data['products']->pluck('id')->all())->toBe([$match->id])
        ->and($data['filters'])->toBe(['q' => 'Needle', 'account_id' => (string) $one->id, 'visibility' => 'hidden']);
});

it('finds exact local and upstream numeric ids in addition to product names', function (): void {
    $account = UpstreamApi::factory()->create();
    $local = productListProduct($account, ['name' => 'Alpha cloud']);
    $remote = productListProduct($account, ['name' => 'Beta cloud', 'upstream_pid' => 700005]);
    expect(productListData('q='.$local->id)['products']->pluck('id')->all())->toBe([$local->id])
        ->and(productListData('q=700005')['products']->pluck('id')->all())->toBe([$remote->id])
        ->and(productListData('q=Alpha')['products']->pluck('id')->all())->toBe([$local->id]);
});

it('paginates in stable id order and retains only approved filters in pagination and detail return links', function (): void {
    $account = UpstreamApi::factory()->create();
    $products = Product::factory()->count(23)->sequence(fn ($sequence): array => ['name' => 'Paged cloud '.$sequence->index,
        'driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id, 'upstream_pid' => 700100 + $sequence->index])->create();
    $data = productListData('q=Paged&account_id='.$account->id.'&visibility=visible&page=2&product='.$products->last()->id.'&dialog=connections&injected=untrusted');
    expect($data['products']->total())->toBe(23)->and($data['products']->perPage())->toBe(20)
        ->and($data['products']->pluck('id')->all())->toBe($products->take(3)->reverse()->pluck('id')->all())
        ->and($data['selectedProduct']->id)->toBe($products->last()->id);
    parse_str(parse_url($data['products']->url(1), PHP_URL_QUERY), $paginationQuery);
    parse_str(parse_url($data['detailReturnUrl'], PHP_URL_QUERY), $returnQuery);
    expect($paginationQuery)->toBe(['q' => 'Paged', 'account_id' => (string) $account->id, 'visibility' => 'visible', 'page' => '1'])
        ->and($returnQuery)->toBe(['q' => 'Paged', 'account_id' => (string) $account->id, 'visibility' => 'visible', 'page' => '2']);
});

it('projects safe connection metadata without exposing secrets or private configuration', function (): void {
    $classic = UpstreamApi::factory()->create(['encrypted_secret' => 'PRIVATE-LIST-CLASSIC',
        'config' => ['allow_credit_limit' => true, 'private' => 'PRIVATE-LIST-CONFIG']]);
    $sub2 = UpstreamApi::factory()->create(['driver' => DriverType::SUB2API, 'encrypted_secret' => 'PRIVATE-LIST-SUB2']);
    $data = productListData();
    expect($data['accounts']->pluck('id')->all())->toBe([$classic->id])
        ->and($data['accounts']->first()->allow_credit_limit)->toBeTrue()
        ->and($data['sub2Accounts']->pluck('id')->all())->toBe([$sub2->id])
        ->and($data['sub2Accounts']->first()->has_secret)->toBeTrue()
        ->and(json_encode([$data['accounts'], $data['sub2Accounts']], JSON_THROW_ON_ERROR))->not->toContain('PRIVATE-LIST');
});

it('offers customer preview links only for publicly visible products on active connections', function (): void {
    $active = UpstreamApi::factory()->create();
    $inactive = UpstreamApi::factory()->create(['is_active' => false]);
    $visible = productListProduct($active, ['stock_control' => true, 'qty' => 7]);
    $selected = productListProduct($active, ['name' => 'Selected outside filter']);
    productListProduct($active, ['is_hidden' => true]);
    $hiddenCategory = productListProduct($active);
    $hiddenCategory->category->update(['is_hidden' => true]);
    productListProduct($inactive);
    expect(productListData()['previewableProductIds'])->toBe([$visible->id, $selected->id]);
    $data = productListData('q=not-a-match&product='.$selected->id);
    expect($data['previewableProductIds'])->toBe([$selected->id]);
    $data = productListData('q='.$visible->id);
    expect($data['products']->first()->stock_control)->toBeTrue()->and($data['products']->first()->qty)->toBe(7);
});

it('rejects nonexistent nonclassic and malformed product selections', function (string $selection): void {
    $local = Product::factory()->create();
    $this->actingAs(productListOperator())->get('/admin/upstreams?product='.($selection === 'local' ? $local->id : $selection))->assertNotFound();
})->with(['999999', 'local', '0', '-1', 'not-an-id', '1.5', '1&product[]=2']);

it('validates only supported scalar list filters', function (string $query): void {
    $this->actingAs(productListOperator())->getJson('/admin/upstreams?'.$query)->assertUnprocessable()
        ->assertJsonPath('message', '商品筛选参数无效。');
})->with([
    'array search' => 'q[]=cloud', 'oversized search' => 'q='.str_repeat('a', 101), 'array connection' => 'account_id[]=1',
    'invalid connection' => 'account_id=0', 'invalid visibility' => 'visibility=enabled',
    'array visibility' => 'visibility[]=visible', 'invalid page' => 'page=0',
]);

it('preserves upstream page access rules for list and selected-product views', function (): void {
    $product = productListProduct(UpstreamApi::factory()->create());
    $this->get('/admin/upstreams')->assertRedirect('/admin/login');
    $this->actingAs(User::factory()->create())->get('/admin/upstreams?product='.$product->id)->assertForbidden();
});

it('renders one global menu and an accessible product table with persistent filter values', function (): void {
    $account = UpstreamApi::factory()->create(['name' => 'Selected classic connection']);
    $product = productListProduct($account, ['name' => 'Filtered cloud', 'is_hidden' => true]);
    productListProduct($account, ['name' => 'Filtered but visible']);
    $response = $this->actingAs(productListOperator())->get('/admin/upstreams?'.http_build_query([
        'q' => 'Filtered', 'account_id' => $account->id, 'visibility' => 'hidden',
    ]))->assertOk()->assertSee($product->name)->assertDontSee('Filtered but visible');
    $xpath = productListXpath($response->getContent());

    expect($xpath->query('//nav[contains(concat(" ", normalize-space(@class), " "), " fi-sidebar-nav ")]'))->toHaveCount(1)
        ->and($xpath->query('//main//nav[not(@aria-label="结果分页")]'))->toHaveCount(0)
        ->and($xpath->query('//main//form[@method="get"][@action="/admin/upstreams"][@aria-label="筛选条件"]'))->toHaveCount(1)
        ->and($xpath->query('//input[@name="q"][@value="Filtered"]'))->toHaveCount(1)
        ->and($xpath->query('//select[@name="account_id"]/option[@value="'.$account->id.'"][@selected]'))->toHaveCount(1)
        ->and($xpath->query('//select[@name="visibility"]/option[@value="hidden"][@selected]'))->toHaveCount(1)
        ->and($xpath->query('//form[@aria-label="筛选条件"]//a[@href="/admin/upstreams"][normalize-space(.)="重置"]'))->toHaveCount(1)
        ->and($xpath->query('//div[@role="region"][@aria-label="经典魔方商品列表"][@tabindex="0"]/table/caption[normalize-space(.)="经典魔方商品列表"]'))->toHaveCount(1)
        ->and($xpath->query('//table/thead/tr/th[@scope="col"]'))->toHaveCount(8)
        ->and($xpath->query('//table/tbody/tr'))->toHaveCount(1);
});

it('displays exact monthly base money without presenting a cheaper trial or annual price as the list price', function (): void {
    $product = productListProduct(UpstreamApi::factory()->create(), ['local_prices' => [
        'ontrial' => ['price' => '0.01', 'enabled' => true],
        'annually' => ['price' => '30.00', 'enabled' => true],
        'monthly' => ['price' => '1234567890.12', 'enabled' => true],
    ]]);
    $response = $this->actingAs(productListOperator())->get('/admin/upstreams')->assertOk();
    $xpath = productListXpath($response->getContent());
    $row = $xpath->query('//table/tbody/tr[.//a[normalize-space(.)="'.$product->name.'"]]')->item(0);

    expect($row)->not->toBeNull()->and($row->textContent)->toContain('1234567890.12', 'CNY', '基础价 / Monthly')
        ->not->toContain('0.01', '30.00', 'Trial', 'Annually');
});

it('does not advertise trial only products with an ordinary base price', function (): void {
    $product = productListProduct(UpstreamApi::factory()->create(), ['local_prices' => [
        'ontrial' => ['price' => '0.01', 'enabled' => true],
    ]]);
    $response = $this->actingAs(productListOperator())->get('/admin/upstreams')->assertOk();
    $xpath = productListXpath($response->getContent());
    $row = $xpath->query('//table/tbody/tr[.//a[normalize-space(.)="'.$product->name.'"]]')->item(0);

    expect($row)->not->toBeNull()->and($row->textContent)->toContain('暂无普通周期基础价')->not->toContain('0.01');
});

it('distinguishes an unimported catalog from a filter that matches no products', function (): void {
    $this->actingAs(productListOperator())->get('/admin/upstreams')->assertOk()
        ->assertSee('尚未导入经典魔方商品')->assertDontSee('没有匹配商品')->assertSeeText('共 0 条');
    productListProduct(UpstreamApi::factory()->create(), ['name' => 'Available cloud']);
    $response = $this->get('/admin/upstreams?q=Missing')->assertOk()->assertSee('没有匹配商品')
        ->assertSee('调整筛选条件或重置后重试。')->assertDontSee('尚未导入经典魔方商品');
    $xpath = productListXpath($response->getContent());

    expect($xpath->query('//table/tbody/tr/td[@colspan="8"]//a[@href="/admin/upstreams"][normalize-space(.)="重置筛选"]'))->toHaveCount(1);

    $zeroResponse = $this->get('/admin/upstreams?q=0')->assertOk()->assertSee('没有匹配商品')
        ->assertSee('调整筛选条件或重置后重试。')->assertDontSee('尚未导入经典魔方商品');
    $zeroXpath = productListXpath($zeroResponse->getContent());

    expect($zeroXpath->query('//input[@name="q"][@value="0"]'))->toHaveCount(1)
        ->and($zeroXpath->query('//table/tbody/tr/td[@colspan="8"]//a[@href="/admin/upstreams"][normalize-space(.)="重置筛选"]'))->toHaveCount(1);
});

it('renders complete selected configuration and preserves existing quantity and option boundary fields for operators', function (): void {
    $product = productListProduct(UpstreamApi::factory()->create(), ['name' => 'Configured cloud']);
    $quantity = ProductOptionGroup::create(['product_id' => $product->id, 'name' => 'Bandwidth', 'type' => 'quantity',
        'min_value' => 5, 'max_value' => 95, 'step' => 5,
        'downgrade_bounds' => ['allow_downgrade' => true, 'min_value' => 20]]);
    $selection = ProductOptionGroup::create(['product_id' => $product->id, 'name' => 'Operating system', 'type' => 'select', 'is_hidden' => true]);
    $allowed = ProductOptionItem::create(['option_group_id' => $selection->id, 'label' => 'Allowed Linux']);
    $other = ProductOptionItem::create(['option_group_id' => $selection->id, 'label' => 'Other Linux']);
    $hidden = ProductOptionItem::create(['option_group_id' => $selection->id, 'label' => 'Retired Linux', 'is_hidden' => true]);
    $selection->update(['downgrade_bounds' => ['allow_downgrade' => true, 'allowed_item_ids' => [$allowed->id, $hidden->id]]]);
    $response = $this->actingAs(productListOperator('upstream-operator'))->get('/admin/upstreams?q=Unrelated&visibility=hidden&page=2&product='.$product->id)
        ->assertOk()->assertSee($product->name)->assertSee('Bandwidth')->assertSee('Operating system')
        ->assertSee('Allowed Linux')->assertSee('Other Linux')->assertSee('Retired Linux（已下架）');
    $xpath = productListXpath($response->getContent());
    $quantityForm = '//form[@action="/admin/upstream-options/'.$quantity->id.'/bounds"]';
    $selectionForm = '//form[@action="/admin/upstream-options/'.$selection->id.'/bounds"]';

    expect($xpath->query('//form[@method="post"][@action="/admin/upstreams/'.$product->id.'/sync"]//input[@name="_token"]'))->toHaveCount(1)
        ->and($xpath->query($quantityForm.'[@method="post"]//input[@name="_token"]'))->toHaveCount(1)
        ->and($xpath->query($quantityForm.'//input[@name="allow"][@value="1"][@checked]'))->toHaveCount(1)
        ->and($xpath->query($quantityForm.'//input[@name="minimum"][@type="number"][@min="5"][@max="95"][@step="5"][@value="20"]'))->toHaveCount(1)
        ->and($xpath->query($quantityForm.'//input[@name="reason"][@maxlength="1000"]'))->toHaveCount(1)
        ->and($xpath->query($selectionForm.'//input[@name="items[]"][@value="'.$allowed->id.'"][@checked]'))->toHaveCount(1)
        ->and($xpath->query($selectionForm.'//input[@name="items[]"][@value="'.$other->id.'"][not(@checked)]'))->toHaveCount(1)
        ->and($xpath->query($selectionForm.'//input[@name="items[]"][@value="'.$hidden->id.'"]'))->toHaveCount(0)
        ->and($xpath->query('//a[@href="/admin/upstreams?q=Unrelated&visibility=hidden&page=2"][normalize-space(.)="返回商品列表"]'))->toHaveCount(1);
});

it('lets read only operators inspect complete options without showing sync import or boundary write forms', function (): void {
    $product = productListProduct(UpstreamApi::factory()->create());
    $group = ProductOptionGroup::create(['product_id' => $product->id, 'name' => 'Retained group', 'type' => 'select']);
    ProductOptionItem::create(['option_group_id' => $group->id, 'label' => 'Retained hidden option', 'is_hidden' => true]);
    $response = $this->actingAs(productListOperator())->get('/admin/upstreams?product='.$product->id)->assertOk()
        ->assertSee('Retained group')->assertSee('Retained hidden option（已下架）');
    $xpath = productListXpath($response->getContent());

    expect($xpath->query('//form[@action="/admin/upstreams/'.$product->id.'/sync"]'))->toHaveCount(0)
        ->and($xpath->query('//form[@action="/admin/upstream-options/'.$group->id.'/bounds"]'))->toHaveCount(0)
        ->and($xpath->query('//form[@action="/admin/upstreams/import"]'))->toHaveCount(0)
        ->and($xpath->query('//form[@action="/admin/upstreams/configure"]'))->toHaveCount(0);
});

it('exposes a selected customer configuration link only when the existing public catalog permits it', function (string $state): void {
    $account = UpstreamApi::factory()->create(['is_active' => $state !== 'inactive connection']);
    $product = productListProduct($account, ['is_hidden' => $state === 'hidden product']);
    if ($state === 'hidden category') {
        $product->category->update(['is_hidden' => true]);
    }
    $response = $this->actingAs(productListOperator())->get('/admin/upstreams?product='.$product->id)->assertOk();
    $xpath = productListXpath($response->getContent());

    expect($xpath->query('//a[@href="/console/products/'.$product->id.'/configure"]'))->toHaveCount($state === 'public' ? 1 : 0);
})->with(['public', 'hidden product', 'hidden category', 'inactive connection']);
