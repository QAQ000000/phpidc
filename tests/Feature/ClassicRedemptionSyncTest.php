<?php

declare(strict_types=1);

use App\Actions\ApplyInstanceSnapshotAction;
use App\Actions\CallUpstreamAction;
use App\Data\InstanceInfoResult;
use App\Enums\BillingCycle;
use App\Enums\DriverType;
use App\Enums\InstanceStatus;
use App\Models\Instance;
use App\Models\Product;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Services\DriverManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(fn () => $this->installApplicationForTest());

function classicRedemptionInstance(): Instance
{
    $account = UpstreamApi::factory()->create();
    $product = Product::factory()->create(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id, 'upstream_pid' => 731]);

    return Instance::factory()->create(['product_id' => $product->id, 'driver_type' => DriverType::ZJMF_API,
        'upstream_api_id' => $account->id, 'upstream_instance_id' => '4066', 'billing_cycle' => BillingCycle::ONETIME,
        'status' => InstanceStatus::PENDING, 'encrypted_credentials' => null, 'next_due_at' => null])->fresh();
}

function classicRedemptionHtml(): string
{
    return '<div class="sub2api-delivery"><h5>兑换码已开通</h5><input id="sub2api-redeem-code" type="text" readonly value="PRIVATE-ALREADY-ISSUED-CARD">'
        .'<a href="https://redeem.example.test/redeem">前往兑换</a><script>UNTRUSTED-SUPPLIER-SCRIPT</script></div>';
}

function fakeClassicRedemption(string $html, string $status = 'Active', string $area = 'redeem', int $contentStatus = 200): void
{
    $level = DB::transactionLevel();
    Http::preventStrayRequests();
    Http::fake(function ($request) use ($html, $status, $area, $level, $contentStatus) {
        expect(DB::transactionLevel())->toBe($level);

        return Http::response(match (parse_url($request->url(), PHP_URL_PATH)) {
            '/zjmf_api_login' => ['status' => 200, 'jwt' => 'PRIVATE-API-JWT'],
            '/host/header' => ['status' => 200, 'data' => ['host_data' => ['id' => 4066, 'productid' => 731,
                'domainstatus' => $status, 'billingcycle' => 'onetime', 'nextduedate' => 0],
                'module_client_area' => [['key' => $area, 'name' => '兑换码']]]],
            '/zjmf_api/provision/custom/content' => ['status' => $contentStatus, 'data' => ['html' => $html]],
            default => throw new RuntimeException('Unexpected request during existing card sync.'),
        });
    });
}

function readClassicRedemptionSnapshot(Instance $instance): InstanceInfoResult
{
    return app(CallUpstreamAction::class)->execute(fn () => app(DriverManager::class)
        ->fulfillment($instance->driver_type, $instance->upstream_api_id)->syncInfo($instance));
}

it('syncs an existing classic redemption code without purchasing and exposes it only to its owner', function (): void {
    $instance = classicRedemptionInstance();
    $before = $instance->getRawOriginal();
    $credit = $instance->user->credit;
    fakeClassicRedemption(classicRedemptionHtml());
    $snapshot = readClassicRedemptionSnapshot($instance);
    expect($instance->fresh()->getRawOriginal())->toBe($before)
        ->and($snapshot->panelWidgets)->toBe([['key' => 'redeem', 'title' => '兑换码', 'type' => 'redemption_code',
            'data' => ['card_key' => 'PRIVATE-ALREADY-ISSUED-CARD', 'redeem_url' => 'https://redeem.example.test/redeem']]]);
    expect(app(ApplyInstanceSnapshotAction::class)->execute($instance, $snapshot, 'Read existing service; no purchase.'))->toBeTrue();
    $current = $instance->fresh();
    expect($current->status)->toBe(InstanceStatus::ACTIVE)
        ->and($current->encrypted_credentials)->toBe(['card_key' => 'PRIVATE-ALREADY-ISSUED-CARD', 'redeem_url' => 'https://redeem.example.test/redeem'])
        ->and($current->getRawOriginal('encrypted_credentials'))->not->toContain('PRIVATE-ALREADY-ISSUED-CARD')
        ->and($current->user->credit)->toBe($credit);
    $this->actingAs($instance->user)->get('/console/instances/'.$instance->id)->assertOk()
        ->assertSee('PRIVATE-ALREADY-ISSUED-CARD')->assertSee('https://redeem.example.test/redeem')
        ->assertDontSee('UNTRUSTED-SUPPLIER-SCRIPT')->assertDontSee('登录面板')->assertHeader('Cache-Control', 'no-store, private');
    $this->actingAs(User::factory()->create())->get('/console/instances/'.$instance->id)->assertForbidden()->assertDontSee('PRIVATE-ALREADY-ISSUED-CARD');
    Http::assertSent(fn ($r) => parse_url($r->url(), PHP_URL_PATH) === '/zjmf_api/provision/custom/content'
        && $r->method() === 'POST' && $r['id'] === '4066' && $r['key'] === 'redeem');
    $logs = DB::table('upstream_operations')->pluck('response_body')->implode(' ');
    expect($logs)->not->toContain('PRIVATE-ALREADY-ISSUED-CARD', 'PRIVATE-API-JWT', 'UNTRUSTED-SUPPLIER-SCRIPT');
    $operation = DB::table('upstream_operations')->where('path', '/zjmf_api/provision/custom/content')->sole();
    expect(json_decode($operation->response_body, true)['data']['html'])->toBe('[REDACTED]');
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('fulfillment_tasks', 0);
    $this->assertDatabaseHas('audit_logs', ['subject_id' => $instance->id, 'action' => 'instance.upstream_sync', 'reason' => 'Read existing service; no purchase.']);
});

it('does not read a code from a suspended host or an unadvertised redemption tab', function (string $status, string $area): void {
    $instance = classicRedemptionInstance();
    fakeClassicRedemption(classicRedemptionHtml(), $status, $area);
    expect(readClassicRedemptionSnapshot($instance)->panelWidgets)->toBe([]);
    Http::assertNotSent(fn ($r) => parse_url($r->url(), PHP_URL_PATH) === '/zjmf_api/provision/custom/content');
})->with([['Suspended', 'redeem'], ['Active', 'information']]);

it('does not invent a code from incomplete or ambiguous upstream delivery content', function (string $html): void {
    $instance = classicRedemptionInstance();
    fakeClassicRedemption($html);
    $snapshot = readClassicRedemptionSnapshot($instance);
    expect($snapshot->panelWidgets)->toBe([]);
    app(ApplyInstanceSnapshotAction::class)->execute($instance, $snapshot);
    expect($instance->fresh()->encrypted_credentials)->toBeNull();
})->with([
    'not delivered' => '<p>兑换码尚未交付</p>',
    'duplicate codes' => fn () => classicRedemptionHtml().classicRedemptionHtml(),
    'blank code' => fn () => str_replace('PRIVATE-ALREADY-ISSUED-CARD', '', classicRedemptionHtml()),
    'editable input' => fn () => str_replace(' readonly', '', classicRedemptionHtml()),
]);

it('does not persist a code from an obsolete upstream binding', function (): void {
    $instance = classicRedemptionInstance();
    fakeClassicRedemption(classicRedemptionHtml());
    $snapshot = readClassicRedemptionSnapshot($instance);
    Instance::whereKey($instance->id)->update(['upstream_instance_id' => 'another-host']);
    expect(app(ApplyInstanceSnapshotAction::class)->execute($instance, $snapshot))->toBeFalse()
        ->and($instance->fresh()->encrypted_credentials)->toBeNull();
});

it('keeps the code but omits an insecure upstream redemption link', function (): void {
    $instance = classicRedemptionInstance();
    fakeClassicRedemption(str_replace('https://redeem.example.test', 'http://redeem.example.test', classicRedemptionHtml()));
    $snapshot = readClassicRedemptionSnapshot($instance);
    app(ApplyInstanceSnapshotAction::class)->execute($instance, $snapshot);
    expect($instance->fresh()->encrypted_credentials)->toBe(['card_key' => 'PRIVATE-ALREADY-ISSUED-CARD']);
});

it('rejects a failed content response without treating its code as delivered', function (): void {
    $instance = classicRedemptionInstance();
    fakeClassicRedemption(classicRedemptionHtml(), contentStatus: 400);
    expect(fn () => readClassicRedemptionSnapshot($instance))->toThrow(ValidationException::class)
        ->and($instance->fresh()->status)->toBe(InstanceStatus::PENDING)
        ->and($instance->fresh()->encrypted_credentials)->toBeNull();
    $this->assertDatabaseHas('upstream_operations', ['path' => '/zjmf_api/provision/custom/content', 'outcome' => 'failed']);
});
