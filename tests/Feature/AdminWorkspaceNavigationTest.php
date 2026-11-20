<?php

declare(strict_types=1);

use App\Actions\SyncAdminInstanceAction;
use App\Enums\DriverType;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Services\AdminCustomerQueries;
use App\Services\AdminInstanceQueries;
use App\Support\AdminWorkspaceNavigation as Navigation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
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
});

function workspaceActor(array $permissions = ['customers.view', 'upstream.view', 'finance.view']): User
{
    $actor = User::factory()->create();
    foreach ($permissions as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $actor;
}

function workspaceXpath(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

    return new DOMXPath($document);
}

function workspaceLink(string $html, string $selector): string
{
    $node = workspaceXpath($html)->query($selector)->item(0);
    expect($node)->toBeInstanceOf(DOMElement::class);

    return $node->getAttribute('href');
}

function workspaceQuery(string $url): array
{
    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

    return $query;
}

it('restores only the immediate allowed source and its own query', function (string $kind, ?int $id, array $query, ?string $section): void {
    $actor = workspaceActor();
    $origin = Navigation::origin($actor, $kind, $id, $query + ['from' => ['kind' => 'customers'], 'return_url' => 'https://evil.test'], $section);
    $link = Navigation::returnLink($actor, $origin, '/admin/customers', '默认返回');
    expect($link['url'])->toBe(Navigation::url($kind, $id, $query, $section))
        ->and($origin['from']['query'] ?? [])->not->toHaveKey('from')->not->toHaveKey('return_url');
})->with([
    'customer list' => ['customers', null, ['name' => '张三', 'status' => 'disabled', 'page' => 3], null],
    'customer workbench' => ['customer', 19, ['services_page' => 2, 'invoices_page' => 3], 'customer-services'],
    'instance list' => ['instances', null, ['q' => '东京', 'account_id' => 4, 'user_id' => 19, 'status' => 'Active', 'page' => 2], null],
    'instance workbench' => ['instance', 28, ['invoices_page' => 2], 'instance-invoices'],
    'invoice list' => ['invoices', null, ['invoice_no' => 'INV-U1', 'status' => 'Unpaid', 'type' => 'new_order', 'page' => 4], null],
    'invoice workbench' => ['invoice', 37, ['ledger_page' => 2, 'tasks_page' => 3], null],
]);

it('discards unrecognized or malformed source addresses', function (mixed $source): void {
    $actor = workspaceActor();
    $link = Navigation::returnLink($actor, ['from' => $source, 'return_url' => 'https://evil.test'], '/admin/customers', '默认返回');
    expect($link)->toBe(['url' => '/admin/customers', 'label' => '默认返回']);
})->with([
    'absolute URL' => ['https://evil.test'],
    'protocol relative URL' => ['//evil.test'],
    'unknown kind' => [['kind' => 'https://evil.test']],
    'array kind' => [['kind' => ['customer'], 'id' => 9]],
    'array id' => [['kind' => 'customer', 'id' => [9]]],
    'negative id' => [['kind' => 'instance', 'id' => -1]],
    'missing id' => [['kind' => 'invoice']],
    'array section' => [['kind' => 'customer', 'id' => 9, 'section' => ['customer-profile']]],
    'array query' => [['kind' => 'customer', 'id' => 9, 'query' => 'https://evil.test']],
    'array page' => [['kind' => 'customer', 'id' => 9, 'query' => ['services_page' => [2]]]],
    'invalid page' => [['kind' => 'customer', 'id' => 9, 'query' => ['services_page' => 0]]],
]);

it('drops ungranted source domains without querying their tables', function (string $kind, string $permission): void {
    $actor = workspaceActor([$permission]);
    $actor->can('customers.view'); // Warm permission metadata before observing business-table reads.
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $link = Navigation::returnLink($actor, ['from' => ['kind' => $kind, 'id' => 999]], '/admin/customers', '默认返回');
    expect($link['url'])->toBe('/admin/customers')->and($queries)->toBe([]);
})->with([
    'finance-only customer' => ['customer', 'finance.view'],
    'customer-only instance' => ['instance', 'customers.view'],
    'upstream-only invoice' => ['invoice', 'upstream.view'],
]);

it('never propagates unknown keys nested values or a recursive navigation history', function (): void {
    $actor = workspaceActor();
    $input = ['from' => ['kind' => 'customer', 'id' => 9, 'section' => 'https://evil.test',
        'query' => ['services_page' => 2, 'unknown_page' => [3], 'credit' => '100.00', 'from' => ['kind' => 'invoices']]],
        'tasks_page' => 3, 'password' => 'PRIVATE-U1', 'return_url' => 'https://evil.test'];
    $query = Navigation::query($actor, 'instance', $input);
    $encoded = http_build_query($query);
    expect($query['tasks_page'])->toBe(3)->and($query['from']['query']['services_page'])->toBe(2)
        ->and($encoded)->not->toContain('PRIVATE-U1', 'evil.test', 'credit', 'invoices_page', '%5Bfrom%5D');
});

it('preserves customer list filters and original page in both detail entry links', function (): void {
    $actor = workspaceActor();
    User::factory()->count(22)->create(['name' => 'U1 筛选同名客户']);
    $query = ['name' => 'U1 筛选同名客户', 'status' => 'active', 'kyc' => 'unverified', 'page' => 2];
    $html = $this->actingAs($actor)->get('/admin/customers?'.http_build_query($query))->assertOk()->getContent();
    $xpath = workspaceXpath($html);
    $links = $xpath->query('//main//a[starts-with(@href,"/admin/customers/")]');
    expect($links->length)->toBeGreaterThanOrEqual(2);
    foreach ($links as $link) {
        $detail = $this->get($link->getAttribute('href'))->assertOk()->getContent();
        $return = workspaceLink($detail, '//*[@data-workspace-return]');
        expect(parse_url($return, PHP_URL_PATH))->toBe('/admin/customers')->and(workspaceQuery($return))->toEqual($query);
    }
});

it('retains instance list filters and pagination when entering its independent workbench', function (): void {
    $actor = workspaceActor();
    $instance = Instance::factory()->active()->create(['driver_type' => DriverType::ZJMF_API, 'display_name' => 'U1 云主机']);
    $query = ['q' => 'U1', 'user_id' => $instance->user_id, 'account_id' => $instance->upstream_api_id, 'status' => 'Active'];
    $html = $this->actingAs($actor)->get('/admin/upstream-instances?'.http_build_query($query))->assertOk()->getContent();
    $entry = workspaceLink($html, '//main//a[starts-with(@href,"/admin/instances/'.$instance->id.'")]');
    $detail = $this->get($entry)->assertOk()->getContent();
    $return = workspaceLink($detail, '//*[@data-workspace-return]');
    expect(parse_url($return, PHP_URL_PATH))->toBe('/admin/upstream-instances')->and(workspaceQuery($return))->toEqual($query);
});

it('connects customer instance and invoice workbenches with single hop returns', function (): void {
    $actor = workspaceActor();
    $owner = User::factory()->create();
    $instance = Instance::factory()->active()->create(['user_id' => $owner->id, 'display_name' => 'U1 实例']);
    $invoice = Invoice::factory()->paid()->create(['user_id' => $owner->id, 'instance_id' => $instance->id]);
    $customer = $this->actingAs($actor)->get('/admin/customers/'.$owner->id)->assertOk()->getContent();
    $entry = workspaceLink($customer, '//*[@id="customer-services"]//a[starts-with(@href,"/admin/instances/")]');
    $detail = $this->get($entry)->assertOk()->getContent();
    expect(workspaceLink($detail, '//*[@data-workspace-return]'))->toBe('/admin/customers/'.$owner->id.'#customer-services');
    $billEntry = workspaceLink($detail, '//*[@id="instance-invoices"]//a[contains(@href,"invoice_id='.$invoice->id.'")]');
    $bill = $this->get($billEntry)->assertOk()->getContent();
    expect(workspaceLink($bill, '//*[@data-workspace-return]'))->toBe('/admin/instances/'.$instance->id.'#instance-invoices')
        ->and(workspaceQuery($billEntry)['from']['query'] ?? [])->not->toHaveKey('from');
    $instanceEntry = workspaceLink($bill, '//*[@id="invoice-summary"]//a[starts-with(@href,"/admin/instances/'.$instance->id.'")]');
    expect(workspaceQuery($instanceEntry)['from']['kind'])->toBe('invoice');
});

it('keeps the allowed source across independent customer and instance pagination', function (): void {
    $actor = workspaceActor();
    $owner = User::factory()->create();
    $instance = Instance::factory()->active()->create(['user_id' => $owner->id]);
    Invoice::factory()->count(12)->create(['user_id' => $owner->id, 'instance_id' => $instance->id]);
    $from = Navigation::origin($actor, 'invoices', null, ['user_id' => $owner->id, 'status' => 'Unpaid', 'page' => 2]);
    $customer = app(AdminCustomerQueries::class)->detail($actor, $owner->id, $from + ['ledger_page' => 3]);
    $service = app(AdminInstanceQueries::class)->detail($actor, $instance->id, $from + ['tasks_page' => 3]);
    foreach ([$customer['invoices'], $service['invoices']] as $paginator) {
        $query = workspaceQuery($paginator->nextPageUrl());
        expect($query['from'])->toEqual($from['from'])->and($query['invoices_page'])->toBe('2');
    }
    expect(workspaceQuery($customer['invoices']->nextPageUrl())['ledger_page'])->toBe('3')
        ->and(workspaceQuery($service['invoices']->nextPageUrl())['tasks_page'])->toBe('3');
});

it('preserves safe source on profile success and validation failure instead of trusting referer', function (): void {
    $actor = workspaceActor(['customers.view', 'customers.manage']);
    $owner = User::factory()->create();
    $query = Navigation::origin($actor, 'customers', null, ['name' => 'U1', 'page' => 2]) + ['invoices_page' => 3];
    $target = Navigation::url('customer', $owner->id, $query, 'customer-profile');
    $action = '/admin/customers/'.$owner->id.'/profile?'.http_build_query($query);
    $this->actingAs($actor)->from('https://evil.test')->post($action, ['name' => 'U1 新名称', 'email' => $owner->email, 'phone' => ''])
        ->assertRedirect($target)->assertSessionHasNoErrors();
    expect($owner->fresh()->name)->toBe('U1 新名称');
    $this->from('https://evil.test')->post($action, ['name' => '', 'email' => 'bad'])
        ->assertRedirect($target)->assertSessionHasErrors(['name', 'email']);
});

it('keeps invoice old list filters and source on offline validation while stripping private receipt input', function (): void {
    $actor = workspaceActor(['customers.view', 'finance.view', 'finance.offline-receipt']);
    $owner = User::factory()->create();
    $invoice = Invoice::factory()->create(['user_id' => $owner->id]);
    $query = Navigation::origin($actor, 'customer', $owner->id, ['invoices_page' => 2], 'customer-invoices')
        + ['status' => 'Unpaid', 'page' => 3, 'ledger_page' => 2];
    $target = Navigation::url('invoice', $invoice->id, $query);
    $this->actingAs($actor)->from('https://evil.test')->post('/admin/finance/invoices/'.$invoice->id.'/offline-receipt?'.http_build_query($query), [
        'amount' => 'bad', 'external_reference' => 'PRIVATE-U1-REFERENCE', 'evidence' => 'PRIVATE-U1-EVIDENCE',
    ])->assertRedirect($target)->assertSessionHasErrors()
        ->assertSessionMissing('_old_input.external_reference')->assertSessionMissing('_old_input.evidence');
    $this->assertDatabaseCount('offline_invoice_receipts', 0);
});

it('preserves instance source and pagination on explicit sync failure without repeating a remote request', function (): void {
    $actor = workspaceActor(['customers.view', 'upstream.view', 'upstream.sync']);
    $instance = Instance::factory()->active()->create();
    $query = Navigation::origin($actor, 'customer', $instance->user_id, [], 'customer-services') + ['tasks_page' => 2];
    $this->mock(SyncAdminInstanceAction::class)->shouldReceive('execute')->once()
        ->andThrow(ValidationException::withMessages(['sync' => 'U1 模拟失败']));
    $this->actingAs($actor)->post('/admin/upstream-instances/'.$instance->id.'/sync?'.http_build_query($query), [
        '_admin_form' => 'instance-sync', 'instance_id' => $instance->id,
    ])->assertRedirect(Navigation::url('instance', $instance->id, $query))->assertSessionHasErrors('sync');
});

it('keeps source and the other page selectors in invoice payment pagination', function (): void {
    $actor = workspaceActor();
    $invoice = Invoice::factory()->paid()->create();
    for ($i = 0; $i < 12; $i++) {
        PaymentAttempt::create(['invoice_id' => $invoice->id, 'gateway' => 'epay_wechat',
            'out_trade_no' => 'U1-PAGE-'.$i, 'amount' => '10.00', 'status' => 'created',
            'request_payload' => ['private' => 'PRIVATE-U1-ATTEMPT']]);
    }
    $source = Navigation::origin($actor, 'instance', 77, ['invoices_page' => 2], 'instance-invoices');
    $html = $this->actingAs($actor)->get(Navigation::url('invoice', $invoice->id, $source + ['callbacks_page' => 2]))
        ->assertOk()->assertDontSee('PRIVATE-U1-ATTEMPT')->getContent();
    $next = workspaceLink($html, '//main//a[contains(@href,"attempts_page=2")]');
    expect(workspaceQuery($next))->toEqual(['invoice_id' => $invoice->id, 'callbacks_page' => 2, 'attempts_page' => 2] + $source);
});

it('drops a forbidden source section while retaining the permitted customer source', function (): void {
    $actor = workspaceActor(['customers.view']);
    $source = ['from' => ['kind' => 'customer', 'id' => 9, 'section' => 'customer-invoices']];
    expect(Navigation::returnLink($actor, $source, '/admin/customers', '默认返回')['url'])->toBe('/admin/customers/9');
});

it('keeps legacy lifecycle and hot swap validation in the selected instance when entered from a workbench', function (string $suffix, string $permission, string $section): void {
    $actor = workspaceActor(['customers.view', 'upstream.view', $permission]);
    $instance = Instance::factory()->active()->create(['driver_type' => DriverType::ZJMF_API]);
    $query = Navigation::origin($actor, 'customer', $instance->user_id, ['services_page' => 2], 'customer-services') + ['user_id' => $instance->user_id];
    $page = Navigation::url('instances', null, $query + ['instance' => $instance->id], $section);
    $html = $this->actingAs($actor)->get($page)->assertOk()->getContent();
    $form = workspaceXpath($html)->query('//main//form[contains(@action,"/'.$suffix.'")]')->item(0);
    expect($form)->toBeInstanceOf(DOMElement::class);
    expect(workspaceQuery($form->getAttribute('action'))['from'])->toEqual($query['from']);
    $this->from('https://evil.test')->post($form->getAttribute('action'), ['ui_form' => 'instance-'.($suffix === 'control' ? 'control-' : 'hotswap-').$instance->id])
        ->assertRedirect($page)->assertSessionHasErrors();
})->with([
    'lifecycle' => ['control', 'upstream.lifecycle', 'instance-lifecycle'],
    'hot swap' => ['hot-swap', 'upstream.hotswap', 'instance-hot-swap'],
]);
