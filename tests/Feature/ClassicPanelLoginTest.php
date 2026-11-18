<?php

declare(strict_types=1);

use App\Enums\DriverType;
use App\Enums\InstanceStatus;
use App\Models\Instance;
use App\Models\Product;
use App\Models\UpstreamApi;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(fn () => $this->installApplicationForTest());

function panelLoginInstance(): Instance
{
    $account = UpstreamApi::factory()->create();
    $product = Product::factory()->create(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id]);

    return Instance::factory()->create(['product_id' => $product->id, 'driver_type' => DriverType::ZJMF_API,
        'upstream_api_id' => $account->id, 'upstream_instance_id' => '4291', 'status' => InstanceStatus::ACTIVE]);
}

function panelLoginHtml(string $action = 'https://panel.example.test/vhost/index.php?c=session&a=login'): string
{
    return '<script>UPSTREAM-UNTRUSTED-SCRIPT</script><form action="'.htmlspecialchars($action, ENT_QUOTES).'" method="post" onsubmit="UNTRUSTED-HANDLER()">'
        .'<input type="hidden" name="token" value=""><input type="hidden" name="username" value="cdn-user">'
        .'<input type="hidden" name="passwd" value="PANEL-PRIVATE-PASSWORD"><button>登录面板</button></form>';
}

function fakePanelLogin(?Closure $override = null): void
{
    $level = DB::transactionLevel();
    Http::preventStrayRequests();
    Http::fake(function ($request) use ($override, $level) {
        expect(DB::transactionLevel())->toBe($level);
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($override !== null && ($response = $override($path, $request)) !== null) {
            return $response;
        }

        return Http::response(match ($path) {
            '/zjmf_api_login' => ['status' => 200, 'jwt' => 'PRIVATE-API-JWT'],
            '/host/header' => ['status' => 200, 'data' => ['host_data' => ['domainstatus' => 'Active'],
                'module_client_area' => [['key' => 'information', 'name' => '主机详情']]]],
            '/zjmf_api/provision/custom/content' => ['status' => 200, 'data' => ['html' => panelLoginHtml()]],
            default => throw new RuntimeException('Unexpected panel request: '.$path),
        });
    });
}

it('shows a panel button without fetching login credentials until the owner clicks it', function (): void {
    Http::preventStrayRequests();
    Http::fake();
    $instance = panelLoginInstance();
    $this->actingAs($instance->user)->get('/console/instances/'.$instance->id)->assertOk()
        ->assertSee('登录面板')->assertSee('/console/instances/'.$instance->id.'/panel-login', false);
    Http::assertNothingSent();
    $this->assertDatabaseCount('upstream_operations', 0);
});

it('opens only the authenticated owner panel form without rendering or logging supplier HTML', function (): void {
    $instance = panelLoginInstance();
    $before = $instance->fresh()->getRawOriginal();
    fakePanelLogin();
    $response = $this->actingAs($instance->user)->post('/console/instances/'.$instance->id.'/panel-login')->assertOk()
        ->assertSee('action="https://panel.example.test/vhost/index.php?c=session&amp;a=login"', false)
        ->assertSee('name="passwd" value="PANEL-PRIVATE-PASSWORD"', false)
        ->assertDontSee('UPSTREAM-UNTRUSTED-SCRIPT')->assertDontSee('UNTRUSTED-HANDLER')
        ->assertDontSee('PRIVATE-API-JWT')->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Frame-Options', 'DENY');
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private')
        ->and($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'", 'form-action https://panel.example.test', "script-src 'nonce-")
        ->and($instance->fresh()->getRawOriginal())->toBe($before);
    Http::assertSent(fn ($r) => parse_url($r->url(), PHP_URL_PATH) === '/zjmf_api/provision/custom/content'
        && $r->method() === 'POST' && $r['id'] === '4291' && $r['key'] === 'information');
    $operation = DB::table('upstream_operations')->where('path', '/zjmf_api/provision/custom/content')->sole();
    expect(json_decode($operation->response_body, true)['data']['html'])->toBe('[REDACTED]')
        ->and($operation->instance_id)->toBe($instance->id)
        ->and(DB::table('upstream_operations')->pluck('response_body')->implode(' '))->not->toContain('PANEL-PRIVATE-PASSWORD', 'PRIVATE-API-JWT');
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('fulfillment_tasks', 0);
});

it('blocks anonymous and foreign users before any upstream panel call', function (): void {
    $instance = panelLoginInstance();
    Http::fake();
    $this->post('/console/instances/'.$instance->id.'/panel-login')->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->post('/console/instances/'.$instance->id.'/panel-login')->assertForbidden();
    Http::assertNothingSent();
});

it('hides and blocks panel login for a local or inactive service', function (string $case): void {
    $instance = $case === 'local' ? Instance::factory()->create(['status' => InstanceStatus::ACTIVE]) : panelLoginInstance();
    if ($case === 'suspended') {
        $instance->update(['status' => InstanceStatus::SUSPENDED]);
    } elseif ($case === 'disabled account') {
        $instance->upstreamApi->update(['is_active' => false]);
    }
    Http::fake();
    $this->actingAs($instance->user)->get('/console/instances/'.$instance->id)->assertOk()->assertDontSee('登录面板');
    $this->postJson('/console/instances/'.$instance->id.'/panel-login')->assertUnprocessable()->assertJsonValidationErrors('panel');
    Http::assertNothingSent();
})->with(['local', 'suspended', 'disabled account']);

it('rejects unverified or ambiguous supplier login forms', function (string $html): void {
    $instance = panelLoginInstance();
    fakePanelLogin(fn ($path) => $path === '/zjmf_api/provision/custom/content'
        ? Http::response(['status' => 200, 'data' => ['html' => $html]]) : null);
    $this->actingAs($instance->user)->postJson('/console/instances/'.$instance->id.'/panel-login')
        ->assertUnprocessable()->assertJsonValidationErrors('panel')->assertDontSee('PANEL-PRIVATE-PASSWORD');
})->with([
    'insecure HTTP' => fn () => panelLoginHtml('http://panel.example.test/vhost/index.php?c=session&a=login'),
    'script destination' => fn () => panelLoginHtml('javascript:alert(1)'),
    'URL credentials' => fn () => panelLoginHtml('https://user@panel.example.test/vhost/index.php?c=session&a=login'),
    'different operation' => fn () => panelLoginHtml('https://panel.example.test/vhost/index.php?c=whm&a=delete'),
    'ambiguous forms' => fn () => panelLoginHtml().panelLoginHtml(),
    'missing password' => fn () => str_replace('name="passwd"', 'name="unknown"', panelLoginHtml()),
]);

it('requires an active upstream header and an advertised information tab', function (bool $active): void {
    $instance = panelLoginInstance();
    fakePanelLogin(fn ($path) => $path === '/host/header' ? Http::response(['status' => 200, 'data' => [
        'host_data' => ['domainstatus' => $active ? 'Active' : 'Suspended'], 'module_client_area' => []]]) : null);
    $this->actingAs($instance->user)->postJson('/console/instances/'.$instance->id.'/panel-login')->assertUnprocessable();
    Http::assertNotSent(fn ($r) => parse_url($r->url(), PHP_URL_PATH) === '/zjmf_api/provision/custom/content');
})->with([true, false]);

it('discards panel credentials when the binding changes during the upstream request', function (): void {
    $instance = panelLoginInstance();
    fakePanelLogin(function ($path) use ($instance) {
        if ($path === '/zjmf_api/provision/custom/content') {
            $instance->update(['upstream_instance_id' => 'new-host']);
        }

        return null;
    });
    $this->actingAs($instance->user)->postJson('/console/instances/'.$instance->id.'/panel-login')
        ->assertUnprocessable()->assertJsonValidationErrors('panel')->assertDontSee('PANEL-PRIVATE-PASSWORD');
});
