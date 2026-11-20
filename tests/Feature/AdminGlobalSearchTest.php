<?php

declare(strict_types=1);

use App\Enums\DriverType;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\UpstreamApi;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
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

afterEach(function (): void {
    Http::assertNothingSent();
});

function globalSearchActor(string ...$permissions): User
{
    $actor = User::factory()->create();
    foreach ($permissions as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $actor;
}

function globalSearchCaptureQueries(array &$queries): void
{
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (preg_match('/\bfrom ["`]?(users|invoices|instances|upstream_apis)["`]?\b/i', $query->sql)) {
            $queries[] = $query->sql;
        }
    });
}

it('authenticates the search endpoint and refuses ordinary customers before searching', function (): void {
    $this->get('/admin/search?q=1')->assertRedirect('/admin/login');
    $this->getJson('/admin/search?q=1')->assertUnauthorized();
    $customer = User::factory()->create();
    $this->actingAs($customer);
    $queries = [];
    globalSearchCaptureQueries($queries);
    $this->getJson('/admin/search?q=1')->assertForbidden();
    expect($queries)->toBeEmpty();
});

it('refuses unrelated module roles before query validation or data reads', function (): void {
    $this->actingAs(globalSearchActor('support.view'));
    $queries = [];
    globalSearchCaptureQueries($queries);
    $this->getJson('/admin/search?q[]=1')->assertForbidden();
    expect($queries)->toBeEmpty();
});

it('returns an empty result without searching for missing or whitespace terms', function (?string $term): void {
    $this->actingAs(globalSearchActor('customers.view', 'finance.view', 'upstream.view'));
    $queries = [];
    globalSearchCaptureQueries($queries);
    $url = '/admin/search'.($term === null ? '' : '?'.http_build_query(['q' => $term]));
    $this->getJson($url)->assertOk()->assertExactJson(['query' => '', 'groups' => []])
        ->assertHeader('Cache-Control', 'no-store, private');
    expect($queries)->toBeEmpty();
})->with([null, '', '   ']);

it('rejects invalid search parameters without querying business tables', function (array $parameters): void {
    $this->actingAs(globalSearchActor('customers.view', 'finance.view', 'upstream.view'));
    $queries = [];
    globalSearchCaptureQueries($queries);
    $this->getJson('/admin/search?'.http_build_query($parameters))->assertUnprocessable()->assertJsonValidationErrors('q');
    expect($queries)->toBeEmpty();
})->with([
    [['q' => ['1']]],
    [['q' => str_repeat('中', 256)]],
]);

it('searches customers by exact identity without disclosing financial fields or other domains', function (): void {
    $this->actingAs(globalSearchActor('customers.view'));
    $customer = User::factory()->create(['name' => 'C1 示例客户', 'email' => 'c1-exact@example.test', 'credit' => '1234.56']);
    $queries = [];
    globalSearchCaptureQueries($queries);
    $response = $this->getJson('/admin/search?q=c1-exact%40example.test')->assertOk();
    expect($response->json('groups'))->toHaveCount(1)
        ->and($response->json('groups.0.key'))->toBe('customers')
        ->and($response->json('groups.0.items.0'))->toEqual([
            'id' => $customer->id, 'label' => 'C1 示例客户 · c1-exact@example.test', 'description' => '客户 #'.$customer->id,
            'url' => '/admin/customers/'.$customer->id,
        ]);
    $response->assertDontSee('1234.56')->assertDontSee('password')->assertDontSee('credit');
    foreach ($queries as $query) {
        expect($query)->not->toContain('invoices')->not->toContain('instances')->not->toContain('*')->not->toContain('credit')->not->toContain('password');
    }
    $this->getJson('/admin/search?q=000'.$customer->id)->assertOk()->assertJsonPath('groups.0.items.0.id', $customer->id);
    $this->getJson('/admin/search?q=c1-exact')->assertOk()->assertJsonPath('groups.0.items', []);
    $this->getJson('/admin/search?q=C1')->assertOk()->assertJsonPath('groups.0.items', []);
});

it('includes retained anonymized customers by their current identity only', function (): void {
    $this->actingAs(globalSearchActor('customers.view'));
    $customer = User::factory()->create(['name' => '已注销客户', 'email' => 'closed-c1@example.invalid']);
    $customer->forceFill(['deleted_at' => now(), 'anonymized_at' => now()])->save();
    $this->getJson('/admin/search?q='.$customer->id)->assertOk()->assertJsonPath('groups.0.items.0.description', '客户 #'.$customer->id.' · 已注销');
    $this->getJson('/admin/search?q=closed-c1%40example.invalid')->assertOk()->assertJsonPath('groups.0.items.0.id', $customer->id);
    $this->getJson('/admin/search?q=original-address%40example.test')->assertOk()->assertJsonPath('groups.0.items', []);
});

it('projects only invoice metadata for finance-only search without querying customers', function (): void {
    $this->actingAs(globalSearchActor('finance.view'));
    $invoice = Invoice::factory()->paid()->create(['invoice_no' => 'INV-C1-EXACT', 'line_items' => ['private' => 'C1-PAYMENT-SECRET']]);
    $queries = [];
    globalSearchCaptureQueries($queries);
    $response = $this->getJson('/admin/search?q=INV-C1-EXACT&return_to=javascript%3Aalert(1)')->assertOk()
        ->assertJsonPath('groups.0.key', 'invoices')->assertJsonPath('groups.0.items.0.id', $invoice->id)
        ->assertJsonPath('groups.0.items.0.description', '账单 #'.$invoice->id.' · 已付款')
        ->assertJsonPath('groups.0.items.0.url', '/admin/finance/invoice?invoice_id='.$invoice->id)
        ->assertDontSee('C1-PAYMENT-SECRET')->assertDontSee('javascript:');
    expect(array_keys($response->json('groups.0.items.0')))->toBe(['id', 'label', 'description', 'url']);
    foreach ($queries as $query) {
        expect($query)->not->toContain('users')->not->toContain('instances')->not->toContain('*')->not->toContain('line_items')->not->toContain('user_id');
    }
    $this->getJson('/admin/search?q='.$invoice->id)->assertOk()->assertJsonPath('groups.0.items.0.id', $invoice->id);
    $this->getJson('/admin/search?q=INV-C1')->assertOk()->assertJsonPath('groups.0.items', []);
});

it('lists duplicated upstream IDs with their connection and local IDs and a bounded result count', function (): void {
    $this->actingAs(globalSearchActor('upstream.view'));
    $instances = [];
    for ($index = 0; $index < 6; $index++) {
        $connection = UpstreamApi::factory()->create(['name' => 'C1 连接 '.$index]);
        $instances[] = Instance::factory()->active()->create(['driver_type' => DriverType::ZJMF_API,
            'upstream_api_id' => $connection->id, 'upstream_instance_id' => 'C1-HOST-SHARED',
            'display_name' => 'C1 服务 '.$index, 'encrypted_credentials' => ['password' => 'C1-HOST-SECRET']]);
    }
    $queries = [];
    globalSearchCaptureQueries($queries);
    $response = $this->getJson('/admin/search?q=C1-HOST-SHARED')->assertOk()->assertJsonCount(5, 'groups.0.items')
        ->assertJsonPath('groups.0.key', 'instances')->assertDontSee('C1-HOST-SECRET');
    foreach ($response->json('groups.0.items') as $item) {
        $instance = collect($instances)->firstWhere('id', $item['id']);
        expect($item['description'])->toContain('本地 #'.$instance->id)->toContain('连接 #'.$instance->upstream_api_id)->toContain('上游 #C1-HOST-SHARED')
            ->and($item['url'])->toBe('/admin/upstream-instances?instance='.$instance->id);
    }
    foreach ($queries as $query) {
        expect($query)->not->toContain('users')->not->toContain('invoices')->not->toContain('*')->not->toContain('encrypted_credentials')->not->toContain('encrypted_secret')->not->toContain('active_config')->not->toContain('downstream_token');
    }
});

it('omits nonclassic services unless the actor can reach their customer detail', function (): void {
    $actor = globalSearchActor('upstream.view');
    $instance = Instance::factory()->active()->create(['driver_type' => DriverType::REDEMPTION, 'upstream_instance_id' => 'C1-CARD-ID']);
    $this->actingAs($actor)->getJson('/admin/search?q=C1-CARD-ID')->assertOk()->assertJsonPath('groups.0.items', []);
    $actor->givePermissionTo(Permission::findOrCreate('customers.view', 'web'));
    $this->getJson('/admin/search?q=C1-CARD-ID')->assertOk()->assertJsonPath('groups.1.items.0.id', $instance->id)
        ->assertJsonPath('groups.1.items.0.url', '/admin/customers/'.$instance->user_id.'#customer-services');
});

it('groups matching numeric identifiers instead of choosing one domain implicitly', function (): void {
    $this->actingAs(globalSearchActor('customers.view', 'finance.view', 'upstream.view'));
    $customer = User::factory()->create(['id' => 700]);
    Invoice::factory()->create(['id' => 700, 'user_id' => $customer->id]);
    Instance::factory()->active()->create(['id' => 700, 'user_id' => $customer->id, 'driver_type' => DriverType::ZJMF_API]);
    $response = $this->getJson('/admin/search?q=700')->assertOk()->assertJsonCount(3, 'groups');
    foreach ($response->json('groups') as $group) {
        expect($group['items'][0]['id'])->toBe(700);
    }
    expect(array_column($response->json('groups'), 'key'))->toBe(['customers', 'invoices', 'instances']);
});

it('treats oversized numeric upstream identifiers as exact strings without local integer overflow', function (): void {
    $this->actingAs(globalSearchActor('customers.view', 'finance.view', 'upstream.view'));
    $term = '9223372036854775808';
    $instance = Instance::factory()->active()->create(['driver_type' => DriverType::ZJMF_API, 'upstream_instance_id' => $term]);
    $this->getJson('/admin/search?q='.$term)->assertOk()->assertJsonPath('groups.0.items', [])->assertJsonPath('groups.1.items', [])
        ->assertJsonPath('groups.2.items.0.id', $instance->id);
    $this->getJson('/admin/search?q=1e3')->assertOk()->assertJsonPath('groups.0.items', [])->assertJsonPath('groups.1.items', [])->assertJsonPath('groups.2.items', []);
});

it('performs no financial audit task or notification writes during searches', function (): void {
    $this->actingAs(globalSearchActor('finance.view', 'upstream.view'));
    $invoice = Invoice::factory()->create();
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });
    $this->getJson('/admin/search?q='.$invoice->id)->assertOk();
    expect($writes)->toBeEmpty();
});

it('renders an accessible search dialog only for authorized module viewers', function (): void {
    $this->actingAs(globalSearchActor('support.view'));
    expect(view('filament.admin-global-search')->render())->not->toContain('data-admin-search');
    $this->actingAs(globalSearchActor('finance.view'));
    $html = view('filament.admin-global-search')->render();
    expect($html)->toContain('data-admin-search')->toContain('<dialog')->toContain('aria-labelledby="admin-search-title"')
        ->toContain('maxlength="255"')->toContain('data-search-open')->toContain('role="status"')
        ->toContain('/js/admin-search.js')->toContain('/css/admin-search.css');
});
