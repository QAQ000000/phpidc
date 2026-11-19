<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Actions\ConfigureClassicUpstreamAction;
use App\Enums\DriverType;
use App\Models\UpstreamApi;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Http::preventStrayRequests();
});

function upstreamUsabilityOperator(string $role = 'upstream-operator'): User
{
    $operator = User::factory()->create();
    app(AssignAdminRoleAction::class)->execute($operator, $role, 'Isolated upstream usability authority');

    return $operator;
}

function upstreamUsabilityEdit(UpstreamApi $account, array $changes = []): array
{
    return $changes + ['account_id' => $account->id, 'name' => $account->name, 'url' => $account->base_url,
        'username' => $account->username, 'api_secret' => '', 'raw_jwt' => 1, 'credit_limit' => 1];
}

it('edits an explicit connection with a blank secret while preserving its encrypted value and unrelated configuration', function (): void {
    $operator = upstreamUsabilityOperator();
    $account = UpstreamApi::factory()->create(['name' => 'Existing supplier', 'auth_header_mode' => 'raw_jwt',
        'config' => ['allow_credit_limit' => true, 'existing_option' => 'keep'], 'is_active' => false]);
    $rawSecret = $account->getRawOriginal('encrypted_secret');
    $this->actingAs($operator)->post('/admin/upstreams/configure', upstreamUsabilityEdit($account, [
        'name' => 'Renamed supplier', 'url' => 'https://renamed-upstream.test',
    ]))->assertRedirect('/admin/upstreams')->assertSessionHasNoErrors();
    $account->refresh();
    expect($account->name)->toBe('Renamed supplier')->and($account->base_url)->toBe('https://renamed-upstream.test')
        ->and($account->getRawOriginal('encrypted_secret'))->toBe($rawSecret)
        ->and($account->auth_header_mode)->toBe('raw_jwt')->and($account->config)->toBe(['allow_credit_limit' => true, 'existing_option' => 'keep'])
        ->and($account->is_active)->toBeFalse()->and(UpstreamApi::count())->toBe(1);
    $this->assertDatabaseHas('audit_logs', ['action' => 'upstream.classic_configure', 'subject_id' => $account->id,
        'actor_id' => $operator->id, 'reason' => '编辑上游连接配置']);
    expect(DB::table('audit_logs')->where('action', 'upstream.classic_configure')->value('after'))->not->toContain('isolated-api-secret');
    Http::assertNothingSent();
});

it('rejects an implicit same-name HTTP update and keeps the original connection and audit unchanged', function (): void {
    $operator = upstreamUsabilityOperator();
    $account = UpstreamApi::factory()->create(['name' => 'Existing supplier']);
    $before = $account->refresh()->getRawOriginal();
    $this->actingAs($operator)->postJson('/admin/upstreams/configure', ['name' => $account->name,
        'url' => 'https://wrong-upstream.test', 'username' => 'other-user', 'api_secret' => 'NEW-PRIVATE-SECRET',
        'raw_jwt' => 0, 'credit_limit' => 0])->assertUnprocessable()->assertJsonValidationErrors('operation');
    expect($account->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('upstream_apis', 1);
    expect(DB::table('audit_logs')->where('action', 'upstream.classic_configure')->count())->toBe(0);
    Http::assertNothingSent();
});

it('preserves an omitted compatibility flag when editing through an existing HTTP client', function (string $field): void {
    $operator = upstreamUsabilityOperator();
    $account = UpstreamApi::factory()->create(['auth_header_mode' => 'raw_jwt', 'config' => ['allow_credit_limit' => true]]);
    $data = upstreamUsabilityEdit($account);
    unset($data[$field]);
    $this->actingAs($operator)->post('/admin/upstreams/configure', $data)->assertRedirect('/admin/upstreams')->assertSessionHasNoErrors();
    expect($account->fresh()->auth_header_mode)->toBe('raw_jwt')->and($account->fresh()->config['allow_credit_limit'])->toBeTrue();
    Http::assertNothingSent();
})->with(['raw_jwt', 'credit_limit']);

it('keeps the trusted name-based CLI configuration contract and encrypts a deliberately rotated secret', function (): void {
    $action = app(ConfigureClassicUpstreamAction::class);
    $account = $action->execute('CLI supplier', 'https://old-upstream.test', 'user', 'FIRST-PRIVATE-SECRET', 'Initial setup');
    $same = $action->execute('CLI supplier', 'https://new-upstream.test', 'user', 'SECOND-PRIVATE-SECRET', 'Explicit CLI rotation', true, true);
    expect($same->id)->toBe($account->id)->and($same->encrypted_secret)->toBe('SECOND-PRIVATE-SECRET')
        ->and($same->auth_header_mode)->toBe('raw_jwt')->and($same->config['allow_credit_limit'])->toBeTrue();
    expect(DB::table('upstream_apis')->value('encrypted_secret'))->not->toContain('SECOND-PRIVATE-SECRET');
    $this->assertDatabaseCount('upstream_apis', 1);
    Http::assertNothingSent();
});

it('does not edit a different driver or rename a connection over an existing target', function (string $conflict): void {
    $operator = upstreamUsabilityOperator();
    $account = UpstreamApi::factory()->create();
    $other = UpstreamApi::factory()->create(['driver' => $conflict === 'driver' ? DriverType::PROXMOX : DriverType::ZJMF_API]);
    $before = $account->refresh()->getRawOriginal();
    $otherBefore = $other->refresh()->getRawOriginal();
    $data = upstreamUsabilityEdit($account, $conflict === 'driver' ? ['account_id' => $other->id] : ['name' => $other->name]);
    $response = $this->actingAs($operator)->postJson('/admin/upstreams/configure', $data);
    if ($conflict === 'driver') {
        $response->assertNotFound();
    } else {
        $response->assertUnprocessable()->assertJsonValidationErrors('operation');
    }
    expect($account->fresh()->getRawOriginal())->toBe($before)->and($other->fresh()->getRawOriginal())->toBe($otherBefore);
    Http::assertNothingSent();
})->with(['driver', 'name']);

it('renders an editable connection with compatibility values but no encrypted secret or unrelated configuration', function (): void {
    $operator = upstreamUsabilityOperator();
    $account = UpstreamApi::factory()->create(['auth_header_mode' => 'raw_jwt', 'config' => ['allow_credit_limit' => true, 'private_note' => 'PRIVATE-CONFIG-VALUE']]);
    $response = $this->actingAs($operator)->get('/admin/upstreams')->assertOk()->assertSee('编辑此连接')->assertSee('留空保留原密钥')
        ->assertDontSee('isolated-api-secret')->assertDontSee('PRIVATE-CONFIG-VALUE')->assertDontSee($account->getRawOriginal('encrypted_secret'));
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    $xpath = new DOMXPath($dom);
    $form = $xpath->query('//form[input[@name="account_id" and @value="'.$account->id.'"] and @action="/admin/upstreams/configure"]')->item(0);
    expect($form)->not->toBeNull();
    expect($xpath->query('.//input[@name="name"]', $form)->item(0)->getAttribute('value'))->toBe($account->name);
    expect($xpath->query('.//input[@name="raw_jwt" and @type="checkbox" and @checked]', $form)->length)->toBe(1);
    expect($xpath->query('.//input[@name="credit_limit" and @type="checkbox" and @checked]', $form)->length)->toBe(1);
    expect($xpath->query('.//input[@name="api_secret" and @required]', $form)->length)->toBe(0);
    expect($xpath->query('.//input[@name="reason" and @required]', $form)->length)->toBe(0);
    Http::assertNothingSent();
});

it('offers product import directly from the authenticated directory with the chosen account and product already supplied', function (): void {
    $operator = upstreamUsabilityOperator();
    $account = UpstreamApi::factory()->create();
    $catalog = ['products' => [['name' => 'Cloud group', 'products' => [['id' => 77, 'name' => 'Chosen cloud']]]]];
    Http::fake(['*/zjmf_api_login' => Http::response(['status' => 200, 'jwt' => 'isolated-jwt']),
        '*/cart/all*' => Http::response(['status' => 200, 'data' => $catalog])]);
    $this->actingAs($operator)->post('/admin/upstreams/catalog', ['account_id' => $account->id])->assertOk()
        ->assertSee('Chosen cloud')->assertSee('action="/admin/upstreams/import"', false)
        ->assertSee('name="account_id" value="'.$account->id.'"', false)->assertSee('name="pid" value="77"', false)
        ->assertSee('导入并同步此商品');
    $this->assertDatabaseCount('products', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
    Http::assertSentCount(2);
});

it('denies connection changes to read-only upstream viewers before writes or HTTP', function (): void {
    $viewer = upstreamUsabilityOperator('upstream-viewer');
    $account = UpstreamApi::factory()->create();
    $before = $account->refresh()->getRawOriginal();
    $this->actingAs($viewer)->post('/admin/upstreams/configure', upstreamUsabilityEdit($account))->assertForbidden();
    expect($account->fresh()->getRawOriginal())->toBe($before);
    $this->get('/admin/upstreams')->assertOk()->assertDontSee('编辑此连接')->assertDontSee('新增加密连接');
    Http::assertNothingSent();
});
