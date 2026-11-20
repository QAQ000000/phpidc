<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Enums\DriverType;
use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\PaymentCallback;
use App\Models\User;
use App\Support\AdminRoles;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Http::preventStrayRequests();
    Queue::fake();
});

afterEach(function (): void {
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

function c1IntegrationActor(string ...$roles): User
{
    $actor = User::factory()->create();
    foreach ($roles as $role) {
        app(AssignAdminRoleAction::class)->execute($actor, $role, 'C1 integration acceptance');
    }

    return $actor;
}

function c1IntegrationXPath(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

    return new DOMXPath($document);
}

it('admits the fixed customer viewer without adding unrelated permissions or changing existing users', function (): void {
    $existing = c1IntegrationActor('finance-viewer');
    $viewer = c1IntegrationActor('customer-viewer');
    expect(AdminRoles::permissions()['customer-viewer'])->toBe(['customers.view'])
        ->and(AdminRoles::webAssignable())->toContain('customer-viewer')
        ->and(AdminRoles::labels()['customer-viewer'])->toBe('客户资料查看')
        ->and($viewer->canAccessPanel(Filament::getPanel('admin')))->toBeTrue()
        ->and($viewer->getAllPermissions()->pluck('name')->all())->toBe(['customers.view'])
        ->and($existing->fresh()->can('customers.view'))->toBeFalse();
    foreach (['finance_readonly', 'all_business'] as $preset) {
        expect(AdminRoles::presets()[$preset]['roles'])->toContain('customer-viewer');
    }
    expect(AdminRoles::presets()['operations']['roles'])->toContain('customer-operator');
    $this->actingAs($viewer)->get('/admin/customers')->assertOk();
    foreach (['/admin/finance', '/admin/upstream-instances', '/admin/audit', '/admin/access'] as $path) {
        $this->get($path)->assertForbidden();
    }
    $viewer->delete();
    expect($viewer->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
});

it('keeps the customer management menu scoped and renders one global search control', function (): void {
    $viewer = c1IntegrationActor('customer-viewer');
    $response = $this->actingAs($viewer)->get('/admin/customers')->assertOk();
    $xpath = c1IntegrationXPath($response->getContent());
    expect($xpath->query('//*[@data-admin-search]'))->toHaveCount(1);
    $links = $xpath->query('//nav[contains(concat(" ",normalize-space(@class)," ")," fi-sidebar-nav ")]//a[@href]');
    $urls = [];
    foreach ($links as $link) {
        $urls[] = $link->getAttribute('href');
    }
    expect(array_values(array_unique($urls)))->toBe(['/admin', '/admin/customers']);
    $this->actingAs(c1IntegrationActor('audit-viewer'));
    $xpath = c1IntegrationXPath($this->get('/admin/audit')->assertOk()->getContent());
    expect($xpath->query('//*[@data-admin-search]'))->toHaveCount(0);
});

it('filters classic services by customer and keeps that scope through details and return links', function (): void {
    $owner = User::factory()->create();
    $owned = Instance::factory()->active()->create(['driver_type' => DriverType::ZJMF_API, 'user_id' => $owner->id, 'display_name' => 'C1-owned-classic']);
    $other = Instance::factory()->active()->create(['driver_type' => DriverType::ZJMF_API, 'display_name' => 'C1-unrelated-classic']);
    Instance::factory()->active()->create(['user_id' => $owner->id, 'display_name' => 'C1-owned-local-card']);
    $this->actingAs(c1IntegrationActor('upstream-viewer'));
    $path = '/admin/upstream-instances?user_id='.$owner->id;
    $response = $this->get($path)->assertOk()->assertSee('C1-owned-classic')->assertDontSee('C1-unrelated-classic')->assertDontSee('C1-owned-local-card');
    $xpath = c1IntegrationXPath($response->getContent());
    $detailLink = $xpath->query('//main//a[contains(@href,"instance='.$owned->id.'")]')->item(0);
    expect($detailLink)->toBeInstanceOf(DOMElement::class)
        ->and($detailLink->getAttribute('href'))->toContain('user_id='.$owner->id);
    $this->get($detailLink->getAttribute('href'))->assertOk()->assertSee('href="'.$path.'"', false);
    $this->get($path.'&instance='.$other->id)->assertNotFound();
});

it('preserves customer filters on classic service pagination', function (): void {
    $owner = User::factory()->create();
    Instance::factory()->count(21)->create(['driver_type' => DriverType::ZJMF_API, 'user_id' => $owner->id]);
    $path = '/admin/upstream-instances?user_id='.$owner->id;
    $xpath = c1IntegrationXPath($this->actingAs(c1IntegrationActor('upstream-viewer'))->get($path)->assertOk()->getContent());
    $next = $xpath->query('//main//a[contains(@href,"page=2")]')->item(0);
    expect($next)->toBeInstanceOf(DOMElement::class)
        ->and($next->getAttribute('href'))->toContain('user_id='.$owner->id);
    $this->get($next->getAttribute('href'))->assertOk();
});

it('scopes both ledger entries and callbacks through the selected customer invoice ownership', function (): void {
    $owner = User::factory()->create();
    $owned = Invoice::factory()->create(['user_id' => $owner->id]);
    $other = Invoice::factory()->create();
    foreach ([[$owned, 'C1-owned-reference', 'C1-owned-trade'], [$other, 'C1-other-reference', 'C1-other-trade']] as [$invoice, $reference, $trade]) {
        FinancialTransaction::create(['user_id' => $invoice->user_id, 'invoice_id' => $invoice->id,
            'type' => TransactionType::CREDIT_IN, 'amount' => '3.00', 'reference_key' => $reference]);
        PaymentCallback::factory()->create(['invoice_id' => $invoice->id, 'invoice_no' => $invoice->invoice_no,
            'gateway' => 'epay_alipay', 'gateway_trade_no' => $trade, 'raw_payload' => ['secret' => 'C1-private-callback']]);
    }
    PaymentCallback::factory()->create(['invoice_id' => null, 'invoice_no' => $owned->invoice_no,
        'gateway' => 'epay_alipay', 'gateway_trade_no' => 'C1-unassigned-trade', 'outcome' => 'unknown_invoice']);
    $this->actingAs(c1IntegrationActor('finance-viewer'));
    $this->get('/admin/finance/ledger?user_id='.$owner->id)->assertOk()
        ->assertSee('C1-owned-reference')->assertSee('C1-owned-trade')->assertSee('客户账单的支付回调')
        ->assertDontSee('C1-other-reference')->assertDontSee('C1-other-trade')->assertDontSee('C1-unassigned-trade')
        ->assertDontSee('C1-private-callback')->assertDontSee('客户 ID 筛选仅适用于上方流水');
    $this->get('/admin/finance/ledger?user_id='.$owner->id.'&invoice_id='.$other->id)->assertOk()
        ->assertDontSee('C1-owned-reference')->assertDontSee('C1-other-reference')->assertDontSee('C1-owned-trade')->assertDontSee('C1-other-trade');
    $this->get('/admin/finance/ledger')->assertOk()->assertSee('C1-unassigned-trade');
});

it('preserves customer and enum filters across both financial paginators', function (): void {
    $owner = User::factory()->create();
    $invoice = Invoice::factory()->create(['user_id' => $owner->id]);
    for ($index = 0; $index < 21; $index++) {
        FinancialTransaction::create(['user_id' => $owner->id, 'invoice_id' => $invoice->id, 'type' => TransactionType::CREDIT_IN,
            'amount' => '1.00', 'reference_key' => 'c1-pagination-'.$index]);
        PaymentCallback::factory()->create(['invoice_id' => $invoice->id, 'invoice_no' => $invoice->invoice_no,
            'gateway' => 'epay_alipay', 'outcome' => 'rejected_short_amount']);
    }
    $path = '/admin/finance/ledger?'.http_build_query(['user_id' => $owner->id, 'type' => 'credit_in', 'outcome' => 'rejected_short_amount']);
    $xpath = c1IntegrationXPath($this->actingAs(c1IntegrationActor('finance-viewer'))->get($path)->assertOk()->getContent());
    foreach (['ledger_page', 'callbacks_page'] as $page) {
        $next = $xpath->query('//main//a[contains(@href,"'.$page.'=2")]')->item(0);
        expect($next)->toBeInstanceOf(DOMElement::class);
        parse_str((string) parse_url($next->getAttribute('href'), PHP_URL_QUERY), $query);
        expect($query)->toMatchArray(['user_id' => (string) $owner->id, 'type' => 'credit_in', 'outcome' => 'rejected_short_amount', $page => '2']);
        $this->get($next->getAttribute('href'))->assertOk();
    }
});

it('keeps account audits separate from another model with the same numeric id and preserves exact filters', function (): void {
    $owner = User::factory()->create();
    for ($index = 0; $index < 21; $index++) {
        DB::table('audit_logs')->insert(['actor_type' => 'system', 'action' => 'c1.account.changed', 'subject_type' => User::class,
            'subject_id' => $owner->id, 'reason' => 'C1-account-audit-'.$index,
            'before' => json_encode(['status' => 'active', 'secret' => 'C1-audit-private']), 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('audit_logs')->insert(['actor_type' => 'system', 'action' => 'c1.account.changed', 'subject_type' => Invoice::class,
        'subject_id' => $owner->id, 'reason' => 'C1-other-model-audit', 'created_at' => now(), 'updated_at' => now()]);
    $path = '/admin/audit?'.http_build_query(['subject_type' => User::class, 'subject_id' => $owner->id, 'action' => 'c1.account.changed']);
    $response = $this->actingAs(c1IntegrationActor('audit-viewer'))->get($path)->assertOk()
        ->assertSee('C1-account-audit-20')->assertDontSee('C1-other-model-audit')->assertDontSee('C1-audit-private');
    $xpath = c1IntegrationXPath($response->getContent());
    expect($xpath->query('//main//select[@name="subject_type"]/option[@selected]')->item(0)->getAttribute('value'))->toBe(User::class);
    $next = $xpath->query('//main//a[contains(@href,"page=2")]')->item(0);
    expect($next)->toBeInstanceOf(DOMElement::class);
    parse_str((string) parse_url($next->getAttribute('href'), PHP_URL_QUERY), $query);
    expect($query)->toMatchArray(['subject_type' => User::class, 'subject_id' => (string) $owner->id, 'action' => 'c1.account.changed']);
    $this->get($next->getAttribute('href'))->assertOk()->assertSee('C1-account-audit-0')->assertDontSee('C1-other-model-audit');
});

it('rejects malformed customer-linked filters before loading each old list', function (string $role, string $path): void {
    $this->actingAs(c1IntegrationActor($role))->get($path)->assertStatus(422);
})->with([
    ['upstream-viewer', '/admin/upstream-instances?user_id[]=1'],
    ['upstream-viewer', '/admin/upstream-instances?user_id=-1'],
    ['finance-viewer', '/admin/finance/ledger?user_id[]=1'],
    ['finance-viewer', '/admin/finance/ledger?user_id=0'],
    ['finance-viewer', '/admin/finance/ledger?type=not-a-transaction'],
    ['finance-viewer', '/admin/finance/ledger?callbacks_page=-1'],
    ['audit-viewer', '/admin/audit?subject_type[]=User'],
    ['audit-viewer', '/admin/audit?subject_type='.str_repeat('x', 256)],
    ['audit-viewer', '/admin/audit?subject_id=0'],
]);
