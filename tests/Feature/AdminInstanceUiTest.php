<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Filament\Pages\UpstreamInstances;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\UpstreamApi;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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

function instanceUiActor(array $roles = ['upstream-viewer']): User
{
    $actor = User::factory()->create();
    foreach ($roles as $role) {
        app(AssignAdminRoleAction::class)->execute($actor, $role, 'Isolated instance UI authority');
    }

    return $actor;
}

function instanceUiService(UpstreamApi $account, array $attributes = []): Instance
{
    return Instance::factory()->create($attributes + ['driver_type' => DriverType::ZJMF_API,
        'upstream_api_id' => $account->id, 'upstream_instance_id' => (string) fake()->unique()->numberBetween(1000, 999999)]);
}

function instanceUiData(string $query = ''): array
{
    $request = Request::create('/admin/upstream-instances'.($query === '' ? '' : '?'.$query));
    app()->instance('request', $request);
    app()->instance('originalRequest', $request);

    return (new ReflectionMethod(UpstreamInstances::class, 'getViewData'))->invoke(app(UpstreamInstances::class));
}

function instanceUiXPath(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

    return new DOMXPath($document);
}

it('filters classic instances by real name host id connection and lifecycle facts', function (): void {
    $one = UpstreamApi::factory()->create();
    $two = UpstreamApi::factory()->create(['is_active' => false]);
    $match = instanceUiService($one, ['display_name' => 'Needle service', 'status' => InstanceStatus::ACTIVE]);
    $suspended = instanceUiService($one, ['display_name' => 'Needle paused', 'status' => InstanceStatus::SUSPENDED]);
    $inactive = instanceUiService($two, ['display_name' => 'Needle inactive', 'status' => InstanceStatus::ACTIVE, 'upstream_instance_id' => 'HOST-702']);
    instanceUiService($one, ['display_name' => 'Other service', 'status' => InstanceStatus::ACTIVE]);
    Instance::factory()->create(['display_name' => 'Needle local', 'status' => InstanceStatus::ACTIVE]);

    $data = instanceUiData('q=Needle&account_id='.$one->id.'&status=Active');
    expect($data['instances']->pluck('id')->all())->toBe([$match->id])
        ->and($data['filters'])->toBe(['q' => 'Needle', 'account_id' => (string) $one->id, 'status' => 'Active'])
        ->and(instanceUiData('q='.$suspended->id)['instances']->pluck('id')->all())->toBe([$suspended->id])
        ->and(instanceUiData('q=HOST-702')['instances']->pluck('id')->all())->toBe([$inactive->id])
        ->and(instanceUiData('account_id='.$two->id)['instances']->pluck('id')->all())->toBe([$inactive->id]);
});

it('uses safe projections and stable server pagination while retaining only supported list state', function (): void {
    $account = UpstreamApi::factory()->create(['encrypted_secret' => 'PRIVATE-INSTANCE-UPSTREAM', 'config' => ['private' => 'PRIVATE-INSTANCE-CONFIG']]);
    $instances = Instance::factory()->count(23)->sequence(fn ($sequence): array => ['display_name' => 'Paged instance '.$sequence->index,
        'driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id, 'upstream_instance_id' => (string) (700100 + $sequence->index),
        'status' => InstanceStatus::ACTIVE, 'active_config' => ['large' => str_repeat('PRIVATE-INSTANCE-LARGE', 100)],
        'encrypted_credentials' => ['password' => 'PRIVATE-INSTANCE-PASSWORD'], 'hostname' => 'host.example.test'])->create();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $data = instanceUiData('q=Paged&account_id='.$account->id.'&status=Active&page=2&instance='.$instances->last()->id.'&unapproved=discard');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($data['instances']->perPage())->toBe(20)->and($data['instances']->total())->toBe(23)
        ->and($data['instances']->pluck('id')->all())->toBe($instances->take(3)->reverse()->pluck('id')->all())
        ->and($data['selectedInstance']->id)->toBe($instances->last()->id)
        ->and($data['selectedInstance']->hostname)->toBe('host.example.test');
    foreach ([...$data['instances'], $data['selectedInstance']] as $instance) {
        expect(array_intersect(['encrypted_credentials', 'downstream_token', 'active_config', 'custom_field_values'], array_keys($instance->getAttributes())))->toBe([])
            ->and($instance->getRelations())->toBe([]);
    }
    expect(array_intersect(['encrypted_secret', 'config'], array_keys($data['accounts']->first()->getAttributes())))->toBe([])
        ->and(array_filter($queries, fn (array $query): bool => str_contains($query['query'], 'encrypted_credentials') || str_contains($query['query'], 'encrypted_secret')))->toBeEmpty();
    parse_str(parse_url($data['instances']->url(1), PHP_URL_QUERY), $paginationQuery);
    parse_str(parse_url($data['detailReturnUrl'], PHP_URL_QUERY), $returnQuery);
    expect($paginationQuery)->toBe(['q' => 'Paged', 'account_id' => (string) $account->id, 'status' => 'Active', 'page' => '1'])
        ->and($returnQuery)->toBe(['q' => 'Paged', 'account_id' => (string) $account->id, 'status' => 'Active', 'page' => '2']);
});

it('retains only forty newest classic tasks globally and narrows them to the selected service', function (): void {
    $account = UpstreamApi::factory()->create();
    $one = instanceUiService($account);
    $two = instanceUiService($account);
    $tasks = FulfillmentTask::factory()->count(42)->create(['instance_id' => $one->id, 'payload' => ['secret' => 'PRIVATE-TASK-PAYLOAD']]);
    $other = FulfillmentTask::factory()->create(['instance_id' => $two->id]);
    FulfillmentTask::factory()->create();
    $data = instanceUiData('q=Missing');
    expect($data['instances']->total())->toBe(0)->and($data['tasks'])->toHaveCount(40)
        ->and($data['tasks']->pluck('id')->all())->toBe([$other->id, ...$tasks->slice(-39)->reverse()->pluck('id')->all()]);
    foreach ($data['tasks'] as $task) {
        expect(array_key_exists('payload', $task->getAttributes()))->toBeFalse();
    }
    $selected = instanceUiData('q=Missing&instance='.$one->id);
    expect($selected['tasks'])->toHaveCount(40)
        ->and($selected['tasks']->pluck('id')->all())->toBe($tasks->slice(-40)->reverse()->pluck('id')->all())
        ->and($selected['selectedInstance']->id)->toBe($one->id);
});

it('renders one sidebar compact tables and real preserved filter values without credentials or unsafe diagnostics', function (): void {
    $account = UpstreamApi::factory()->create(['is_active' => false, 'encrypted_secret' => 'PRIVATE-INSTANCE-KEY']);
    $instance = instanceUiService($account, ['display_name' => 'Filtered paused', 'status' => InstanceStatus::SUSPENDED,
        'next_due_at' => null, 'recurring_amount' => '1234567890.12', 'encrypted_credentials' => ['password' => 'PRIVATE-INSTANCE-PASSWORD'],
        'downstream_token' => 'PRIVATE-INSTANCE-TOKEN', 'custom_field_values' => ['private' => 'PRIVATE-INSTANCE-CUSTOM']]);
    $task = FulfillmentTask::factory()->create(['instance_id' => $instance->id, 'status' => FulfillmentTaskStatus::FAILED,
        'error_message' => '<script>unknownDiagnostic()</script>', 'payload' => ['private' => 'PRIVATE-TASK-PAYLOAD'], 'attempts' => 3]);
    $before = $instance->fresh()->getRawOriginal();
    $response = $this->actingAs(instanceUiActor())->get('/admin/upstream-instances?q=Filtered&account_id='.$account->id.'&status=Suspended')
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertSee('Filtered paused')->assertSee('连接停用')
        ->assertSee('1234567890.12')->assertSee('未设置')->assertSee('已暂停')->assertSee('<script>unknownDiagnostic()</script>')
        ->assertDontSee('<script>unknownDiagnostic()</script>', false)->assertDontSee('PRIVATE-INSTANCE')->assertDontSee('PRIVATE-TASK');
    $xpath = instanceUiXPath($response->getContent());
    expect($xpath->query('//nav[contains(concat(" ", normalize-space(@class), " "), " fi-sidebar-nav ")]'))->toHaveCount(1)
        ->and($xpath->query('//table/caption[normalize-space(.)="经典魔方实例列表"]'))->toHaveCount(1)
        ->and($xpath->query('//table/caption[normalize-space(.)="全局最近任务"]'))->toHaveCount(1)
        ->and($xpath->query('//input[@name="q"][@value="Filtered"]'))->toHaveCount(1)
        ->and($xpath->query('//select[@name="account_id"]/option[@value="'.$account->id.'"][@selected]'))->toHaveCount(1)
        ->and($xpath->query('//select[@name="status"]/option[@value="Suspended"][@selected]'))->toHaveCount(1)
        ->and($xpath->query('//a[@href="/admin/machine-operation-review?instance_id='.$instance->id.'"]'))->toHaveCount(1)
        ->and($xpath->query('//form[@action="/admin/upstream-tasks/'.$task->id.'/retry"]'))->toHaveCount(0)
        ->and($instance->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('fulfillment_tasks', 1);
    $this->assertDatabaseCount('financial_transactions', 0);
});

it('distinguishes an empty service inventory from an empty filter including the string zero', function (): void {
    $this->actingAs(instanceUiActor())->get('/admin/upstream-instances')->assertOk()->assertSee('暂无经典魔方服务')->assertSee('暂无经典魔方任务。');
    $response = $this->get('/admin/upstream-instances?q=0')->assertOk()->assertSee('没有匹配实例')->assertDontSee('暂无经典魔方服务');
    $xpath = instanceUiXPath($response->getContent());
    expect($xpath->query('//table/tbody/tr/td[@colspan="6"]//a[@href="/admin/upstream-instances"]'))->toHaveCount(1);
});

it('keeps existing control swap review and retry form contracts in the selected service detail', function (): void {
    $account = UpstreamApi::factory()->create();
    $inactive = UpstreamApi::factory()->create(['is_active' => false]);
    $foreign = UpstreamApi::factory()->create(['driver' => DriverType::SUB2API]);
    $instance = instanceUiService($account, ['hostname' => 'node.example.test', 'primary_ip' => '192.0.2.22', 'last_synced_at' => now(),
        'suspend_reason' => '<b>Diagnostic reason</b>']);
    $task = FulfillmentTask::factory()->create(['instance_id' => $instance->id, 'status' => FulfillmentTaskStatus::FAILED]);
    $response = $this->actingAs(instanceUiActor(['upstream-lifecycle', 'upstream-migrator', 'upstream-mapping-reviewer']))
        ->get('/admin/upstream-instances?q=Missing&page=2&instance='.$instance->id)->assertOk()
        ->assertSee('node.example.test')->assertSee('192.0.2.22')->assertSee('客户 #'.$instance->user_id.' / 商品 #'.$instance->product_id)
        ->assertSee('<b>Diagnostic reason</b>')->assertDontSee('<b>Diagnostic reason</b>', false);
    $xpath = instanceUiXPath($response->getContent());
    $control = '//form[@method="post"][@action="/admin/upstream-instances/'.$instance->id.'/control"]';
    $swap = '//form[@method="post"][@action="/admin/upstream-instances/'.$instance->id.'/hot-swap"]';
    $retry = '//form[@method="post"][@action="/admin/upstream-tasks/'.$task->id.'/retry"]';
    expect($xpath->query($control.'//input[@name="_token"]'))->toHaveCount(1)
        ->and(Str::isUlid($xpath->query($control.'//input[@name="request_id"]')->item(0)->getAttribute('value')))->toBeTrue()
        ->and($xpath->query($control.'//select[@name="action"]/option'))->toHaveCount(3)
        ->and($xpath->query($control.'//input[@name="reason"][@maxlength="1000"][not(@required)]'))->toHaveCount(1)
        ->and($xpath->query($control.'//input[@name="confirm"][@required][@value="1"][not(@checked)]'))->toHaveCount(1)
        ->and($xpath->query($swap.'//input[@name="_token"]'))->toHaveCount(1)
        ->and($xpath->query($swap.'//select[@name="account_id"]/option[@value="'.$account->id.'"]'))->toHaveCount(1)
        ->and($xpath->query($swap.'//select[@name="account_id"]/option[@value="'.$inactive->id.'" or @value="'.$foreign->id.'"]'))->toHaveCount(0)
        ->and($xpath->query($swap.'//input[@name="host_id"][@type="number"][@required]'))->toHaveCount(1)
        ->and($xpath->query($swap.'//select[@name="disposition"]/option[@value="keep_orphan" or @value="terminate"]'))->toHaveCount(2)
        ->and($xpath->query($swap.'//input[@name="reason"][@required][@maxlength="255"]'))->toHaveCount(1)
        ->and($xpath->query($swap.'//input[@name="confirm"][@required][not(@checked)]'))->toHaveCount(1)
        ->and($xpath->query($swap.'//input[@name="pid"]'))->toHaveCount(0)
        ->and($xpath->query($retry.'//input[@name="_token"]'))->toHaveCount(1)
        ->and($xpath->query($retry.'//input[@name="reason"][@required]'))->toHaveCount(1)
        ->and($xpath->query($retry.'//input[@name="confirm"][@required][not(@checked)]'))->toHaveCount(1)
        ->and($xpath->query('//a[@href="/admin/upstream-instances/'.$instance->id.'/mapping-review"]'))->toHaveCount(1)
        ->and($xpath->query('//a[@href="/admin/upstream-instances?q=Missing&page=2"][normalize-space(.)="返回实例列表"]'))->toHaveCount(1);
});

it('isolates operation forms and mapping links by their original business permission', function (string $role, bool $control, bool $swap, bool $mapping): void {
    $instance = instanceUiService(UpstreamApi::factory()->create());
    $task = FulfillmentTask::factory()->create(['instance_id' => $instance->id, 'status' => FulfillmentTaskStatus::FAILED]);
    $success = FulfillmentTask::factory()->create(['instance_id' => $instance->id, 'status' => FulfillmentTaskStatus::SUCCESS]);
    $response = $this->actingAs(instanceUiActor([$role]))->get('/admin/upstream-instances?instance='.$instance->id)->assertOk();
    $xpath = instanceUiXPath($response->getContent());
    expect($xpath->query('//form[@action="/admin/upstream-instances/'.$instance->id.'/control"]'))->toHaveCount($control ? 1 : 0)
        ->and($xpath->query('//form[@action="/admin/upstream-instances/'.$instance->id.'/hot-swap"]'))->toHaveCount($swap ? 1 : 0)
        ->and($xpath->query('//form[@action="/admin/upstream-tasks/'.$task->id.'/retry"]'))->toHaveCount($control ? 1 : 0)
        ->and($xpath->query('//form[@action="/admin/upstream-tasks/'.$success->id.'/retry"]'))->toHaveCount(0)
        ->and($xpath->query('//a[@href="/admin/upstream-instances/'.$instance->id.'/mapping-review"]'))->toHaveCount($mapping ? 1 : 0);
})->with([
    ['upstream-viewer', false, false, false], ['upstream-lifecycle', true, false, false],
    ['upstream-migrator', false, true, false], ['upstream-mapping-reviewer', false, false, true],
]);

it('reopens a failed control with the original valid request id while keeping confirmation and unrelated forms clear', function (): void {
    $instance = instanceUiService(UpstreamApi::factory()->create());
    $url = '/admin/upstream-instances?instance='.$instance->id;
    $requestId = (string) Str::ulid();
    $this->actingAs(instanceUiActor(['upstream-lifecycle', 'upstream-migrator']))->from($url)
        ->post('/admin/upstream-instances/'.$instance->id.'/control', [
            'ui_form' => 'instance-control-'.$instance->id, 'request_id' => $requestId,
            'action' => 'terminate', 'reason' => 'Needs renewed confirmation',
        ])->assertRedirect($url)->assertSessionHasErrors('confirm');
    $error = session('errors')->first('confirm');
    $response = $this->get($url)->assertOk()->assertSee($error);
    $xpath = instanceUiXPath($response->getContent());
    $control = '//form[@action="/admin/upstream-instances/'.$instance->id.'/control"]';
    $swap = '//form[@action="/admin/upstream-instances/'.$instance->id.'/hot-swap"]';
    expect($xpath->query($control.'/ancestor::details[@open]'))->toHaveCount(1)
        ->and($xpath->query($control.'//input[@name="request_id"][@value="'.$requestId.'"]'))->toHaveCount(1)
        ->and($xpath->query($control.'//select[@name="action"]/option[@value="terminate"][@selected]'))->toHaveCount(1)
        ->and($xpath->query($control.'//input[@name="reason"][@value="Needs renewed confirmation"]'))->toHaveCount(1)
        ->and($xpath->query($swap.'/ancestor::details[@open]'))->toHaveCount(0)
        ->and($xpath->query($swap.'//input[@name="reason"][@value=""]'))->toHaveCount(1)
        ->and($xpath->query('//input[@name="confirm"][@checked]'))->toHaveCount(0);
    $this->assertDatabaseCount('fulfillment_tasks', 0);
});

it('reopens only a failed swap with its nonsecret inputs and requires confirmation again', function (): void {
    $account = UpstreamApi::factory()->create();
    $target = UpstreamApi::factory()->create();
    $instance = instanceUiService($account);
    $url = '/admin/upstream-instances?instance='.$instance->id;
    $unrelatedRequest = (string) Str::ulid();
    $this->actingAs(instanceUiActor(['upstream-lifecycle', 'upstream-migrator']))->from($url)
        ->post('/admin/upstream-instances/'.$instance->id.'/hot-swap', [
            'ui_form' => 'instance-hotswap-'.$instance->id, 'account_id' => $target->id,
            'host_id' => '7788', 'disposition' => 'terminate', 'reason' => 'Unsaved swap evidence',
            'request_id' => $unrelatedRequest, 'confirm' => '0',
        ])->assertRedirect($url)->assertSessionHasErrors('confirm');
    $response = $this->get($url)->assertOk();
    $xpath = instanceUiXPath($response->getContent());
    $swap = '//form[@action="/admin/upstream-instances/'.$instance->id.'/hot-swap"]';
    $control = '//form[@action="/admin/upstream-instances/'.$instance->id.'/control"]';
    expect($xpath->query($swap.'/ancestor::details[@open]'))->toHaveCount(1)
        ->and($xpath->query($swap.'//select[@name="account_id"]/option[@value="'.$target->id.'"][@selected]'))->toHaveCount(1)
        ->and($xpath->query($swap.'//input[@name="host_id"][@value="7788"]'))->toHaveCount(1)
        ->and($xpath->query($swap.'//select[@name="disposition"]/option[@value="terminate"][@selected]'))->toHaveCount(1)
        ->and($xpath->query($swap.'//input[@name="reason"][@value="Unsaved swap evidence"]'))->toHaveCount(1)
        ->and($xpath->query($control.'/ancestor::details[@open]'))->toHaveCount(0)
        ->and($xpath->query($control.'//input[@name="reason"][@value=""]'))->toHaveCount(1)
        ->and($xpath->query($control.'//input[@name="request_id"][@value="'.$unrelatedRequest.'"]'))->toHaveCount(0)
        ->and($xpath->query('//input[@name="confirm"][@checked]'))->toHaveCount(0);
    $this->assertDatabaseCount('instance_upstream_bindings', 0);
    $this->assertDatabaseCount('fulfillment_tasks', 0);
});

it('restores a failed retry to its own task without checking confirmation or filling another task reason', function (): void {
    $instance = instanceUiService(UpstreamApi::factory()->create());
    $task = FulfillmentTask::factory()->create(['instance_id' => $instance->id, 'status' => FulfillmentTaskStatus::FAILED]);
    $other = FulfillmentTask::factory()->create(['instance_id' => $instance->id, 'status' => FulfillmentTaskStatus::FAILED]);
    $this->actingAs(instanceUiActor(['upstream-lifecycle']))->from('/admin/upstream-instances')
        ->post('/admin/upstream-tasks/'.$task->id.'/retry', ['ui_form' => 'instance-task-retry-'.$task->id, 'reason' => 'Original task evidence'])
        ->assertRedirect('/admin/upstream-instances')->assertSessionHasErrors('confirm');
    $response = $this->get('/admin/upstream-instances')->assertOk();
    $xpath = instanceUiXPath($response->getContent());
    $retry = '//form[@action="/admin/upstream-tasks/'.$task->id.'/retry"]';
    $otherRetry = '//form[@action="/admin/upstream-tasks/'.$other->id.'/retry"]';
    expect($xpath->query($retry.'/ancestor::details[@open]'))->toHaveCount(1)
        ->and($xpath->query($retry.'//input[@name="reason"][@value="Original task evidence"]'))->toHaveCount(1)
        ->and($xpath->query($otherRetry.'/ancestor::details[@open]'))->toHaveCount(0)
        ->and($xpath->query($otherRetry.'//input[@name="reason"][@value=""]'))->toHaveCount(1)
        ->and($xpath->query('//input[@name="confirm"][@checked]'))->toHaveCount(0)
        ->and($task->fresh()->status)->toBe(FulfillmentTaskStatus::FAILED);
});

it('safely falls back from malformed operation inputs instead of reflecting arrays or reusing invalid request ids', function (string $operation): void {
    $instance = instanceUiService(UpstreamApi::factory()->create());
    $url = '/admin/upstream-instances?instance='.$instance->id;
    $this->actingAs(instanceUiActor(['upstream-lifecycle', 'upstream-migrator']))->from($url)
        ->post('/admin/upstream-instances/'.$instance->id.'/'.$operation, [
            'ui_form' => 'instance-'.($operation === 'control' ? 'control' : 'hotswap').'-'.$instance->id,
            'action' => ['invalid'], 'request_id' => ['invalid'], 'reason' => ['invalid'],
            'account_id' => ['invalid'], 'host_id' => ['invalid'], 'disposition' => ['invalid'], 'confirm' => '0',
        ])->assertRedirect($url)->assertSessionHasErrors('reason');
    $response = $this->get($url)->assertOk();
    $xpath = instanceUiXPath($response->getContent());
    $control = '//form[@action="/admin/upstream-instances/'.$instance->id.'/control"]';
    $swap = '//form[@action="/admin/upstream-instances/'.$instance->id.'/hot-swap"]';
    expect(Str::isUlid($xpath->query($control.'//input[@name="request_id"]')->item(0)->getAttribute('value')))->toBeTrue()
        ->and($xpath->query($control.'//input[@name="reason"][@value=""]'))->toHaveCount(1)
        ->and($xpath->query($control.'//select[@name="action"]/option[@value="suspend"][@selected]'))->toHaveCount(1)
        ->and($xpath->query($swap.'//input[@name="reason"][@value=""]'))->toHaveCount(1)
        ->and($xpath->query($swap.'//input[@name="host_id"][@value=""]'))->toHaveCount(1)
        ->and($xpath->query('//input[@name="confirm"][@checked]'))->toHaveCount(0);
})->with(['control', 'hot-swap']);

it('does not copy failed operation input to another instance detail', function (): void {
    $account = UpstreamApi::factory()->create();
    $instance = instanceUiService($account);
    $other = instanceUiService($account);
    $this->actingAs(instanceUiActor(['upstream-migrator']))->from('/admin/upstream-instances?instance='.$instance->id)
        ->post('/admin/upstream-instances/'.$instance->id.'/hot-swap', [
            'ui_form' => 'instance-hotswap-'.$instance->id, 'account_id' => $account->id,
            'host_id' => '8899', 'disposition' => 'terminate', 'reason' => 'Only the first instance', 'confirm' => '0',
        ])->assertSessionHasErrors('confirm');
    $response = $this->get('/admin/upstream-instances?instance='.$other->id)->assertOk();
    $xpath = instanceUiXPath($response->getContent());
    $swap = '//form[@action="/admin/upstream-instances/'.$other->id.'/hot-swap"]';
    expect($xpath->query($swap.'/ancestor::details[@open]'))->toHaveCount(0)
        ->and($xpath->query($swap.'//input[@name="reason"][@value=""]'))->toHaveCount(1)
        ->and($xpath->query($swap.'//input[@name="host_id"][@value=""]'))->toHaveCount(1);
});

it('preserves authentication and server permission checks for both list detail and direct operations', function (): void {
    $instance = instanceUiService(UpstreamApi::factory()->create());
    $task = FulfillmentTask::factory()->create(['instance_id' => $instance->id, 'status' => FulfillmentTaskStatus::FAILED]);
    $this->get('/admin/upstream-instances')->assertRedirect('/admin/login');
    $this->actingAs(User::factory()->create())->get('/admin/upstream-instances?instance='.$instance->id)->assertForbidden();
    $this->actingAs(instanceUiActor())->get('/admin/upstream-instances?instance='.$instance->id)->assertOk();
    $this->post('/admin/upstream-instances/'.$instance->id.'/control', [])->assertForbidden();
    $this->post('/admin/upstream-instances/'.$instance->id.'/hot-swap', [])->assertForbidden();
    $this->post('/admin/upstream-tasks/'.$task->id.'/retry', [])->assertForbidden();
    $this->assertDatabaseCount('fulfillment_tasks', 1);
    $this->assertDatabaseCount('financial_transactions', 0);
});

it('rejects nonexistent malformed and other driver instance selections', function (string $selection): void {
    $local = Instance::factory()->create();
    $this->actingAs(instanceUiActor())->get('/admin/upstream-instances?instance='.($selection === 'local' ? $local->id : $selection))->assertNotFound();
})->with(['999999', 'local', '0', '-1', 'not-an-id', '1.5', '1&instance[]=2']);

it('validates supported scalar filters and enum lifecycle values', function (string $query): void {
    $this->actingAs(instanceUiActor())->getJson('/admin/upstream-instances?'.$query)->assertUnprocessable()
        ->assertJsonPath('message', '实例筛选参数无效。');
})->with(['q[]=cloud', 'q='.str_repeat('a', 101), 'account_id[]=1', 'account_id=0', 'status=active', 'status[]=Active', 'page=0']);
