<?php

declare(strict_types=1);

use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\ProductOptionGroup;
use App\Models\Ticket;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Services\AdminInstanceQueries;
use App\Support\AdminRoles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Http::preventStrayRequests();
    Queue::fake();
});

afterEach(function (): void {
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

function workbenchActor(string ...$permissions): User
{
    $actor = User::factory()->create();
    foreach ($permissions as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $actor;
}

function workbenchClassic(array $attributes = []): Instance
{
    return Instance::factory()->active()->create($attributes + ['driver_type' => DriverType::ZJMF_API,
        'upstream_api_id' => UpstreamApi::factory()->create()->id, 'upstream_instance_id' => 'C4-LOCAL-READ']);
}

function workbenchXpath(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

    return new DOMXPath($document);
}

it('requires an enabled administrator with upstream view before reading an instance', function (): void {
    $instance = workbenchClassic();
    $this->get('/admin/instances/'.$instance->id)->assertRedirect('/admin/login');
    $this->actingAs(User::factory()->create())->get('/admin/instances/'.$instance->id)->assertForbidden();
    $actor = workbenchActor('finance.view', 'tasks.view', 'support.view');
    $this->actingAs($actor)->get('/admin/instances/'.$instance->id)->assertForbidden();
    $actor = workbenchActor('upstream.view');
    $this->actingAs($actor)->get('/admin/instances/'.$instance->id)->assertOk();
    $actor->forceFill(['disabled_at' => now()])->save();
    expect(fn () => app(AdminInstanceQueries::class)->detail($actor, $instance->id, []))
        ->toThrow(HttpException::class);
});

it('returns not found for invalid or absent workbench identities', function (string $id): void {
    $this->actingAs(workbenchActor('upstream.view'))->get('/admin/instances/'.$id)->assertNotFound();
})->with(['0', '-1', 'words', '1.5', '999999999999999999999999999', '999999']);

it('renders every existing fulfillment driver from safe local facts without decrypting delivery data', function (DriverType $driver): void {
    $owner = User::factory()->create(['name' => 'PRIVATE-C4-CUSTOMER-NAME']);
    $attributes = ['driver_type' => $driver, 'user_id' => $owner->id, 'hostname' => 'c4-node.example.test',
        'primary_ip' => '192.0.2.24', 'assigned_ips' => ['192.0.2.25', 'PRIVATE-C4-INVALID-IP'],
        'custom_field_values' => ['secret' => 'PRIVATE-C4-CUSTOM-FIELD'],
        'active_config' => ['arbitrary' => 'PRIVATE-C4-ARBITRARY', 'cloud_operation' => ['encrypted_binding' => 'INVALID-C4-BINDING']]];
    $instance = $driver === DriverType::ZJMF_API ? workbenchClassic($attributes) : Instance::factory()->active()->create($attributes);
    DB::table('instances')->where('id', $instance->id)->update(['encrypted_credentials' => 'INVALID-C4-CIPHERTEXT', 'downstream_token' => 'PRIVATE-C4-TOKEN']);
    $response = $this->actingAs(workbenchActor('upstream.view'))->get('/admin/instances/'.$instance->id)->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')->assertSee('c4-node.example.test')->assertSee('192.0.2.25')
        ->assertDontSee('PRIVATE-C4')->assertDontSee('INVALID-C4');
    $xpath = workbenchXpath($response->getContent());
    expect($xpath->query('//main//form[@method="post"]'))->toHaveCount(0)
        ->and($xpath->query('//main//a[starts-with(@href,"/admin/customers/")]'))->toHaveCount(0);
    if ($driver !== DriverType::ZJMF_API) {
        $response->assertSee($driver === DriverType::PROXMOX ? '此驱动尚未交付后台远端控制能力' : '未开放后台远端机器控制与上游同步')
            ->assertDontSee('读取机器管理菜单')->assertDontSee('同步上游事实')->assertDontSee('机器命令核对与历史');
    }
})->with([DriverType::ZJMF_API, DriverType::REDEMPTION, DriverType::SUB2API, DriverType::PROXMOX]);

it('checks every domain authority before querying customer invoices tasks or tickets', function (array $extra, array $allowed): void {
    $instance = workbenchClassic();
    $actor = workbenchActor('upstream.view', ...$extra);
    $actor->getAllPermissions();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $data = app(AdminInstanceQueries::class)->detail($actor, $instance->id, []);
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();
    foreach (['users', 'invoices', 'fulfillment_tasks', 'tickets'] as $table) {
        $domainQueries = array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from "'.$table.'"')));
        expect($domainQueries !== [])->toBe(in_array($table, $allowed, true));
    }
    expect(array_intersect(['encrypted_credentials', 'downstream_token', 'active_config', 'custom_field_values', 'public_option_selection'],
        array_keys($data['instance']->getAttributes())))->toBe([]);
    foreach ($queries as $sql) {
        expect($sql)->not->toContain('encrypted_credentials', 'downstream_token', 'encrypted_secret', 'error_message', 'payload');
    }
})->with([
    'base only' => [[], []],
    'customer facts' => [['customers.view'], ['users']],
    'invoice facts' => [['finance.view'], ['invoices']],
    'task facts' => [['tasks.view'], ['fulfillment_tasks']],
    'support facts' => [['support.view'], ['tickets']],
]);

it('does not query any instance before service authorization succeeds', function (): void {
    $actor = workbenchActor('finance.view');
    $actor->getAllPermissions();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        app(AdminInstanceQueries::class)->detail($actor, 987654321, []);
        test()->fail('Unprivileged projection was accepted.');
    } catch (AuthorizationException) {
        expect(array_filter(DB::getQueryLog(), fn (array $row): bool => str_contains($row['query'], 'from "instances"')))->toBeEmpty();
    } finally {
        DB::disableQueryLog();
    }
});

it('projects public option labels from numeric selections and never copies arbitrary saved JSON', function (): void {
    $instance = workbenchClassic(['active_config' => ['upstream_configoption' => ['101' => 201, '102' => 4, '103' => 203, '104' => 'PRIVATE-C4-SELECTION'],
        'mapping_requires_review' => true, 'price_requires_review' => true, 'private' => 'PRIVATE-C4-ROOT']]);
    foreach ([['101', 'select', '操作系统', '201', 'Debian 12'], ['102', 'quantity', 'CPU 核数', '202', '基础 CPU'],
        ['103', 'select', 'PRIVATE-C4-HIDDEN', '203', 'PRIVATE-C4-HIDDEN-ITEM'], ['104', 'select', '公开选项', '204', '公开值']] as [$groupId, $type, $label, $itemId, $value]) {
        $group = ProductOptionGroup::create(['product_id' => $instance->product_id, 'upstream_group_id' => $groupId,
            'name' => $label, 'type' => $type, 'sync_source' => 'upstream', 'is_hidden' => $groupId === '103']);
        $group->items()->create(['upstream_sub_id' => $itemId, 'label' => $value]);
    }
    $data = app(AdminInstanceQueries::class)->detail(workbenchActor('upstream.view'), $instance->id, []);
    expect($data['configuration'])->toBe([['label' => '操作系统', 'value' => 'Debian 12'], ['label' => 'CPU 核数', 'value' => '4']])
        ->and($data['mappingReview'])->toBeTrue()->and($data['priceReview'])->toBeTrue();
    $this->actingAs(workbenchActor('upstream.view'))->get('/admin/instances/'.$instance->id)->assertOk()
        ->assertSee('Debian 12')->assertSee('CPU 核数')->assertSee('待核准')->assertSee('待客户确认')->assertDontSee('PRIVATE-C4');
});

it('keeps related records scoped to both the selected instance and its original customer', function (): void {
    $instance = workbenchClassic();
    $foreign = workbenchClassic();
    $actor = workbenchActor('upstream.view', 'finance.view', 'tasks.view', 'support.view', 'customers.view');
    $invoice = Invoice::factory()->paid()->create(['instance_id' => $instance->id, 'user_id' => $instance->user_id]);
    $task = FulfillmentTask::factory()->create(['instance_id' => $instance->id, 'invoice_id' => $invoice->id]);
    $ticket = Ticket::create(['instance_id' => $instance->id, 'user_id' => $instance->user_id, 'subject' => 'C4 owned ticket']);
    Invoice::factory()->create(['instance_id' => $instance->id, 'user_id' => $foreign->user_id]);
    FulfillmentTask::factory()->create(['instance_id' => $instance->id, 'user_id' => $foreign->user_id]);
    Ticket::create(['instance_id' => $instance->id, 'user_id' => $foreign->user_id, 'subject' => 'PRIVATE-C4-FOREIGN-TICKET']);
    Ticket::create(['instance_id' => $foreign->id, 'user_id' => $instance->user_id, 'subject' => 'PRIVATE-C4-OTHER-INSTANCE']);
    $data = app(AdminInstanceQueries::class)->detail($actor, $instance->id, []);
    expect($data['invoices']->pluck('id')->all())->toBe([$invoice->id])
        ->and($data['tasks']->pluck('id')->all())->toBe([$task->id])
        ->and($data['tickets']->pluck('id')->all())->toBe([$ticket->id]);
    $this->actingAs($actor)->get('/admin/instances/'.$instance->id)->assertOk()->assertSee('C4 owned ticket')
        ->assertDontSee('PRIVATE-C4');
});

it('paginates each related domain independently with only approved query keys and section fragments', function (): void {
    $instance = workbenchClassic();
    $actor = workbenchActor('upstream.view', 'finance.view', 'tasks.view', 'support.view');
    for ($i = 0; $i < 12; $i++) {
        $invoice = Invoice::factory()->paid()->create(['instance_id' => $instance->id, 'user_id' => $instance->user_id]);
        FulfillmentTask::factory()->create(['instance_id' => $instance->id, 'invoice_id' => $invoice->id]);
        Ticket::create(['instance_id' => $instance->id, 'user_id' => $instance->user_id, 'subject' => 'Paged ticket '.$i]);
    }
    $data = app(AdminInstanceQueries::class)->detail($actor, $instance->id,
        ['invoices_page' => '2', 'tasks_page' => '2', 'tickets_page' => '2', 'secret' => 'PRIVATE-C4-QUERY']);
    foreach (['invoices', 'tasks', 'tickets'] as $key) {
        expect($data[$key]->total())->toBe(12)->and($data[$key]->count())->toBe(2)->and($data[$key]->perPage())->toBe(10)
            ->and($data[$key]->url(1))->toEndWith('#instance-'.$key)->not->toContain('secret', 'PRIVATE-C4');
        parse_str(parse_url($data[$key]->url(1), PHP_URL_QUERY), $query);
        expect(array_keys($query))->toEqualCanonicalizing(['invoices_page', 'tasks_page', 'tickets_page']);
    }
});

it('rejects malformed related pagination as an HTTP error', function (string $query): void {
    $instance = workbenchClassic();
    $this->actingAs(workbenchActor('upstream.view'))->getJson('/admin/instances/'.$instance->id.'?'.$query)
        ->assertUnprocessable()->assertJsonPath('message', '实例明细分页参数无效。');
})->with(['invoices_page[]=1', 'tasks_page=0', 'tickets_page=-1', 'tasks_page=2147483648']);

it('shows remote menu and explicit synchronization only for their independent authority and eligible binding', function (array $extra, bool $machine, bool $sync): void {
    $instance = workbenchClassic();
    $response = $this->actingAs(workbenchActor('upstream.view', ...$extra))->get('/admin/instances/'.$instance->id)->assertOk();
    $xpath = workbenchXpath($response->getContent());
    expect($xpath->query('//form[@action="/admin/upstream-instances/'.$instance->id.'/machine"]'))->toHaveCount($machine ? 1 : 0)
        ->and($xpath->query('//dialog[@id="instance-sync"]//form[@action="/admin/upstream-instances/'.$instance->id.'/sync"]'))->toHaveCount($sync ? 1 : 0);
    if ($sync) {
        expect($xpath->query('//dialog[@id="instance-sync"]//input[@name="reason"][@maxlength="255"][not(@required)]'))->toHaveCount(1)
            ->and($xpath->query('//dialog[@id="instance-sync"]//input[@name="confirm" or @name="request_id"]'))->toHaveCount(0);
    }
})->with([
    'viewer' => [[], false, false], 'lifecycle only' => [['upstream.lifecycle'], false, false],
    'machine only' => [['upstream.machine-control'], true, false], 'sync only' => [['upstream.sync'], false, true],
    'both' => [['upstream.machine-control', 'upstream.sync'], true, true],
]);

it('checks local operation eligibility without treating an old intent status as proof of an unresolved request', function (string $kind): void {
    $instance = workbenchClassic();
    match ($kind) {
        'terminal' => $instance->update(['status' => InstanceStatus::DELETED]),
        'unbound' => $instance->update(['upstream_instance_id' => null]),
        'inactive' => $instance->upstreamApi()->update(['is_active' => false]),
        'pending command' => $instance->update(['active_config' => ['cloud_operation' => ['status' => 'unknown', 'encrypted_binding' => 'INVALID-C4-ENCRYPTED']]]),
        'cancellation' => $instance->update(['active_config' => ['cancellation' => ['task_id' => 99]]]),
    };
    $data = app(AdminInstanceQueries::class)->detail(workbenchActor('upstream.view', 'upstream.machine-control', 'upstream.sync'), $instance->id, []);
    expect($data['canMachine'])->toBe($kind === 'pending command')
        ->and($data['canSync'])->toBe(in_array($kind, ['pending command', 'cancellation'], true));
})->with(['terminal', 'unbound', 'inactive', 'pending command', 'cancellation']);

it('offers a new retry dialog only for current paid failed deliveries and all required domains', function (string $kind, bool $allowed): void {
    $instance = $kind === 'local' ? Instance::factory()->create(['status' => InstanceStatus::PROVISION_FAILED]) : workbenchClassic();
    $task = FulfillmentTask::factory()->create(['instance_id' => $instance->id, 'status' => FulfillmentTaskStatus::FAILED]);
    $permissions = ['upstream.view', 'tasks.view', 'finance.view', 'upstream.lifecycle', 'inventory.view', 'inventory.retry'];
    if ($kind === 'no finance') {
        $permissions = array_values(array_diff($permissions, ['finance.view']));
    } elseif ($kind === 'no tasks') {
        $permissions = array_values(array_diff($permissions, ['tasks.view']));
    } elseif ($kind === 'no operation') {
        $permissions = array_values(array_diff($permissions, ['upstream.lifecycle']));
    } elseif ($kind === 'unpaid') {
        $task->invoice()->update(['status' => InvoiceStatus::UNPAID, 'paid_at' => null]);
    } elseif ($kind === 'running') {
        $task->update(['status' => FulfillmentTaskStatus::RUNNING]);
    } elseif ($kind === 'stale binding') {
        $task->update(['upstream_api_id' => UpstreamApi::factory()->create()->id]);
    } elseif ($kind === 'terminal') {
        $instance->update(['status' => InstanceStatus::DELETED]);
    }
    $response = $this->actingAs(workbenchActor(...$permissions))->get('/admin/instances/'.$instance->id)->assertOk();
    $xpath = workbenchXpath($response->getContent());
    $form = '//dialog[@id="instance-task-retry-'.$task->id.'"]//form';
    expect($xpath->query($form))->toHaveCount($allowed ? 1 : 0);
    if ($allowed) {
        expect($xpath->query($form.'[@action="/admin/instances/'.$instance->id.'/tasks/'.$task->id.'/retry"]'))->toHaveCount(1)
            ->and($xpath->query($form.'//input[@name="reason"][@maxlength="255"][not(@required)]'))->toHaveCount(1)
            ->and($xpath->query($form.'//input[@name="confirm"][@required][not(@checked)]'))->toHaveCount(1);
    }
})->with([
    ['classic', true], ['local', true], ['no finance', false], ['no tasks', false], ['no operation', false],
    ['unpaid', false], ['running', false], ['stale binding', false], ['terminal', false],
]);

it('restores only the matching dialog and target without restoring confirmation or machine secrets', function (): void {
    $instance = workbenchClassic();
    $other = workbenchClassic();
    $actor = workbenchActor('upstream.view', 'upstream.sync');
    $response = $this->actingAs($actor)->withSession(['errors' => (new ViewErrorBag)->put('default',
        new MessageBag(['reason' => 'C4 validation rejected'])), '_old_input' => [
            '_admin_form' => 'instance-sync', 'instance_id' => $instance->id, 'reason' => 'C4 retained note',
            'confirm' => '1', 'temp_pass' => 'PRIVATE-C4-TEMP-PASS', 'command' => 'PRIVATE-C4-COMMAND']])
        ->get('/admin/instances/'.$instance->id)->assertOk()->assertSee('C4 retained note')->assertDontSee('PRIVATE-C4');
    $xpath = workbenchXpath($response->getContent());
    expect($xpath->query('//dialog[@id="instance-sync"][@x-init]')->item(0)->getAttribute('x-init'))->toContain('showModal');
    $this->get('/admin/instances/'.$other->id)->assertOk()->assertDontSee('C4 retained note')->assertDontSee('PRIVATE-C4');
});

it('keeps one global sidebar and thirty one entries while mapping the hidden workbench to upstream services', function (): void {
    $permissions = array_values(array_unique(array_merge(...array_values(AdminRoles::permissions()))));
    $actor = workbenchActor(...$permissions);
    $instance = workbenchClassic();
    $response = $this->actingAs($actor)->get('/admin/instances/'.$instance->id)->assertOk();
    $xpath = workbenchXpath($response->getContent());
    $sidebars = $xpath->query('//nav[contains(concat(" ",normalize-space(@class)," ")," fi-sidebar-nav ")]');
    $urls = [];
    foreach ($xpath->query('.//a[@href]', $sidebars->item(0)) as $link) {
        $urls[] = $link->getAttribute('href');
    }
    expect($sidebars)->toHaveCount(1)
        ->and(array_unique($urls))->toHaveCount(31)
        ->and($xpath->query('.//li[contains(concat(" ",normalize-space(@class)," ")," fi-active ")]/a[@href="/admin/upstream-instances"]', $sidebars->item(0)))->toHaveCount(1)
        ->and($xpath->query('//main//aside|//main//*[@role="tablist"]'))->toHaveCount(0);
});

it('adds workbench links while retaining classic detail customer service and task entry contracts', function (): void {
    $instance = workbenchClassic();
    $local = Instance::factory()->create(['user_id' => $instance->user_id]);
    $task = FulfillmentTask::factory()->create(['instance_id' => $instance->id]);
    $this->actingAs(workbenchActor('upstream.view', 'customers.view', 'tasks.view'));
    $this->get('/admin/upstream-instances?instance='.$instance->id)->assertOk()
        ->assertSee('href="/admin/instances/'.$instance->id.'?from%5Bkind%5D=instances"', false)->assertSee('机器命令核对与历史');
    $response = $this->get('/admin/customers/'.$instance->user_id)->assertOk();
    $xpath = workbenchXpath($response->getContent());
    $source = http_build_query(['from' => ['kind' => 'customer', 'id' => $instance->user_id, 'section' => 'customer-services']]);
    foreach ([$instance, $local] as $service) {
        expect($xpath->query('//main//a[@href="/admin/instances/'.$service->id.'?'.$source.'"]'))->toHaveCount(1);
    }
    expect($xpath->query('//main//a[@href="/admin/upstream-instances?instance='.$instance->id.'"]'))->toHaveCount(1)
        ->and($xpath->query('//main//a[@href="/admin/upstream-instances?instance='.$local->id.'"]'))->toHaveCount(0);
    $this->get('/admin/tasks?task_id='.$task->id)->assertOk()->assertSee('href="/admin/instances/'.$instance->id.'"', false);
});

it('restricts the legacy queue by customer when a customer filter is explicitly supplied', function (): void {
    $instance = workbenchClassic();
    $other = workbenchClassic();
    FulfillmentTask::factory()->create(['instance_id' => $instance->id, 'error_message' => 'C4 owned legacy diagnostic']);
    FulfillmentTask::factory()->create(['instance_id' => $other->id, 'error_message' => 'PRIVATE-C4-OTHER-DIAGNOSTIC']);
    $this->actingAs(workbenchActor('upstream.view'))->get('/admin/upstream-instances?user_id='.$instance->user_id)
        ->assertOk()->assertSee('C4 owned legacy diagnostic')->assertDontSee('PRIVATE-C4-OTHER-DIAGNOSTIC');
});

it('keeps GET business rows unchanged and selects no task payload or unfiltered configuration', function (): void {
    $instance = workbenchClassic(['encrypted_credentials' => ['password' => 'PRIVATE-C4-CREDENTIAL'],
        'active_config' => ['cloud_operation' => ['status' => 'unknown', 'encrypted_binding' => 'INVALID-C4-BINDING']]]);
    FulfillmentTask::factory()->create(['instance_id' => $instance->id, 'error_message' => 'PRIVATE-C4-RAW-ERROR',
        'payload' => ['encrypted_operation' => 'INVALID-C4-PAYLOAD']]);
    $actor = workbenchActor('upstream.view', 'customers.view', 'finance.view', 'tasks.view', 'support.view');
    $tables = ['users', 'instances', 'invoices', 'financial_transactions', 'fulfillment_tasks', 'tickets', 'audit_logs', 'notification_events'];
    $snapshot = fn (): array => collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    $before = $snapshot();
    $this->actingAs($actor)->get('/admin/instances/'.$instance->id)->assertOk()->assertDontSee('PRIVATE-C4')->assertDontSee('INVALID-C4');
    expect($snapshot())->toBe($before);
    $data = app(AdminInstanceQueries::class)->detail($actor, $instance->id, []);
    expect($data['machinePending'])->toBeTrue();
    foreach ($data['tasks'] as $task) {
        expect(array_intersect(['error_message', 'payload'], array_keys($task->getAttributes())))->toBe([]);
    }
});
