<?php

declare(strict_types=1);

use App\Enums\DriverType;
use App\Models\Instance;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Services\AdminInstanceQueries;
use App\Support\AdminWorkspaceNavigation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
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

function instanceTabsActor(string ...$abilities): User
{
    $actor = User::factory()->create();
    foreach ($abilities as $ability) {
        $actor->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
    $actor->getAllPermissions();

    return $actor;
}

function instanceTabsService(): Instance
{
    return Instance::factory()->active()->create(['driver_type' => DriverType::ZJMF_API,
        'upstream_api_id' => UpstreamApi::factory()->create()->id, 'upstream_instance_id' => 'U2-LOCAL-ONLY']);
}

it('reads no related history or option tables on an all-authority instance overview', function (): void {
    $instance = instanceTabsService();
    $actor = instanceTabsActor('upstream.view', 'customers.view', 'finance.view', 'tasks.view', 'support.view');
    DB::enableQueryLog();
    DB::flushQueryLog();
    $data = app(AdminInstanceQueries::class)->detail($actor, $instance->id, ['tab' => 'overview']);
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();
    foreach (['invoices', 'fulfillment_tasks', 'tickets', 'product_option_groups', 'product_option_items'] as $table) {
        expect(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from "'.$table.'"')))->toBeEmpty();
    }
    expect($data['currentTab'])->toBe('overview')->and($data['configuration'])->toBe([])
        ->and($data['invoices'])->toBeNull()->and($data['tasks'])->toBeNull()->and($data['tickets'])->toBeNull();
});

it('authorizes an instance history tab before querying any business row', function (string $tab): void {
    $actor = instanceTabsActor('upstream.view');
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        app(AdminInstanceQueries::class)->detail($actor, 987654321, ['tab' => $tab]);
        test()->fail('Unauthorized instance tab was accepted.');
    } catch (AuthorizationException) {
        foreach (['instances', 'invoices', 'fulfillment_tasks', 'tickets'] as $table) {
            expect(array_filter(DB::getQueryLog(), fn (array $row): bool => str_contains($row['query'], 'from "'.$table.'"')))->toBeEmpty();
        }
    } finally {
        DB::disableQueryLog();
    }
    $this->actingAs($actor)->get('/admin/instances/987654321?tab='.$tab)->assertForbidden();
})->with(['invoices', 'tasks', 'tickets']);

it('rejects invalid instance tabs before selecting an instance', function (mixed $tab): void {
    $actor = instanceTabsActor('upstream.view');
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        app(AdminInstanceQueries::class)->detail($actor, 987654321, ['tab' => $tab]);
        test()->fail('Malformed instance tab was accepted.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(422)
            ->and(array_filter(DB::getQueryLog(), fn (array $row): bool => str_contains($row['query'], 'from "instances"')))->toBeEmpty();
    } finally {
        DB::disableQueryLog();
    }
})->with([['unknown'], [''], [['overview']], [1], [null]]);

it('keeps the canonical tab and only its page when navigating to related records', function (): void {
    $instance = instanceTabsService();
    $actor = instanceTabsActor('upstream.view', 'tasks.view', 'finance.view', 'customers.view');
    $query = ['tab' => 'tasks', 'tasks_page' => 2, 'invoices_page' => 9];
    $origin = AdminWorkspaceNavigation::origin($actor, 'instance', $instance->id, $query, 'instance-tasks');
    $return = AdminWorkspaceNavigation::returnLink($actor, $origin, '/invalid', 'invalid');
    parse_str(parse_url($return['url'], PHP_URL_QUERY), $parsed);
    expect($parsed)->toBe(['tasks_page' => '2', 'tab' => 'tasks'])
        ->and($return['url'])->toEndWith('#instance-tasks');
    $this->actingAs($actor)->get('/admin/instances/'.$instance->id.'?'.http_build_query($query))->assertOk()
        ->assertSee('aria-current="page"', false)->assertDontSee('invoices_page=9', false);
});

it('redirects the old selected detail through a safe immediate list source and checks customer scope', function (): void {
    $instance = instanceTabsService();
    $other = User::factory()->create();
    $actor = instanceTabsActor('upstream.view');
    $filters = ['q' => 'U2', 'user_id' => $instance->user_id, 'page' => 2];
    $this->actingAs($actor)->get('/admin/upstream-instances?'.http_build_query($filters + [
        'instance' => $instance->id, 'return_url' => 'https://example.invalid/private',
        'from' => ['kind' => 'unknown', 'query' => []],
    ]))->assertRedirect(AdminWorkspaceNavigation::url('instance', $instance->id,
        AdminWorkspaceNavigation::origin($actor, 'instances', null, $filters)));
    $this->get('/admin/upstream-instances?instance='.$instance->id.'&user_id='.$other->id)->assertNotFound();
    $this->get('/admin/upstream-instances?instance='.$instance->id.'&user_id[]=1')->assertUnprocessable();
});

it('retains legacy validation input across the old GET redirect without reviving confirmations', function (): void {
    $instance = instanceTabsService();
    $actor = instanceTabsActor('upstream.view', 'upstream.lifecycle');
    $old = '/admin/upstream-instances?instance='.$instance->id;
    $this->actingAs($actor)->from($old)->post('/admin/upstream-instances/'.$instance->id.'/control', [
        'ui_form' => 'instance-control-'.$instance->id, 'request_id' => (string) Str::ulid(),
        'action' => 'terminate', 'reason' => 'U2 retained legacy reason',
    ])->assertRedirect($old)->assertSessionHasErrors('confirm');
    $redirect = $this->get($old)->assertRedirect();
    $html = $this->get($redirect->headers->get('Location'))->assertOk()->assertSee('U2 retained legacy reason')
        ->assertDontSee('name="confirm" value="1" checked', false)->getContent();
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
    $xpath = new DOMXPath($document);
    expect($xpath->query('//dialog[@id="instance-lifecycle"][starts-with(@x-init,"$nextTick")]'))->toHaveCount(1)
        ->and($xpath->query('//input[@name="confirm"][@checked]'))->toHaveCount(0);
});

it('returns canonical lifecycle and swap validation failures to their matching dialog', function (string $operation, string $section): void {
    $instance = instanceTabsService();
    $actor = instanceTabsActor('upstream.view', 'upstream.lifecycle', 'upstream.hotswap');
    $query = ['tab' => 'overview'] + AdminWorkspaceNavigation::origin($actor, 'instances', null, ['q' => 'U2']);
    $this->actingAs($actor)->from('/irrelevant')->post('/admin/upstream-instances/'.$instance->id.'/'.$operation.'?'.http_build_query($query), [
        'ui_form' => 'instance-'.($operation === 'control' ? 'control' : 'hotswap').'-'.$instance->id,
    ])->assertRedirect(AdminWorkspaceNavigation::url('instance', $instance->id, $query, $section))->assertSessionHasErrors();
    $this->assertDatabaseCount('fulfillment_tasks', 0);
    $this->assertDatabaseCount('instance_upstream_bindings', 0);
})->with([['control', 'instance-lifecycle'], ['hot-swap', 'instance-hot-swap']]);
