<?php

declare(strict_types=1);

use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\AffiliateWithdrawal;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\ReconciliationCase;
use App\Models\Ticket;
use App\Models\UpstreamApi;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
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

afterEach(function (): void {
    Http::assertNothingSent();
});

function dashboardActor(string ...$permissions): User
{
    $actor = User::factory()->create();
    foreach ($permissions as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $actor;
}

function dashboardXPath(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

    return new DOMXPath($document);
}

/** @return array<string, string> */
function dashboardMetrics(string $html): array
{
    $metrics = [];
    foreach (dashboardXPath($html)->query('//*[@data-dashboard-metric]') as $element) {
        $key = $element->getAttribute('data-dashboard-metric');
        expect(array_key_exists($key, $metrics))->toBeFalse();
        $metrics[$key] = trim($element->textContent);
    }

    return $metrics;
}

/** @return list<string> */
function dashboardViewPermissions(): array
{
    return ['finance.view', 'upstream.view', 'tasks.view', 'support.view'];
}

it('uses the dashboard as panel home and renders exact zero metrics for an admitted empty operation', function (): void {
    $response = $this->actingAs(dashboardActor(...dashboardViewPermissions()))->get('/admin')->assertOk()->assertSee('经营总览');
    expect(Filament::getPanel('admin')->getHomeUrl())->toBe('/admin')
        ->and(dashboardMetrics($response->getContent()))->toEqualCanonicalizing([
            'today_paid' => '0.00', 'month_paid' => '0.00', 'unpaid' => '0', 'pending_withdrawals' => '0',
            'wallet_withdrawals' => '0', 'payment_attention' => '0', 'active_instances' => '0',
            'due_instances' => '0', 'failed_tasks' => '0', 'open_tickets' => '0',
        ]);
    $xpath = dashboardXPath($response->getContent());
    expect($xpath->query('//nav[contains(concat(" ", normalize-space(@class), " "), " fi-sidebar-nav ")]'))->toHaveCount(1)
        ->and($xpath->query('//main//form[@method="post"]'))->toHaveCount(0);
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('fulfillment_tasks', 0);
    $this->assertDatabaseCount('jobs', 0);
});

it('keeps anonymous ordinary and closed users outside the dashboard', function (string $kind): void {
    if ($kind === 'guest') {
        $this->get('/admin')->assertRedirect('/admin/login');
    } else {
        $actor = $kind === 'customer' ? dashboardActor() : dashboardActor('finance.view');
        if ($kind === 'closed') {
            $actor->delete();
        }
        $this->actingAs($actor)->get('/admin')->assertForbidden();
    }
    $this->assertDatabaseCount('financial_transactions', 0);
})->with(['guest', 'customer', 'closed']);

it('does not query business metrics for a settings-only operator', function (): void {
    $actor = dashboardActor('settings.view');
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $response = $this->actingAs($actor)->get('/admin')->assertOk()->assertSee('经营总览');
    expect(dashboardMetrics($response->getContent()))->toBe([]);
    foreach (['invoices', 'affiliate_withdrawals', 'reconciliation_cases', 'instances', 'fulfillment_tasks', 'tickets'] as $table) {
        expect(collect($queries)->contains(fn (string $sql): bool => preg_match('/\b'.preg_quote($table, '/').'\b/i', $sql) === 1))->toBeFalse();
    }
    $xpath = dashboardXPath($response->getContent());
    expect($xpath->query('//main//a[@href="/admin/settings?tab=site"]'))->toHaveCount(1)
        ->and($xpath->query('//main//a[@href="/admin/finance"]'))->toHaveCount(0)
        ->and($xpath->query('//main//a[@href="/admin/upstreams"]'))->toHaveCount(0);
});

it('queries and renders only the metric domain granted to the operator', function (string $permission, array $keys, array $allowed): void {
    $actor = dashboardActor($permission);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $response = $this->actingAs($actor)->get('/admin')->assertOk();
    expect(array_keys(dashboardMetrics($response->getContent())))->toEqualCanonicalizing($keys);
    foreach (['invoices', 'affiliate_withdrawals', 'reconciliation_cases', 'instances', 'fulfillment_tasks', 'tickets'] as $table) {
        $queried = collect($queries)->contains(fn (string $sql): bool => preg_match('/\b'.preg_quote($table, '/').'\b/i', $sql) === 1);
        expect($queried)->toBe(in_array($table, $allowed, true));
    }
})->with([
    'finance only' => ['finance.view', ['today_paid', 'month_paid', 'unpaid', 'pending_withdrawals', 'wallet_withdrawals', 'payment_attention'], ['invoices', 'affiliate_withdrawals', 'reconciliation_cases']],
    'upstream only' => ['upstream.view', ['active_instances', 'due_instances'], ['instances']],
    'task only' => ['tasks.view', ['failed_tasks'], ['fulfillment_tasks']],
    'support only' => ['support.view', ['open_tickets'], ['tickets']],
]);

it('counts CNY product settlements once with exact large totals and the documented gross refund semantics', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00:00', date_default_timezone_get()));
    $owner = User::factory()->create();
    $base = ['user_id' => $owner->id, 'paid_at' => now(), 'currency' => 'CNY', 'status' => InvoiceStatus::PAID];
    foreach (['9999999999.99', '9999999999.99'] as $amount) {
        Invoice::factory()->create($base + ['total_amount' => $amount, 'gateway_amount' => $amount]);
    }
    Invoice::factory()->create($base + ['total_amount' => '0.10', 'credit_applied' => '0.10', 'gateway_amount' => '0.00']);
    Invoice::factory()->create(array_replace($base, ['type' => InvoiceType::UPGRADE, 'status' => InvoiceStatus::REFUNDED,
        'total_amount' => '0.20', 'gateway_amount' => '0.20']));
    Invoice::factory()->create(array_replace($base, ['type' => InvoiceType::RENEW, 'total_amount' => '3.00',
        'gateway_amount' => '3.00', 'paid_at' => '2026-11-01 00:00:00']));
    foreach ([
        ['type' => InvoiceType::WALLET_TOPUP], ['type' => InvoiceType::DOWNGRADE], ['status' => InvoiceStatus::UNPAID],
        ['status' => InvoiceStatus::CANCELLED], ['status' => InvoiceStatus::EXPIRED], ['currency' => 'USD'],
        ['paid_at' => null], ['paid_at' => '2026-11-20 12:00:01'], ['paid_at' => '2026-10-31 23:59:59'],
    ] as $excluded) {
        Invoice::factory()->create(array_replace($base, ['total_amount' => '500.00', 'gateway_amount' => '500.00'], $excluded));
    }
    $owner->delete();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $response = $this->actingAs(dashboardActor('finance.view'))->get('/admin')->assertOk()->assertSee('退款前');
    $metrics = dashboardMetrics($response->getContent());
    expect($metrics['today_paid'])->toBe('20000000000.28')
        ->and($metrics['month_paid'])->toBe('20000000003.28');
    foreach ($queries as $query) {
        expect(preg_match('/sum\s*\(\s*["`]?total_amount\b/i', $query))->toBe(0);
    }
});

it('uses the configured local day and month and excludes future settlement timestamps', function (): void {
    $originalTimezone = date_default_timezone_get();
    date_default_timezone_set('Asia/Shanghai');
    $this->beforeApplicationDestroyed(function () use ($originalTimezone): void {
        date_default_timezone_set($originalTimezone);
    });
    config(['app.timezone' => 'Asia/Shanghai']);
    $this->travelTo(CarbonImmutable::parse('2026-12-01 00:30:00', 'Asia/Shanghai'));
    foreach ([
        ['2026-11-30 23:59:59', '0.20'], ['2026-12-01 00:00:00', '0.10'],
        ['2026-12-01 00:30:00', '0.30'], ['2026-12-01 00:30:01', '0.40'],
    ] as [$paidAt, $amount]) {
        Invoice::factory()->paid()->create(['paid_at' => $paidAt, 'total_amount' => $amount, 'gateway_amount' => $amount]);
    }
    $response = $this->actingAs(dashboardActor('finance.view'))->get('/admin')->assertOk();
    $metrics = dashboardMetrics($response->getContent());
    expect($metrics['today_paid'])->toBe('0.40')->and($metrics['month_paid'])->toBe('0.40');
});

it('counts classic service states and the inclusive future seven day boundary without reading credentials', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00:00', 'UTC'));
    $account = UpstreamApi::factory()->create();
    foreach ([
        [InstanceStatus::ACTIVE, now()->addDay()], [InstanceStatus::ACTIVE, now()->addDays(7)],
        [InstanceStatus::ACTIVE, now()->subSecond()], [InstanceStatus::ACTIVE, now()->addDays(8)],
        [InstanceStatus::ACTIVE, null], [InstanceStatus::SUSPENDED, now()],
        [InstanceStatus::SUSPENDED, now()->addDay()], [InstanceStatus::SUSPENDED, now()->addDays(7)->addSecond()],
        [InstanceStatus::PENDING, now()->addDay()], [InstanceStatus::DELETED, now()->addDay()],
    ] as [$status, $dueAt]) {
        $instance = Instance::factory()->create(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id,
            'status' => $status, 'next_due_at' => $dueAt]);
        DB::table('instances')->where('id', $instance->id)->update(['encrypted_credentials' => 'PRIVATE-INVALID-CREDENTIAL-CIPHERTEXT']);
    }
    Instance::factory()->active()->create(['driver_type' => DriverType::REDEMPTION, 'next_due_at' => now()->addDay()]);
    $response = $this->actingAs(dashboardActor('upstream.view'))->get('/admin')->assertOk()->assertDontSee('PRIVATE-INVALID');
    $metrics = dashboardMetrics($response->getContent());
    expect($metrics['active_instances'])->toBe('5')->and($metrics['due_instances'])->toBe('4');
});

it('keeps wallet and external approvals separate and counts only unresolved payment cases', function (): void {
    $owner = User::factory()->create();
    foreach ([['wallet', 'pending'], ['wallet', 'pending'], ['external', 'pending'], ['external', 'approved'], ['wallet', 'approved']] as [$destination, $status]) {
        AffiliateWithdrawal::create(['user_id' => $owner->id, 'amount' => '1.00', 'destination' => $destination,
            'status' => $status, 'payout_name' => 'PRIVATE-PAYOUT-NAME']);
    }
    foreach ([['payment_attention', 'open'], ['payment_attention', 'awaiting_clearance'], ['payment_attention', 'resolved'], ['binding_orphan', 'open']] as [$kind, $status]) {
        ReconciliationCase::create(['user_id' => $owner->id, 'kind' => $kind, 'status' => $status, 'source_type' => 'dashboard-fixture',
            'source_id' => (string) Str::ulid(), 'source_fingerprint' => str_repeat('a', 64), 'initial_reason' => 'PRIVATE-CASE-REASON']);
    }
    Invoice::factory()->count(2)->create(['status' => InvoiceStatus::UNPAID]);
    Invoice::factory()->create(['status' => InvoiceStatus::CANCELLED]);
    $response = $this->actingAs(dashboardActor('finance.view'))->get('/admin')->assertOk()->assertDontSee('PRIVATE-PAYOUT')->assertDontSee('PRIVATE-CASE');
    $metrics = dashboardMetrics($response->getContent());
    expect($metrics['pending_withdrawals'])->toBe('1')->and($metrics['wallet_withdrawals'])->toBe('2')
        ->and($metrics['payment_attention'])->toBe('2')->and($metrics['unpaid'])->toBe('2');
});

it('shows only failed fulfillment tasks and never hydrates task payloads', function (): void {
    foreach (FulfillmentTaskStatus::cases() as $status) {
        FulfillmentTask::factory()->create(['status' => $status, 'payload' => ['private' => 'PRIVATE-DASHBOARD-TASK-PAYLOAD']]);
    }
    FulfillmentTask::factory()->create(['status' => FulfillmentTaskStatus::FAILED]);
    $response = $this->actingAs(dashboardActor('tasks.view'))->get('/admin')->assertOk()->assertDontSee('PRIVATE-DASHBOARD');
    expect(dashboardMetrics($response->getContent()))->toBe(['failed_tasks' => '2']);
    expect(dashboardXPath($response->getContent())->query('//main//a[@href="/admin/tasks?status=failed"]'))->not->toHaveCount(0);
});

it('projects the latest eight invoices in stable id order including a closed owners retained history', function (): void {
    $owner = User::factory()->create(['email' => 'private-dashboard-owner@example.invalid']);
    $invoices = collect();
    foreach (range(1, 11) as $number) {
        $invoices->push(Invoice::factory()->create(['user_id' => $owner->id, 'invoice_no' => 'DASH-INVOICE-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
            'line_items' => ['private' => 'PRIVATE-DASHBOARD-ORDER-SNAPSHOT'], 'created_at' => now()->subDays($number)]));
    }
    $owner->delete();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $response = $this->actingAs(dashboardActor('finance.view'))->get('/admin')->assertOk()
        ->assertDontSee('PRIVATE-DASHBOARD')->assertDontSee('private-dashboard-owner@example.invalid')
        ->assertSee('DASH-INVOICE-11')->assertDontSee('DASH-INVOICE-03');
    $xpath = dashboardXPath($response->getContent());
    $links = $xpath->query('//*[@aria-label="最近账单"]//tbody//a[contains(@href,"/admin/finance/invoice?")]');
    $urls = [];
    foreach ($links as $link) {
        $urls[] = $link->getAttribute('href');
    }
    expect($urls)->toBe($invoices->slice(3)->reverse()->map(fn (Invoice $invoice): string => '/admin/finance/invoice?invoice_id='.$invoice->id)->values()->all());
    foreach ($queries as $query) {
        if (preg_match('/\bfrom\s+["`]?invoices\b/i', $query) === 1) {
            expect($query)->not->toContain('line_items', 'upstream_snapshot');
        }
    }
});

it('shows the latest five open tickets with safe subjects and keeps closed replies outside the overview', function (): void {
    $owner = User::factory()->create();
    $tickets = collect();
    foreach (range(1, 7) as $number) {
        $tickets->push(Ticket::create(['user_id' => $owner->id, 'status' => 'open',
            'subject' => $number === 7 ? '<script>dashboardTicketUnsafe()</script>' : 'DASH-TICKET-'.$number]));
    }
    Ticket::create(['user_id' => $owner->id, 'subject' => 'PRIVATE-ANSWERED-TICKET', 'status' => 'answered']);
    Ticket::create(['user_id' => $owner->id, 'subject' => 'PRIVATE-CLOSED-TICKET', 'status' => 'closed']);
    DB::table('ticket_replies')->insert(['ticket_id' => $tickets->last()->id, 'user_id' => $owner->id,
        'is_admin' => false, 'body' => 'PRIVATE-TICKET-BODY', 'created_at' => now(), 'updated_at' => now()]);
    $response = $this->actingAs(dashboardActor('support.view'))->get('/admin')->assertOk()
        ->assertSee('<script>dashboardTicketUnsafe()</script>')->assertDontSee('<script>dashboardTicketUnsafe()</script>', false)
        ->assertDontSee('PRIVATE-')->assertDontSee('DASH-TICKET-2');
    expect(dashboardMetrics($response->getContent()))->toBe(['open_tickets' => '7']);
    $links = dashboardXPath($response->getContent())->query('//*[@aria-label="待回复工单"]//a[contains(@href,"/admin/support?edit=")]');
    $urls = [];
    foreach ($links as $link) {
        $urls[] = $link->getAttribute('href');
    }
    expect($urls)->toBe($tickets->slice(2)->reverse()->map(fn (Ticket $ticket): string => '/admin/support?edit='.$ticket->id)->values()->all());
});

it('distinguishes a read only catalog shortcut from the import capable entry using existing permissions', function (bool $importAllowed): void {
    $permissions = $importAllowed ? ['upstream.view', 'upstream.import'] : ['upstream.view'];
    $response = $this->actingAs(dashboardActor(...$permissions))->get('/admin')->assertOk();
    $xpath = dashboardXPath($response->getContent());
    $links = $xpath->query('//main//a[@href="/admin/upstreams"]');
    expect($links)->toHaveCount(1)
        ->and(trim($links->item(0)->textContent))->toBe($importAllowed ? '商品导入与连接' : '查看商品与连接');
})->with([false, true]);
