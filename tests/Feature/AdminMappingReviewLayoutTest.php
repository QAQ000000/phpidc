<?php

declare(strict_types=1);

use App\Actions\InspectInstanceMappingAction;
use App\Enums\DriverType;
use App\Models\Instance;
use App\Models\Product;
use App\Models\UpstreamApi;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Http::preventStrayRequests();
});

function mappingLayoutActor(bool $canReview = true): User
{
    $actor = User::factory()->create();
    foreach ($canReview ? ['upstream.view', 'upstream.mapping-review'] : ['upstream.view'] as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $actor;
}

function mappingLayoutFixture(): array
{
    $account = UpstreamApi::factory()->create();
    $product = Product::factory()->create(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id, 'upstream_pid' => '17']);
    $instance = Instance::factory()->create(['product_id' => $product->id, 'driver_type' => DriverType::ZJMF_API,
        'upstream_api_id' => $account->id, 'active_config' => ['upstream_pid' => '17', 'mapping_requires_review' => true]]);

    return [$instance, $product];
}

function assertMappingNativeSidebar(string $html): void
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
    $xpath = new DOMXPath($document);
    $sidebars = $xpath->query('//nav[contains(concat(" ", normalize-space(@class), " "), " fi-sidebar-nav ")]');
    expect($sidebars)->toHaveCount(1);
    $activeLinks = $xpath->query('.//li[contains(concat(" ", normalize-space(@class), " "), " fi-active ")]/a[@href="/admin/upstream-instances"]', $sidebars->item(0));
    expect($activeLinks)->toHaveCount(1);
}

it('renders the existing mapping review URL with the native panel sidebar and unchanged form', function (): void {
    [$instance, $product] = mappingLayoutFixture();
    $path = '/admin/upstream-instances/'.$instance->id.'/mapping-review';
    $response = $this->actingAs(mappingLayoutActor())->get($path)->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')->assertSee('服务 #'.$instance->id.' 配置映射核准')
        ->assertSee('action="'.$path.'/check"', false)->assertSee('name="target_product_id"', false)
        ->assertSee('name="request_id"', false)->assertSee($product->name)->assertDontSee('我的账单');
    assertMappingNativeSidebar($response->getContent());
    Http::assertNothingSent();
});

it('keeps authenticated review permission checks before rendering or querying a supplier', function (): void {
    [$instance] = mappingLayoutFixture();
    $path = '/admin/upstream-instances/'.$instance->id.'/mapping-review';
    $this->get($path)->assertRedirect('/admin/login');
    $this->post($path.'/check')->assertRedirect('/admin/login');
    $this->actingAs(mappingLayoutActor(false))->get($path)->assertForbidden();
    $this->post($path.'/check')->assertForbidden();
    Http::assertNothingSent();
});

it('keeps controller preview data and the native sidebar after checking a mapping', function (): void {
    [$instance, $product] = mappingLayoutFixture();
    $path = '/admin/upstream-instances/'.$instance->id.'/mapping-review';
    $actor = mappingLayoutActor();
    $requestId = (string) Str::ulid();
    $preview = ['request_id' => $requestId, 'review_token' => 'SIGNED-PREVIEW-TOKEN', 'expires_at' => '2026-11-20T12:00:00Z',
        'snapshot' => ['target_product_id' => $product->id, 'host' => ['pid' => 17], 'original_recurring_amount' => '12.00',
            'base_price' => '8.00', 'option_price' => '1.00', 'new_recurring_amount' => '9.00',
            'catalog_cost' => '9.00', 'renewal_cost' => '9.00', 'resolved_pairs' => [], 'price_baseline_matches' => false]];
    $this->mock(InspectInstanceMappingAction::class)->shouldReceive('execute')->once()
        ->withArgs(fn (Instance $selected, Product $target, User $operator, string $id): bool => $selected->is($instance)
            && $target->is($product) && $operator->is($actor) && $id === $requestId)->andReturn($preview);
    $response = $this->actingAs($actor)->post($path.'/check', ['request_id' => $requestId, 'target_product_id' => $product->id])
        ->assertOk()->assertViewHas('preview', $preview)->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('action="'.$path.'"', false)->assertSee('name="review_token"', false)
        ->assertSee('SIGNED-PREVIEW-TOKEN')->assertSee('等待客户本人');
    assertMappingNativeSidebar($response->getContent());
    Http::assertNothingSent();
});
