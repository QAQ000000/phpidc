<?php

declare(strict_types=1);

use App\Enums\DriverType;
use App\Enums\InstanceStatus;
use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\KycVerification;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AdminCustomerQueries;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

function customerCenterActor(string ...$permissions): User
{
    $actor = User::factory()->create();
    foreach ($permissions as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $actor;
}

function customerCenterXpath(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

    return new DOMXPath($document);
}

/** @return list<string> */
function customerCenterAllPermissions(): array
{
    return ['customers.view', 'finance.view', 'upstream.view', 'support.view', 'notification.view', 'audit.view', 'access.view'];
}

it('requires customer permission for both customer pages and the query service', function (string $kind): void {
    $owner = User::factory()->create();
    if ($kind === 'guest') {
        $this->get('/admin/customers')->assertRedirect('/admin/login');
        $this->get('/admin/customers/'.$owner->id)->assertRedirect('/admin/login');

        return;
    }
    $actor = customerCenterActor(...($kind === 'finance' ? ['finance.view'] : []));
    $this->actingAs($actor)->get('/admin/customers')->assertForbidden();
    $this->get('/admin/customers/'.$owner->id)->assertForbidden();
    expect(fn () => app(AdminCustomerQueries::class)->listing($actor, []))->toThrow(AuthorizationException::class);
    expect(fn () => app(AdminCustomerQueries::class)->detail($actor, $owner->id, []))->toThrow(AuthorizationException::class);
})->with(['guest', 'customer', 'finance']);

it('shows only customer identity and masked KYC to a customer-only viewer without domain queries', function (): void {
    $actor = customerCenterActor('customers.view');
    $owner = User::factory()->create(['name' => '只读客户', 'credit' => '187.63', 'affiliate_balance' => '294.52']);
    KycVerification::create(['user_id' => $owner->id, 'driver' => 'idcsmartali', 'external_id' => 'PRIVATE-CERTIFICATE', 'status' => 'pending',
        'masked_summary' => ['name' => '*明', 'id_last4' => '012X', 'credential' => 'PRIVATE-KYC-FIELD']]);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $response = $this->actingAs($actor)->get('/admin/customers/'.$owner->id.'?tab=identity')->assertOk()
        ->assertSee('只读客户')->assertSee('*明')->assertSee('012X')
        ->assertDontSee('PRIVATE-CERTIFICATE')->assertDontSee('PRIVATE-KYC-FIELD')->assertDontSee('187.63')->assertDontSee('294.52');
    foreach (['instances', 'invoices', 'financial_transactions', 'tickets', 'notification_logs', 'audit_logs', 'affiliate_receivable_settlements'] as $table) {
        expect(collect($queries)->contains(fn (string $sql): bool => preg_match('/\b'.preg_quote($table, '/').'\b/i', $sql) === 1))->toBeFalse();
    }
    $data = app(AdminCustomerQueries::class)->detail($actor, $owner->id, []);
    expect(array_keys($data['customer']->getAttributes()))->toEqualCanonicalizing([
        'id', 'name', 'email', 'phone', 'email_verified_at', 'kyc_verified_at', 'disabled_at', 'anonymized_at', 'deleted_at', 'created_at',
    ]);
    $xpath = customerCenterXpath($response->getContent());
    expect($xpath->query('//main//form[@method="post"]'))->toHaveCount(0)
        ->and($xpath->query('//main//*[@data-customer-metric]'))->toHaveCount(0)
        ->and($xpath->query('//main//section[@id="customer-services"]'))->toHaveCount(0);
    $this->assertDatabaseCount('fulfillment_tasks', 0);
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('notification_events', 0);
});

it('loads only the explicitly granted detail domain before rendering it', function (string $permission, string $table, string $key, string $tab): void {
    $actor = customerCenterActor('customers.view', $permission);
    $owner = User::factory()->create();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $data = app(AdminCustomerQueries::class)->detail($actor, $owner->id, ['tab' => $tab]);
    expect($data)->toHaveKey($key);
    foreach (['instances', 'invoices', 'financial_transactions', 'tickets', 'kyc_verifications', 'notification_logs', 'audit_logs'] as $domain) {
        $expected = $domain === $table;
        expect(collect($queries)->contains(fn (string $sql): bool => preg_match('/\b'.preg_quote($domain, '/').'\b/i', $sql) === 1))->toBe($expected);
    }
})->with([
    'invoices' => ['finance.view', 'invoices', 'invoices', 'invoices'],
    'ledger' => ['finance.view', 'financial_transactions', 'ledger', 'ledger'],
    'services' => ['upstream.view', 'instances', 'services', 'services'],
    'support' => ['support.view', 'tickets', 'tickets', 'tickets'],
    'identity' => ['customers.view', 'kyc_verifications', 'kycRecords', 'identity'],
    'notifications' => ['notification.view', 'notification_logs', 'notifications', 'notifications'],
    'audit' => ['audit.view', 'audit_logs', 'audits', 'audit'],
]);

it('does not load history collections for the customer overview even with every view permission', function (): void {
    $actor = customerCenterActor(...customerCenterAllPermissions());
    $owner = User::factory()->create(['credit' => '19.18', 'frozen_credit' => '2.00', 'wallet_clearance_frozen' => '3.00']);
    $actor->getAllPermissions();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $data = app(AdminCustomerQueries::class)->detail($actor, $owner->id, []);
    expect($data['currentTab'])->toBe('overview')->and($data['customer']->credit)->toBe('19.18')
        ->and($data['customer']->getAttributes())->toHaveKey('active_services_count');
    foreach (['services', 'invoices', 'ledger', 'tickets', 'kycRecords', 'notifications', 'audits'] as $key) {
        expect($data)->not->toHaveKey($key);
    }
    foreach (['invoices', 'financial_transactions', 'tickets', 'kyc_verifications', 'notification_logs', 'audit_logs'] as $table) {
        expect(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, '"'.$table.'"')))->toBeFalse();
    }
    expect(collect($queries)->filter(fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, '"instances"') && ! str_contains($sql, 'from "users"')))->toHaveCount(0);
});

it('checks a restricted customer tab before looking up even a nonexistent target', function (string $tab): void {
    $actor = customerCenterActor('customers.view');
    $actor->getAllPermissions();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    expect(fn () => app(AdminCustomerQueries::class)->detail($actor, 999999999, ['tab' => $tab]))->toThrow(AuthorizationException::class);
    foreach (['users', 'instances', 'invoices', 'financial_transactions', 'tickets', 'kyc_verifications', 'notification_logs', 'audit_logs'] as $table) {
        expect(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, '"'.$table.'"')))->toBeFalse();
    }
    $this->actingAs($actor)->get('/admin/customers/999999999?tab='.$tab)->assertForbidden();
})->with(['services', 'invoices', 'ledger', 'tickets', 'notifications', 'audit']);

it('rejects malformed customer tabs before querying a customer or history', function (array $input): void {
    $actor = customerCenterActor(...customerCenterAllPermissions());
    $actor->getAllPermissions();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    try {
        app(AdminCustomerQueries::class)->detail($actor, 999999999, $input);
        $this->fail('Invalid tab should stop the query.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(422);
    }
    foreach (['users', 'instances', 'invoices', 'financial_transactions', 'tickets', 'kyc_verifications', 'notification_logs', 'audit_logs'] as $table) {
        expect(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, '"'.$table.'"')))->toBeFalse();
    }
    $this->actingAs($actor)->get('/admin/customers/999999999?'.http_build_query($input))->assertStatus(422);
})->with(['unknown' => [['tab' => 'unknown']], 'array' => [['tab' => ['services']]], 'blank' => [['tab' => '']]]);

it('excludes wallet projections and service totals from every history tab', function (string $tab): void {
    $actor = customerCenterActor(...customerCenterAllPermissions());
    $owner = User::factory()->create(['credit' => '914.37']);
    $data = app(AdminCustomerQueries::class)->detail($actor, $owner->id, ['tab' => $tab]);
    expect(array_keys($data['customer']->getAttributes()))->toEqualCanonicalizing([
        'id', 'name', 'email', 'phone', 'email_verified_at', 'kyc_verified_at', 'disabled_at', 'anonymized_at', 'deleted_at', 'created_at',
    ]);
    $this->actingAs($actor)->get('/admin/customers/'.$owner->id.'?tab='.$tab)->assertOk()->assertDontSee('914.37');
})->with(['services', 'invoices', 'ledger', 'tickets', 'identity', 'notifications', 'audit']);

it('preserves the three wallet columns exactly without loading affiliate or receivable balances', function (): void {
    $actor = customerCenterActor('customers.view', 'finance.view');
    $owner = User::factory()->create(['credit' => '9999999999.91', 'frozen_credit' => '23.45', 'wallet_clearance_frozen' => '67.89', 'affiliate_balance' => '5432.10']);
    $listing = app(AdminCustomerQueries::class)->listing($actor, ['user_id' => $owner->id]);
    $customer = $listing['customers']->first();
    expect($customer->credit)->toBe('9999999999.91')->and($customer->frozen_credit)->toBe('23.45')->and($customer->wallet_clearance_frozen)->toBe('67.89')
        ->and($customer->getAttributes())->not->toHaveKey('affiliate_balance')->not->toHaveKey('affiliate_frozen_balance')->not->toHaveKey('receivable_revision');
    $response = $this->actingAs($actor)->get('/admin/customers/'.$owner->id)->assertOk()->assertSee('本页未执行应收核验')->assertDontSee('5432.10');
    $xpath = customerCenterXpath($response->getContent());
    foreach (['credit' => '9999999999.91', 'frozen_credit' => '23.45', 'wallet_clearance_frozen' => '67.89'] as $metric => $amount) {
        expect(trim($xpath->query('//*[@data-customer-metric="'.$metric.'"]/text()')->item(0)->textContent))->toBe($amount);
    }
});

it('counts Active and Suspended across every fulfillment driver while retaining historical service rows', function (): void {
    $actor = customerCenterActor('customers.view', 'upstream.view');
    $owner = User::factory()->create();
    foreach ([DriverType::ZJMF_API, DriverType::PROXMOX, DriverType::SUB2API, DriverType::REDEMPTION] as $driver) {
        foreach ([InstanceStatus::ACTIVE, InstanceStatus::SUSPENDED] as $status) {
            Instance::factory()->create(['user_id' => $owner->id, 'driver_type' => $driver, 'status' => $status]);
        }
    }
    foreach ([InstanceStatus::PENDING, InstanceStatus::PROVISION_FAILED, InstanceStatus::CANCELLED, InstanceStatus::DELETED, InstanceStatus::FRAUD] as $status) {
        Instance::factory()->create(['user_id' => $owner->id, 'status' => $status]);
    }
    Instance::factory()->active()->create();
    $data = app(AdminCustomerQueries::class)->detail($actor, $owner->id, []);
    expect((int) $data['customer']->active_services_count)->toBe(8)->and($data)->not->toHaveKey('services');
    $services = app(AdminCustomerQueries::class)->detail($actor, $owner->id, ['tab' => 'services'])['services'];
    expect($services->total())->toBe(13)->and($services->count())->toBe(10);
    $this->actingAs($actor)->get('/admin/customers?user_id='.$owner->id)->assertOk()->assertSee('data-customer-services="'.$owner->id.'">8', false);
});

it('matches complete identity filters and treats name wildcard characters literally', function (): void {
    $actor = customerCenterActor('customers.view');
    $first = User::factory()->create(['name' => '客户%_!样本', 'email' => 'exact@example.invalid', 'kyc_verified_at' => now()]);
    User::factory()->create(['name' => '客户ABC样本', 'email' => 'prefix-exact@example.invalid']);
    $queries = app(AdminCustomerQueries::class);
    expect($queries->listing($actor, ['name' => '%_!'])['customers']->pluck('id')->all())->toBe([$first->id])
        ->and($queries->listing($actor, ['email' => 'exact@example.invalid'])['customers']->pluck('id')->all())->toBe([$first->id])
        ->and($queries->listing($actor, ['user_id' => $first->id, 'kyc' => 'unverified'])['customers']->total())->toBe(0)
        ->and($queries->listing($actor, ['user_id' => $first->id, 'kyc' => 'verified'])['customers']->pluck('id')->all())->toBe([$first->id]);
});

it('keeps closed identities out of the default list and shows retained facts without a role-management entry', function (): void {
    $actor = customerCenterActor(...customerCenterAllPermissions());
    $owner = User::factory()->create(['name' => '已注销用户', 'email' => 'closed-test@deleted.invalid', 'anonymized_at' => now()]);
    $invoice = Invoice::factory()->paid()->create(['user_id' => $owner->id, 'invoice_no' => 'CLOSED-HISTORY']);
    $service = Instance::factory()->create(['user_id' => $owner->id, 'status' => InstanceStatus::DELETED]);
    $owner->delete();
    expect(app(AdminCustomerQueries::class)->listing($actor, ['user_id' => $owner->id])['customers']->total())->toBe(0)
        ->and(app(AdminCustomerQueries::class)->listing($actor, ['user_id' => $owner->id, 'status' => 'closed'])['customers']->pluck('id')->all())->toBe([$owner->id]);
    $response = $this->actingAs($actor)->get('/admin/customers/'.$owner->id.'?tab=invoices')->assertOk()->assertSee('已注销')->assertSee('CLOSED-HISTORY')
        ->assertSee('/admin/finance/invoice?invoice_id='.$invoice->id, false);
    $this->get('/admin/customers/'.$owner->id.'?tab=services')->assertOk()->assertSee('已注销')->assertSee('data-customer-service="'.$service->id.'"', false);
    expect(customerCenterXpath($response->getContent())->query('//main//a[@href="/admin/access?user_id='.$owner->id.'"]'))->toHaveCount(0);
});

it('rejects invalid customer list filters with an explicit HTTP error', function (array $input): void {
    $actor = customerCenterActor('customers.view');
    $this->actingAs($actor)->get('/admin/customers?'.http_build_query($input))->assertStatus(422);
})->with([
    'negative id' => [['user_id' => '-1']], 'array id' => [['user_id' => ['1']]],
    'partial email' => [['email' => 'not-an-email']], 'oversized name' => [['name' => str_repeat('a', 101)]],
    'bad kyc' => [['kyc' => 'maybe']], 'bad state' => [['status' => 'banned']],
    'zero page' => [['page' => '0']], 'overflow page' => [['page' => '2147483648']],
]);

it('rejects malformed detail ids and pagination before reading domain collections', function (): void {
    $owner = User::factory()->create();
    $this->actingAs(customerCenterActor('customers.view'))->get('/admin/customers/not-a-number')->assertNotFound();
    $this->get('/admin/customers/0')->assertNotFound();
    $this->get('/admin/customers/999999999')->assertNotFound();
    $this->get('/admin/customers/'.$owner->id.'?services_page=-1')->assertStatus(422);
    $this->get('/admin/customers/'.$owner->id.'?kyc_page%5B%5D=1')->assertStatus(422);
});

it('uses bounded independent pagination and never mixes another customers retained records', function (): void {
    $actor = customerCenterActor(...customerCenterAllPermissions());
    $owner = User::factory()->create();
    $other = User::factory()->create();
    foreach ([$owner, $other] as $customer) {
        for ($index = 0; $index < 12; $index++) {
            Instance::factory()->active()->create(['user_id' => $customer->id]);
            Invoice::factory()->create(['user_id' => $customer->id]);
            FinancialTransaction::create(['user_id' => $customer->id, 'type' => TransactionType::CREDIT_IN, 'amount' => '1.00', 'balance_after' => '1.00', 'frozen_after' => '0.00', 'reference_key' => 'customer-test:'.$customer->id.':'.$index]);
            Ticket::create(['user_id' => $customer->id, 'subject' => '客户工单 '.$index, 'status' => 'open']);
            KycVerification::create(['user_id' => $customer->id, 'driver' => 'idcsmartali', 'status' => 'failed', 'masked_summary' => ['name' => '*明', 'id_last4' => '012X']]);
            DB::table('notification_logs')->insert(['user_id' => $customer->id, 'event_key' => 'invoice.paid', 'channel' => 'email', 'target' => 'PRIVATE-RECIPIENT', 'status' => 'sent', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('audit_logs')->insert(['subject_type' => User::class, 'subject_id' => $customer->id, 'actor_type' => 'system', 'action' => 'identity.test.'.$index, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
    foreach (['services' => ['services', 'services_page'], 'invoices' => ['invoices', 'invoices_page'],
        'ledger' => ['ledger', 'ledger_page'], 'tickets' => ['tickets', 'tickets_page'],
        'identity' => ['kycRecords', 'kyc_page'], 'notifications' => ['notifications', 'notifications_page'], 'audit' => ['audits', 'audit_page']] as $tab => [$key, $page]) {
        $data = app(AdminCustomerQueries::class)->detail($actor, $owner->id, ['tab' => $tab, $page => 2]);
        expect($data[$key]->total())->toBe(12)->and($data[$key]->perPage())->toBe(10)
            ->and($data[$key]->currentPage())->toBe(2)->and($data[$key]->count())->toBe(2);
        expect($data[$key]->url(1))->toContain('tab='.$tab)->toContain($page.'=1');
        foreach (['services', 'invoices', 'ledger', 'tickets', 'kycRecords', 'notifications', 'audits'] as $otherKey) {
            if ($otherKey !== $key) {
                expect($data)->not->toHaveKey($otherKey);
            }
        }
    }
    $response = $this->actingAs($actor)->get('/admin/customers/'.$owner->id.'?services_page=2&kyc_page=2')->assertOk()->assertDontSee('PRIVATE-RECIPIENT');
    $xpath = customerCenterXpath($response->getContent());
    expect($xpath->query('//*[@data-customer-service]'))->toHaveCount(2)
        ->and($xpath->query('//*[@data-customer-invoice]'))->toHaveCount(0)
        ->and($xpath->query('//*[@data-customer-kyc]'))->toHaveCount(0);

});

it('projects safe service invoice notification and audit metadata with exact account subject matching', function (): void {
    $actor = customerCenterActor(...customerCenterAllPermissions());
    $owner = User::factory()->create(['name' => '<script>CLIENT-NAME</script>']);
    $classic = Instance::factory()->active()->create(['user_id' => $owner->id, 'driver_type' => DriverType::ZJMF_API]);
    $local = Instance::factory()->active()->create(['user_id' => $owner->id, 'driver_type' => DriverType::SUB2API]);
    DB::table('instances')->whereIn('id', [$classic->id, $local->id])->update(['encrypted_credentials' => 'BROKEN-CREDENTIAL-CIPHERTEXT', 'active_config' => json_encode(['secret' => 'PRIVATE-CONFIG'])]);
    Invoice::factory()->create(['user_id' => $owner->id, 'line_items' => ['purchase' => ['encrypted_request' => 'PRIVATE-ORDER-SNAPSHOT']]]);
    DB::table('notification_logs')->insert(['user_id' => $owner->id, 'event_key' => 'invoice.paid', 'channel' => 'email', 'target' => 'PRIVATE-TARGET', 'status' => 'failed', 'error_message' => 'PRIVATE-ERROR', 'created_at' => now(), 'updated_at' => now()]);
    $auditId = DB::table('audit_logs')->insertGetId(['subject_type' => User::class, 'subject_id' => $owner->id, 'actor_type' => 'system', 'action' => 'identity.allowed', 'after' => json_encode(['status' => 'verified', 'password' => 'PRIVATE-AUDIT-SECRET']), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('audit_logs')->insert(['subject_type' => Invoice::class, 'subject_id' => $owner->id, 'actor_type' => 'system', 'action' => 'invoice.WRONG-SUBJECT', 'created_at' => now(), 'updated_at' => now()]);
    $query = app(AdminCustomerQueries::class);
    $services = $query->detail($actor, $owner->id, ['tab' => 'services'])['services'];
    expect($services->first()->getAttributes())->not->toHaveKey('encrypted_credentials')->not->toHaveKey('active_config')->not->toHaveKey('custom_field_values')->not->toHaveKey('downstream_token');
    expect($services->first()->upstreamApi)->toBeNull();
    expect($query->detail($actor, $owner->id, ['tab' => 'invoices'])['invoices']->first()->getAttributes())->not->toHaveKey('line_items')->not->toHaveKey('gateway_trade_no');
    expect(get_object_vars($query->detail($actor, $owner->id, ['tab' => 'notifications'])['notifications']->first()))->not->toHaveKey('target')->not->toHaveKey('error_message');
    $audits = $query->detail($actor, $owner->id, ['tab' => 'audit'])['audits'];
    expect($audits->pluck('id')->all())->toBe([$auditId])->and($audits->first()->after)->toBe('{"status":"verified"}');
    $responses = [];
    foreach (['overview', 'services', 'invoices', 'ledger', 'tickets', 'identity', 'notifications', 'audit'] as $tab) {
        $responses[$tab] = $this->actingAs($actor)->get('/admin/customers/'.$owner->id.'?tab='.$tab)->assertOk();
        foreach (['BROKEN-CREDENTIAL-CIPHERTEXT', 'PRIVATE-CONFIG', 'PRIVATE-ORDER-SNAPSHOT', 'PRIVATE-TARGET', 'PRIVATE-ERROR', 'PRIVATE-AUDIT-SECRET', 'invoice.WRONG-SUBJECT'] as $secret) {
            $responses[$tab]->assertDontSee($secret);
        }
        $responses[$tab]->assertSee('&lt;script&gt;CLIENT-NAME&lt;/script&gt;', false)->assertDontSee('<script>CLIENT-NAME</script>', false);
        expect(customerCenterXpath($responses[$tab]->getContent())->query('//main//form[@method="post"]'))->toHaveCount(0);
    }
    $xpath = customerCenterXpath($responses['services']->getContent());
    foreach ([$classic, $local] as $service) {
        expect($xpath->query('//main//a[starts-with(@href,"/admin/instances/'.$service->id.'?")]'))->toHaveCount(1)
            ->and($xpath->query('//main//a[@href="/admin/upstream-instances?instance='.$service->id.'"]'))->toHaveCount(0);
    }
    expect($xpath->query('//main//section[@id="customer-services"]'))->toHaveCount(1);
    foreach (['invoices' => '/admin/finance?user_id=', 'ledger' => '/admin/finance/ledger?user_id=', 'services' => '/admin/upstream-instances?user_id=', 'tickets' => '/admin/support?user_id=', 'notifications' => '/admin/notifications?user_id=', 'overview' => '/admin/access?user_id='] as $tab => $path) {
        expect(customerCenterXpath($responses[$tab]->getContent())->query('//main//a[@href="'.$path.$owner->id.'"]'))->toHaveCount(1);
    }
    $auditUrl = '/admin/audit?'.http_build_query(['subject_type' => User::class, 'subject_id' => $owner->id]);
    expect(customerCenterXpath($responses['audit']->getContent())->query('//main//a[@href="'.$auditUrl.'"]'))->toHaveCount(1);

});

it('paginates twenty customers with constant query count and no per-customer business collection loads', function (): void {
    $actor = customerCenterActor('customers.view', 'upstream.view');
    User::factory()->count(25)->create();
    $actor->can('customers.view');
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $listing = app(AdminCustomerQueries::class)->listing($actor, []);
    expect($listing['customers']->total())->toBe(26)->and($listing['customers']->count())->toBe(20);
    $customerQueries = array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from "users"')));
    expect($customerQueries)->toHaveCount(2);
    expect(collect($queries)->contains(fn (string $sql): bool => preg_match('/^select .*from "instances"/i', $sql) === 1 && ! str_contains($sql, 'from "users"')))->toBeFalse();
    $page2 = app(AdminCustomerQueries::class)->listing($actor, ['page' => 2]);
    expect($page2['customers']->count())->toBe(6)->and($page2['customers']->currentPage())->toBe(2);
});

it('does not select wallet columns or run service counts for the customer-only list', function (): void {
    $actor = customerCenterActor('customers.view');
    $owner = User::factory()->create(['credit' => '321.09', 'frozen_credit' => '12.34', 'wallet_clearance_frozen' => '56.78']);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $listing = app(AdminCustomerQueries::class)->listing($actor, ['user_id' => $owner->id]);
    expect($listing['customers']->first()->getAttributes())->not->toHaveKey('credit')->not->toHaveKey('frozen_credit')->not->toHaveKey('wallet_clearance_frozen')->not->toHaveKey('active_services_count');
    foreach (['credit', 'frozen_credit', 'wallet_clearance_frozen', 'instances', 'password', 'remember_token'] as $column) {
        expect(collect($queries)->contains(fn (string $sql): bool => preg_match('/\b'.preg_quote($column, '/').'\b/i', $sql) === 1))->toBeFalse();
    }
    $this->actingAs($actor)->get('/admin/customers?user_id='.$owner->id)->assertOk()->assertDontSee('321.09')->assertDontSee('12.34')->assertDontSee('56.78');
});

it('keeps notification acceptance simulation and inactive statuses clear in Chinese', function (string $channel, string $status, string $label): void {
    $actor = customerCenterActor('customers.view', 'notification.view');
    $owner = User::factory()->create();
    DB::table('notification_logs')->insert(['user_id' => $owner->id, 'event_key' => 'invoice.paid', 'channel' => $channel, 'target' => 'PRIVATE-DELIVERY-TARGET', 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($actor)->get('/admin/customers/'.$owner->id.'?tab=notifications')->assertOk()->assertSee('邮件')->assertSee($label)
        ->assertSee('已受理表示通道接受请求，不代表最终送达')->assertDontSee('PRIVATE-DELIVERY-TARGET');
})->with([
    'legacy mail acceptance' => ['mail', 'sent', '已受理'],
    'simulation' => ['email', 'simulated', '模拟，未外发'],
    'skipped' => ['email', 'skipped', '已跳过'],
    'disabled' => ['email', 'disabled', '已停用'],
]);
