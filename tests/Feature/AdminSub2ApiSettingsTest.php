<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Actions\ConfigureSub2ApiAction;
use App\Actions\CreateComboInvoiceAction;
use App\Actions\CreateSub2ApiProductAction;
use App\Actions\ExecuteFulfillmentTaskAction;
use App\Actions\RecoverSub2ApiCardAction;
use App\Enums\BillingCycle;
use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Models\FulfillmentTask;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Services\DriverManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Http::preventStrayRequests();
    Queue::fake();
});

function adminSub2Operator(string $role = 'upstream-operator'): User
{
    $actor = User::factory()->create();
    app(AssignAdminRoleAction::class)->execute($actor, $role, 'Isolated Sub2API configuration authority');

    return $actor;
}

function adminSub2Account(): UpstreamApi
{
    return app(ConfigureSub2ApiAction::class)->execute('Original Sub2API', 'https://sub2.test/api/v1', 'SUB2-ORIGINAL-PRIVATE-KEY', 'Isolated connection setup');
}

function adminSub2Form(UpstreamApi $account, array $changes = []): array
{
    return $changes + ['account_id' => $account->id, 'name' => $account->name, 'url' => $account->base_url, 'api_secret' => '', 'active' => 1];
}

it('protects the Sub2API configuration endpoint with authentication and upstream authority', function (): void {
    $this->post('/admin/upstreams/sub2api/configure', [])->assertRedirect('/admin/login');
    $this->actingAs(User::factory()->create())->postJson('/admin/upstreams/sub2api/configure', [])->assertForbidden();
    $viewer = adminSub2Operator('upstream-viewer');
    $this->actingAs($viewer)->postJson('/admin/upstreams/sub2api/configure', [])->assertForbidden();
    $this->assertDatabaseCount('upstream_apis', 0);
    Http::assertNothingSent();
});

it('creates an encrypted fixed-protocol Sub2API connection and records its actual web operator', function (): void {
    $actor = adminSub2Operator();
    $this->actingAs($actor)->post('/admin/upstreams/sub2api/configure', ['name' => 'New remote issuer',
        'url' => 'https://new-sub2.test/api/v1/', 'api_secret' => 'SUB2-NEW-PRIVATE-KEY', 'active' => 0,
        'driver' => DriverType::ZJMF_API->value, 'config' => ['mode' => 'local_pool', 'protocol' => 'unsupported']])
        ->assertRedirect('/admin/upstreams')->assertSessionHasNoErrors()->assertDontSee('SUB2-NEW-PRIVATE-KEY');
    $account = UpstreamApi::sole();
    expect($account->driver)->toBe(DriverType::SUB2API)->and($account->base_url)->toBe('https://new-sub2.test')
        ->and($account->is_active)->toBeFalse()->and($account->config)->toBe(['mode' => 'remote_api', 'protocol' => 'balance-codes-v1'])
        ->and($account->encrypted_secret)->toBe('SUB2-NEW-PRIVATE-KEY')
        ->and($account->getRawOriginal('encrypted_secret'))->not->toContain('SUB2-NEW-PRIVATE-KEY');
    $audit = DB::table('audit_logs')->where('action', 'sub2api.connection_create')->sole();
    expect($audit->actor_type)->toBe('user')->and($audit->actor_id)->toBe($actor->id)->and($audit->subject_id)->toBe($account->id)
        ->and($audit->reason)->toBe('新增 Sub2API 连接')->and($audit->ip)->toBe('127.0.0.1')
        ->and($audit->request_id)->toBeString()->not->toBe('')->and($audit->after)->not->toContain('SUB2-NEW-PRIVATE-KEY');
    $this->assertDatabaseCount('financial_transactions', 0);
    Http::assertNothingSent();
});

it('edits an explicit Sub2API connection with an empty secret without rewriting its ciphertext or unrelated configuration', function (): void {
    $actor = adminSub2Operator();
    $account = adminSub2Account();
    $account->update(['config' => $account->config + ['existing_option' => 'preserved']]);
    $ciphertext = $account->getRawOriginal('encrypted_secret');
    $this->actingAs($actor)->post('/admin/upstreams/sub2api/configure', adminSub2Form($account, ['name' => 'Renamed remote issuer', 'active' => 0]))
        ->assertRedirect('/admin/upstreams')->assertSessionHasNoErrors();
    $account->refresh();
    expect($account->name)->toBe('Renamed remote issuer')->and($account->is_active)->toBeFalse()
        ->and($account->base_url)->toBe('https://sub2.test')->and($account->getRawOriginal('encrypted_secret'))->toBe($ciphertext)
        ->and($account->config)->toBe(['mode' => 'remote_api', 'protocol' => 'balance-codes-v1', 'existing_option' => 'preserved'])
        ->and(UpstreamApi::count())->toBe(1);
    $audit = DB::table('audit_logs')->where('action', 'sub2api.connection_update')->sole();
    expect($audit->actor_id)->toBe($actor->id)->and($audit->reason)->toBe('编辑 Sub2API 连接配置')
        ->and(json_decode($audit->after, true, flags: JSON_THROW_ON_ERROR)['secret_changed'])->toBeFalse();
    Http::assertNothingSent();
});

it('rotates a Sub2API key only on explicit input and preserves an omitted activation flag', function (): void {
    $actor = adminSub2Operator();
    $account = adminSub2Account();
    $account->update(['is_active' => false]);
    $data = adminSub2Form($account, ['api_secret' => ' SUB2-ROTATED-PRIVATE-KEY ', 'reason' => '0']);
    unset($data['active']);
    $this->actingAs($actor)->post('/admin/upstreams/sub2api/configure', $data)->assertRedirect('/admin/upstreams')->assertSessionHasNoErrors();
    expect($account->fresh()->encrypted_secret)->toBe(' SUB2-ROTATED-PRIVATE-KEY ')->and($account->fresh()->is_active)->toBeFalse()
        ->and($account->fresh()->getRawOriginal('encrypted_secret'))->not->toContain('SUB2-ROTATED-PRIVATE-KEY');
    $audit = DB::table('audit_logs')->where('action', 'sub2api.connection_update')->sole();
    expect($audit->reason)->toBe('0')->and($audit->after)->not->toContain('SUB2-ROTATED-PRIVATE-KEY')
        ->and(json_decode($audit->after, true, flags: JSON_THROW_ON_ERROR)['secret_changed'])->toBeTrue();
    Http::assertNothingSent();
});

it('rejects changing an existing Sub2API endpoint before any connection or audit mutation', function (): void {
    $actor = adminSub2Operator();
    $account = adminSub2Account();
    $before = $account->refresh()->getRawOriginal();
    $this->actingAs($actor)->postJson('/admin/upstreams/sub2api/configure', adminSub2Form($account, [
        'url' => 'https://different-sub2.test', 'name' => 'Changed endpoint', 'api_secret' => 'SUB2-WRONG-TENANT-KEY', 'active' => 0,
    ]))->assertUnprocessable()->assertJsonValidationErrors('operation')->assertDontSee('SUB2-WRONG-TENANT-KEY');
    expect($account->fresh()->getRawOriginal())->toBe($before)
        ->and(DB::table('audit_logs')->where('action', 'sub2api.connection_update')->count())->toBe(0);
    Http::assertNothingSent();
});

it('does not overwrite a different driver or implicitly update a same-name Sub2API account', function (string $conflict): void {
    $actor = adminSub2Operator();
    $account = $conflict === 'driver' ? UpstreamApi::factory()->create() : adminSub2Account();
    $before = $account->refresh()->getRawOriginal();
    $data = adminSub2Form($account, ['api_secret' => 'SUB2-UNEXPECTED-KEY']);
    if ($conflict === 'duplicate') {
        unset($data['account_id']);
    }
    $response = $this->actingAs($actor)->postJson('/admin/upstreams/sub2api/configure', $data);
    $conflict === 'driver' ? $response->assertNotFound() : $response->assertUnprocessable()->assertJsonValidationErrors('operation');
    expect($account->fresh()->getRawOriginal())->toBe($before)->and(UpstreamApi::count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'sub2api.connection_update')->count())->toBe(0);
    Http::assertNothingSent();
})->with(['driver', 'duplicate']);

it('renders Sub2API key status and readonly endpoints without leaking plaintext ciphertext or private configuration', function (): void {
    $actor = adminSub2Operator();
    $account = adminSub2Account();
    $account->update(['config' => $account->config + ['private_note' => 'SUB2-PRIVATE-CONFIG']]);
    $response = $this->actingAs($actor)->get('/admin/upstreams')->assertOk()->assertSee('经典 ZJMF 商品与连接')
        ->assertSee('Sub2API 远程余额兑换码连接')->assertSee('管理员密钥已设置')->assertSee('留空保留原密钥')
        ->assertDontSee('SUB2-ORIGINAL-PRIVATE-KEY')->assertDontSee('SUB2-PRIVATE-CONFIG')->assertDontSee($account->getRawOriginal('encrypted_secret'));
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    $xpath = new DOMXPath($dom);
    $form = $xpath->query('//form[@action="/admin/upstreams/sub2api/configure" and input[@name="account_id" and @value="'.$account->id.'"]]')->item(0);
    expect($form)->not->toBeNull();
    expect($xpath->query('.//input[@name="url" and @readonly]', $form)->length)->toBe(1)
        ->and($xpath->query('.//input[@name="api_secret"]', $form)->item(0)->getAttribute('value'))->toBe('')
        ->and($xpath->query('.//input[@name="api_secret" and @required]', $form)->length)->toBe(0)
        ->and($xpath->query('.//input[@name="reason" and @required]', $form)->length)->toBe(0);
    $viewer = adminSub2Operator('upstream-viewer');
    $this->actingAs($viewer)->get('/admin/upstreams')->assertOk()->assertSee($account->name)
        ->assertDontSee('编辑 Sub2API 连接')->assertDontSee('新增 Sub2API 连接')->assertDontSee('api_secret')
        ->assertDontSee('SUB2-ORIGINAL-PRIVATE-KEY')->assertDontSee($account->getRawOriginal('encrypted_secret'));
    Http::assertNothingSent();
});

it('does not flash a newly entered Sub2API secret after form validation fails', function (): void {
    $actor = adminSub2Operator();
    $this->actingAs($actor)->from('/admin/upstreams')->post('/admin/upstreams/sub2api/configure', [
        'name' => '', 'url' => 'https://sub2.test', 'api_secret' => 'SUB2-DO-NOT-FLASH-KEY',
    ])->assertRedirect('/admin/upstreams')->assertSessionHasErrors('name');
    expect(session('_old_input.api_secret'))->toBeNull();
    $this->get('/admin/upstreams')->assertOk()->assertDontSee('SUB2-DO-NOT-FLASH-KEY');
    $this->assertDatabaseCount('upstream_apis', 0);
    Http::assertNothingSent();
});

it('rechecks a Sub2API operators current authority and active identity within the action transaction', function (string $change): void {
    $actor = adminSub2Operator();
    $account = adminSub2Account();
    expect($actor->can('upstream.configure'))->toBeTrue();
    match ($change) {
        'revoked' => app(AssignAdminRoleAction::class)->execute(User::findOrFail($actor->id), 'upstream-operator', 'Revoke stale operator', true),
        'deleted' => User::findOrFail($actor->id)->delete(),
        'anonymized' => DB::table('users')->where('id', $actor->id)->update(['anonymized_at' => now()]),
    };
    $before = $account->refresh()->getRawOriginal();
    expect(fn () => app(ConfigureSub2ApiAction::class)->execute($account->name, $account->base_url, 'SUB2-UNAUTHORIZED-KEY', 'Stale actor attempt', $actor, $account->id, false))
        ->toThrow($change === 'revoked' ? AuthorizationException::class : HttpException::class);
    expect($account->fresh()->getRawOriginal())->toBe($before)
        ->and(DB::table('audit_logs')->where('action', 'sub2api.connection_update')->count())->toBe(0);
    Http::assertNothingSent();
})->with(['revoked', 'deleted', 'anonymized']);

it('rolls back a Sub2API key rotation and activation change if its audit insert fails', function (): void {
    $actor = adminSub2Operator();
    $account = adminSub2Account();
    $before = $account->refresh()->getRawOriginal();
    DB::unprepared("CREATE TRIGGER reject_sub2_audit BEFORE INSERT ON audit_logs WHEN NEW.action = 'sub2api.connection_update' BEGIN SELECT RAISE(ABORT, 'Sub2API audit unavailable'); END");
    expect(fn () => app(ConfigureSub2ApiAction::class)->execute('Rollback issuer', $account->base_url, 'SUB2-ROLLED-BACK-KEY', 'Check audit atomicity', $actor, $account->id, false))
        ->toThrow(QueryException::class, 'Sub2API audit unavailable');
    expect($account->fresh()->getRawOriginal())->toBe($before)
        ->and(DB::table('audit_logs')->where('action', 'sub2api.connection_update')->count())->toBe(0);
    Http::assertNothingSent();
});

it('lets a previously sent Sub2API request finish after connection deactivation and retains customer delivery access', function (): void {
    $actor = adminSub2Operator();
    $account = adminSub2Account();
    $product = app(CreateSub2ApiProductAction::class)->execute($account, 'Remote face value', '10.00', '100', 'CNY', 'Isolated product setup');
    $buyer = User::factory()->create(['credit' => '20.00']);
    app(CreateComboInvoiceAction::class)->execute($buyer, $product, BillingCycle::ONETIME, [], true, input: ['request_id' => (string) Str::ulid()]);
    $task = FulfillmentTask::sole();
    Http::fake(function ($request) use ($actor, $account) {
        expect($request->url())->toBe('https://sub2.test/api/v1/admin/redeem-codes/generate');
        app(ConfigureSub2ApiAction::class)->execute($account->name, $account->base_url, '', 'Pause after request started', $actor, $account->id, false);

        return Http::response(['code' => 0, 'data' => [['id' => 71, 'code' => 'SUB2-DELIVERED-PRIVATE-CARD', 'type' => 'balance', 'value' => 100, 'status' => 'unused']]]);
    });
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($account->fresh()->is_active)->toBeFalse()->and($task->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS)
        ->and($task->instance->fresh()->status)->toBe(InstanceStatus::ACTIVE);
    expect(fn () => app(DriverManager::class)->sub2ApiAccount($account->id))->toThrow(DomainException::class);
    $this->actingAs($buyer)->get('/console/instances/'.$task->instance_id)->assertOk()->assertSee('SUB2-DELIVERED-PRIVATE-CARD');
    Http::assertSentCount(1);
});

it('preserves an uncertain Sub2API intent across deactivation and resumes only the original read-only recovery after enabling', function (): void {
    $actor = adminSub2Operator();
    $account = adminSub2Account();
    $product = app(CreateSub2ApiProductAction::class)->execute($account, 'Remote face value', '10.00', '100', 'CNY', 'Isolated product setup');
    $buyer = User::factory()->create(['credit' => '20.00']);
    app(CreateComboInvoiceAction::class)->execute($buyer, $product, BillingCycle::ONETIME, [], true, input: ['request_id' => (string) Str::ulid()]);
    $task = FulfillmentTask::sole();
    Http::fake(['https://sub2.test/api/v1/admin/redeem-codes/generate' => Http::response('uncertain', 502)]);
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    $intent = $task->fresh()->payload['remote_delivery'];
    $this->actingAs($actor)->post('/admin/upstreams/sub2api/configure', adminSub2Form($account, ['active' => 0]))
        ->assertRedirect('/admin/upstreams')->assertSessionHasNoErrors();
    $recover = app(RecoverSub2ApiCardAction::class);
    expect(fn () => $recover->execute($task, 71, 'Confirmed original code ownership', true))->toThrow(DomainException::class);
    expect($task->fresh()->payload['remote_delivery'])->toBe($intent);
    Http::assertSentCount(1);
    $this->post('/admin/upstreams/sub2api/configure', adminSub2Form($account))->assertRedirect('/admin/upstreams')->assertSessionHasNoErrors();
    expect($task->fresh()->payload['remote_delivery'])->toBe($intent);
    Http::fake(function ($request) {
        expect($request->method())->toBe('GET')->and($request->url())->toBe('https://sub2.test/api/v1/admin/redeem-codes/71');

        return Http::response(['code' => 0, 'data' => ['id' => 71, 'code' => 'SUB2-RECOVERED-PRIVATE-CARD', 'type' => 'balance', 'value' => 100, 'status' => 'unused']]);
    });
    $recover->execute($task, 71, 'Confirmed original code ownership', true);
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS)
        ->and($task->fresh()->payload['remote_delivery']['idempotency_key'])->toBe($intent['idempotency_key']);
    Http::assertSentCount(1);
});
