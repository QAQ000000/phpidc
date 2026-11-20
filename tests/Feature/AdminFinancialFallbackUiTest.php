<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Actions\RecordOfflineInvoiceReceiptAction;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\TransactionType;
use App\Filament\Pages\FinanceInvoice;
use App\Models\FinancialTransaction;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\OfflineInvoiceReceipt;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Services\AdminCustomerQueries;
use App\Support\AdminRoles;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
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

function financialFallbackUiActor(string ...$permissions): User
{
    $actor = User::factory()->create();
    foreach ($permissions as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $actor;
}

function financialFallbackUiXpath(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

    return new DOMXPath($document);
}

function financialFallbackUiInvoice(?User $owner = null): Invoice
{
    $owner ??= User::factory()->create();

    return Invoice::factory()->create(['user_id' => $owner->id, 'gateway' => 'epay_wechat', 'line_items' => [
        'items' => [['kind' => 'product', 'label' => 'C3 固定商品', 'qty' => 1, 'unit_price' => '10.00', 'amount' => '10.00']],
        'purchase' => ['encrypted_params' => Crypt::encryptString('{}')],
    ]]);
}

function financialFallbackUiOfflineInput(): array
{
    return ['request_id' => (string) Str::ulid(), 'amount' => '10.00', 'currency' => 'CNY', 'external_channel' => 'bank_transfer',
        'external_reference' => 'UI-BANK-'.Str::ulid(), 'received_at' => now()->subMinute()->format('Y-m-d\TH:i'),
        'evidence' => 'PRIVATE-C3-RECEIPT-EVIDENCE', 'confirm' => '1'];
}

function financialFallbackUiData(Invoice $invoice, string $query = ''): array
{
    $request = Request::create('/admin/finance/invoice?invoice_id='.$invoice->id.($query === '' ? '' : '&'.$query));
    app()->instance('request', $request);
    app()->instance('originalRequest', $request);
    $page = app(FinanceInvoice::class);
    $page->invoiceId = $invoice->id;

    return (new ReflectionMethod($page, 'getViewData'))->invoke($page);
}

it('adds exactly two financial roles without changing operations or readonly presets', function (): void {
    expect(AdminRoles::permissions()['finance-wallet-adjuster'])->toBe(['finance.view', 'finance.wallet-adjust'])
        ->and(AdminRoles::permissions()['finance-offline-receipt'])->toBe(['finance.view', 'finance.offline-receipt'])
        ->and(AdminRoles::permissions()['finance-operator'])->toBe(['finance.view', 'finance.refund'])
        ->and(AdminRoles::presets()['all_business']['roles'])->toContain('finance-wallet-adjuster', 'finance-offline-receipt');
    foreach (['operations', 'finance_readonly'] as $preset) {
        expect(AdminRoles::presets()[$preset]['roles'])->not->toContain('finance-wallet-adjuster', 'finance-offline-receipt');
    }
    $actor = User::factory()->create();
    app(AssignAdminRoleAction::class)->execute($actor, 'finance-wallet-adjuster', 'Isolated C3 role');
    expect($actor->fresh()->getAllPermissions()->pluck('name')->all())->toEqualCanonicalizing(['finance.view', 'finance.wallet-adjust'])
        ->and($actor->can('customers.view'))->toBeFalse()->and($actor->can('finance.refund'))->toBeFalse();
});

it('shows a separate wallet dialog only with customer finance and wallet authority', function (array $permissions, bool $allowed): void {
    $actor = financialFallbackUiActor(...$permissions);
    $owner = User::factory()->create(['credit' => '17.25']);
    $response = $this->actingAs($actor)->get('/admin/customers/'.$owner->id)->assertOk();
    $data = app(AdminCustomerQueries::class)->detail($actor, $owner->id, []);
    expect($data['walletAdjustment'])->toBe($allowed)->and($data['management'])->toBe(['profile' => false, 'password_reset' => false, 'access' => false]);
    $xpath = financialFallbackUiXpath($response->getContent());
    $form = '//dialog[@id="customer-wallet-adjust"]//form';
    expect($xpath->query($form))->toHaveCount($allowed ? 1 : 0);
    if ($allowed) {
        expect($xpath->query($form.'//input[@name="request_id"]')->item(0))->toBeInstanceOf(DOMElement::class);
        $requestId = $xpath->query($form.'//input[@name="request_id"]')->item(0)->getAttribute('value');
        expect(Str::isUlid($requestId))->toBeTrue()
            ->and($xpath->query($form.'//input[@name="reason"][@maxlength="255"][not(@required)]'))->toHaveCount(1)
            ->and($xpath->query($form.'//input[@name="confirm"][@required][not(@checked)]'))->toHaveCount(1)
            ->and($xpath->query($form.'//input[@name="currency"][@value="CNY"]'))->toHaveCount(1)
            ->and($xpath->query($form.'//input[@name="customer_id"][@value="'.$owner->id.'"]'))->toHaveCount(1);
    }
})->with([
    'customer viewer' => [['customers.view'], false],
    'financial viewer' => [['customers.view', 'finance.view'], false],
    'wallet permission without finance view' => [['customers.view', 'finance.wallet-adjust'], false],
    'fully authorized' => [['customers.view', 'finance.view', 'finance.wallet-adjust'], true],
]);

it('hides financial wallet mutations for protected identities and allows disabled ordinary customers', function (string $kind, bool $allowed): void {
    $actor = financialFallbackUiActor('customers.view', 'finance.view', 'finance.wallet-adjust');
    $owner = $kind === 'self' ? $actor : User::factory()->create();
    if ($kind === 'role') {
        app(AssignAdminRoleAction::class)->execute($owner, 'finance-viewer', 'Protected C3 account');
    } elseif ($kind === 'permission') {
        $owner->givePermissionTo(Permission::findOrCreate('support.view', 'web'));
    } elseif ($kind === 'closed') {
        $owner->forceFill(['anonymized_at' => now(), 'deleted_at' => now()])->save();
    } elseif ($kind === 'disabled') {
        $owner->forceFill(['disabled_at' => now()])->save();
    }
    $response = $this->actingAs($actor)->get('/admin/customers/'.$owner->id)->assertOk();
    expect(financialFallbackUiXpath($response->getContent())->query('//form[contains(@action,"/wallet-adjust")]'))->toHaveCount($allowed ? 1 : 0);
})->with(['self' => ['self', false], 'role' => ['role', false], 'permission' => ['permission', false], 'closed' => ['closed', false], 'disabled' => ['disabled', true]]);

it('submits a wallet adjustment with optional note and creates a fresh request only after success', function (): void {
    $actor = financialFallbackUiActor('customers.view', 'finance.view', 'finance.wallet-adjust');
    $owner = User::factory()->create(['credit' => '5.00', 'frozen_credit' => '3.00', 'wallet_clearance_frozen' => '2.00']);
    $path = '/admin/customers/'.$owner->id;
    $response = $this->actingAs($actor)->get($path)->assertOk();
    $xpath = financialFallbackUiXpath($response->getContent());
    $requestId = $xpath->query('//dialog[@id="customer-wallet-adjust"]//input[@name="request_id"]')->item(0)->getAttribute('value');
    $this->post($path.'/wallet-adjust', ['request_id' => $requestId, 'direction' => 'credit', 'amount' => '1.23', 'currency' => 'CNY', 'confirm' => '1'])
        ->assertRedirect($path.'?tab=overview#customer-profile')->assertSessionHas('customer_status');
    expect($owner->fresh()->credit)->toBe('6.23')->and($owner->fresh()->frozen_credit)->toBe('3.00')
        ->and($owner->fresh()->wallet_clearance_frozen)->toBe('2.00');
    $response = $this->get($path)->assertOk()->assertSee('6.23');
    $this->get($path.'?tab=ledger')->assertOk()->assertSee('钱包加款')->assertSee('1.23');
    $newId = financialFallbackUiXpath($response->getContent())->query('//dialog[@id="customer-wallet-adjust"]//input[@name="request_id"]')->item(0)->getAttribute('value');
    expect(Str::isUlid($newId))->toBeTrue()->and($newId)->not->toBe($requestId);
    $this->get('/admin/finance/ledger?user_id='.$owner->id)->assertOk()->assertSee('钱包加款');
});

it('restores only matching failed wallet input and keeps a valid request id', function (): void {
    $actor = financialFallbackUiActor('customers.view', 'finance.view', 'finance.wallet-adjust');
    $owner = User::factory()->create(['credit' => '1.00']);
    $other = User::factory()->create();
    $requestId = (string) Str::ulid();
    $path = '/admin/customers/'.$owner->id;
    $this->actingAs($actor)->from($path)->post($path.'/wallet-adjust', ['request_id' => $requestId, 'direction' => 'debit',
        'amount' => '90.12', 'currency' => 'CNY', 'reason' => 'C3-RESTORE-NOTE', 'confirm' => '1'])
        ->assertRedirect($path)->assertSessionHasErrors('amount');
    $response = $this->get($path)->assertOk()->assertSee('C3-RESTORE-NOTE');
    $xpath = financialFallbackUiXpath($response->getContent());
    expect($xpath->query('//dialog[@id="customer-wallet-adjust"]')->item(0)->getAttribute('x-init'))->toBe('$nextTick(() => $el.showModal())')
        ->and($xpath->query('//dialog[@id="customer-wallet-adjust"]//input[@name="request_id"][@value="'.$requestId.'"]'))->toHaveCount(1)
        ->and($xpath->query('//dialog[@id="customer-wallet-adjust"]//input[@name="amount"][@value="90.12"]'))->toHaveCount(1)
        ->and($xpath->query('//dialog[@id="customer-wallet-adjust"]//option[@value="debit"][@selected]'))->toHaveCount(1);
    $this->get('/admin/customers/'.$other->id)->assertOk()->assertDontSee('C3-RESTORE-NOTE')->assertDontSee('value="90.12"', false);
    expect($owner->fresh()->credit)->toBe('1.00');
    $this->assertDatabaseCount('financial_transactions', 0);
});

it('regenerates malformed wallet request ids and renders array failures without exposing them', function (): void {
    $actor = financialFallbackUiActor('customers.view', 'finance.view', 'finance.wallet-adjust');
    $owner = User::factory()->create();
    $path = '/admin/customers/'.$owner->id;
    $this->actingAs($actor)->from($path)->post($path.'/wallet-adjust', ['request_id' => ['PRIVATE-C3-ARRAY'],
        'direction' => ['PRIVATE-C3-ARRAY'], 'amount' => ['PRIVATE-C3-ARRAY'], 'reason' => ['PRIVATE-C3-ARRAY'], 'confirm' => '1'])
        ->assertRedirect($path)->assertSessionHasErrors(['request_id', 'direction', 'amount', 'reason']);
    $response = $this->get($path)->assertOk()->assertDontSee('PRIVATE-C3-ARRAY');
    $id = financialFallbackUiXpath($response->getContent())->query('//dialog[@id="customer-wallet-adjust"]//input[@name="request_id"]')->item(0)->getAttribute('value');
    expect(Str::isUlid($id))->toBeTrue();
});

it('restores the wallet dialog on a customer history tab without loading a wallet summary', function (): void {
    $actor = financialFallbackUiActor('customers.view', 'finance.view', 'finance.wallet-adjust', 'upstream.view');
    $owner = User::factory()->create(['credit' => '45.67']);
    $path = '/admin/customers/'.$owner->id.'?tab=services&services_page=2';
    $response = $this->actingAs($actor)->get($path)->assertOk()->assertDontSee('当前可用 45.67')->assertDontSee('data-customer-metric="credit"', false);
    $xpath = financialFallbackUiXpath($response->getContent());
    $form = $xpath->query('//dialog[@id="customer-wallet-adjust"]//form')->item(0);
    $requestId = $xpath->query('.//input[@name="request_id"]', $form)->item(0)->getAttribute('value');
    $failed = $this->post($form->getAttribute('action'), ['request_id' => $requestId, 'direction' => 'debit',
        'amount' => '90.12', 'currency' => 'CNY', 'reason' => 'HISTORY-WALLET-NOTE', 'confirm' => '1'])
        ->assertRedirect()->assertSessionHasErrors('amount');
    $location = $failed->headers->get('Location');
    parse_str(parse_url($location, PHP_URL_QUERY), $query);
    expect($query)->toEqual(['tab' => 'services', 'services_page' => '2']);
    $response = $this->get($location)->assertOk()->assertDontSee('当前可用 45.67')->assertSee('HISTORY-WALLET-NOTE');
    $xpath = financialFallbackUiXpath($response->getContent());
    expect($xpath->query('//nav[@aria-label="客户详情页签"]//a[@data-customer-tab="services"][@aria-current="page"]'))->toHaveCount(1)
        ->and($xpath->query('//dialog[@id="customer-wallet-adjust"]')->item(0)->getAttribute('x-init'))->toContain('showModal')
        ->and($xpath->query('//dialog[@id="customer-wallet-adjust"]//input[@name="request_id"]')->item(0)->getAttribute('value'))->toBe($requestId)
        ->and($owner->fresh()->credit)->toBe('45.67');
    $this->assertDatabaseCount('financial_transactions', 0);
});

it('keeps viewers readonly and rejects both new post routes without their special authority', function (): void {
    $actor = financialFallbackUiActor('customers.view', 'finance.view');
    $invoice = financialFallbackUiInvoice();
    $this->actingAs($actor)->get('/admin/finance/invoice?invoice_id='.$invoice->id)->assertOk()->assertDontSee('确认登记收款');
    $this->postJson('/admin/customers/'.$invoice->user_id.'/wallet-adjust', [])->assertForbidden();
    $this->postJson('/admin/finance/invoices/'.$invoice->id.'/offline-receipt', [])->assertForbidden();
    $this->assertDatabaseCount('offline_invoice_receipts', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
});

it('shows a fixed exact offline amount without requiring customer or task permissions', function (): void {
    $actor = financialFallbackUiActor('finance.view', 'finance.offline-receipt');
    $invoice = financialFallbackUiInvoice();
    $response = $this->actingAs($actor)->get('/admin/finance/invoice?invoice_id='.$invoice->id)->assertOk()
        ->assertSee('原支付计划')->assertSee('实际收款来源')->assertSee('登记已收到的线下款')->assertDontSee('关联履约任务');
    $xpath = financialFallbackUiXpath($response->getContent());
    $form = '//dialog[@id="invoice-offline-receipt"]//form';
    expect($xpath->query($form.'//input[@name="amount"][@value="10.00"][@readonly]'))->toHaveCount(1)
        ->and($xpath->query($form.'//input[@name="currency"][@value="CNY"]'))->toHaveCount(1)
        ->and($xpath->query($form.'//input[@name="reason"][not(@required)]'))->toHaveCount(1)
        ->and($xpath->query($form.'//textarea[@name="evidence"][@maxlength="2000"]'))->toHaveCount(1)
        ->and($xpath->query('//main//a[@href="/admin/customers/'.$invoice->user_id.'"]'))->toHaveCount(0)
        ->and($xpath->query('//select[@name="external_channel"]/option'))->toHaveCount(2);
});

it('does not offer ordinary offline settlement for unsupported invoice facts', function (string $kind): void {
    $actor = financialFallbackUiActor('finance.view', 'finance.offline-receipt');
    $invoice = financialFallbackUiInvoice();
    if ($kind === 'attempt') {
        PaymentAttempt::create(['invoice_id' => $invoice->id, 'gateway' => 'epay_wechat', 'out_trade_no' => 'UI-INFLIGHT', 'amount' => '10.00', 'status' => 'created']);
    } else {
        $invoice->forceFill(match ($kind) {
            'paid' => ['status' => InvoiceStatus::PAID, 'paid_at' => now()],
            'expired' => ['expires_at' => now()->subMinute()],
            'topup' => ['type' => InvoiceType::WALLET_TOPUP],
            'downgrade' => ['type' => InvoiceType::DOWNGRADE],
            'foreign' => ['currency' => 'USD'],
            'wallet' => ['gateway_amount' => '0.00', 'credit_applied' => '10.00'],
            'pending choice' => ['line_items' => ['automatic_renewal' => ['selection_pending' => true]]],
            'missing snapshot' => ['line_items' => []],
        })->save();
    }
    $response = $this->actingAs($actor)->get('/admin/finance/invoice?invoice_id='.$invoice->id)->assertOk();
    expect(financialFallbackUiXpath($response->getContent())->query('//form[contains(@action,"/offline-receipt")]'))->toHaveCount(0);
    $this->assertDatabaseCount('offline_invoice_receipts', 0);
})->with(['paid', 'expired', 'topup', 'downgrade', 'foreign', 'wallet', 'pending choice', 'missing snapshot', 'attempt']);

it('reopens only the rejected offline form and never restores private evidence or a forged amount', function (): void {
    $actor = financialFallbackUiActor('finance.view', 'finance.offline-receipt');
    $invoice = financialFallbackUiInvoice();
    $other = financialFallbackUiInvoice();
    $input = financialFallbackUiOfflineInput();
    unset($input['confirm']);
    $input['amount'] = '999.99';
    $input['reason'] = 'C3-OFFLINE-RESTORE';
    $path = '/admin/finance/invoice?invoice_id='.$invoice->id.'&tab=overview';
    $this->actingAs($actor)->from($path)->post('/admin/finance/invoices/'.$invoice->id.'/offline-receipt?tab=overview', $input)
        ->assertRedirect($path)->assertSessionHasErrors('confirm');
    $response = $this->get($path)->assertOk()->assertSee('C3-OFFLINE-RESTORE')->assertDontSee('PRIVATE-C3-RECEIPT-EVIDENCE')
        ->assertDontSee('value="999.99"', false)->assertDontSee($input['external_reference']);
    $xpath = financialFallbackUiXpath($response->getContent());
    expect($xpath->query('//dialog[@id="invoice-offline-receipt"]//input[@name="request_id"][@value="'.$input['request_id'].'"]'))->toHaveCount(1)
        ->and($xpath->query('//dialog[@id="invoice-offline-receipt"]//input[@name="external_reference"][@value=""]'))->toHaveCount(1)
        ->and($xpath->query('//dialog[@id="invoice-offline-receipt"]//textarea[@name="evidence"]')->item(0)->textContent)->toBe('');
    $this->get('/admin/finance/invoice?invoice_id='.$other->id)->assertOk()->assertDontSee('C3-OFFLINE-RESTORE')
        ->assertDontSee($input['external_reference']);
});

it('records an offline receipt through the existing workbench route and exposes its source only as a mask', function (): void {
    $actor = financialFallbackUiActor('finance.view', 'finance.offline-receipt');
    $invoice = financialFallbackUiInvoice();
    $input = financialFallbackUiOfflineInput();
    $this->actingAs($actor)->post('/admin/finance/invoices/'.$invoice->id.'/offline-receipt', $input)
        ->assertRedirect('/admin/finance/invoice?invoice_id='.$invoice->id)->assertSessionHas('status');
    $receipt = OfflineInvoiceReceipt::firstOrFail();
    $response = $this->get('/admin/finance/invoice?invoice_id='.$invoice->id)->assertOk()->assertSee('线下收款已登记')
        ->assertSee('银行转账')->assertSee(mb_substr($input['external_reference'], -4))->assertDontSee($input['external_reference'])
        ->assertDontSee('PRIVATE-C3-RECEIPT-EVIDENCE')->assertDontSee('确认登记收款');
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::PAID)->and($invoice->fresh()->gateway)->toBe('epay_wechat');
    $data = financialFallbackUiData($invoice);
    expect(array_keys($data['offlineReceipt']))->not->toContain('external_reference', 'encrypted_evidence', 'intent');
    $this->assertDatabaseCount('payment_callbacks', 0);
    $this->assertDatabaseCount('payment_attempts', 0);
    $this->assertDatabaseCount('fulfillment_tasks', 1);
    expect(DB::table('audit_logs')->where('action', 'invoice.offline_receipt_details')->count())->toBe(0);
    $this->postJson('/admin/finance/offline-receipts/'.$receipt->id.'/details')->assertOk()
        ->assertJsonPath('external_reference', $input['external_reference'])->assertJsonPath('evidence', 'PRIVATE-C3-RECEIPT-EVIDENCE')
        ->assertHeader('Cache-Control', 'no-store, private');
    expect(DB::table('audit_logs')->where('action', 'invoice.offline_receipt_details')->count())->toBe(1);
});

it('never decrypts receipt evidence on GET and denies the explicit details POST to finance viewers', function (): void {
    $operator = financialFallbackUiActor('finance.view', 'finance.offline-receipt');
    $invoice = financialFallbackUiInvoice();
    $receipt = app(RecordOfflineInvoiceReceiptAction::class)->execute($invoice, $operator, financialFallbackUiOfflineInput());
    DB::table('offline_invoice_receipts')->where('id', $receipt->id)->update(['encrypted_evidence' => 'INVALID-CIPHERTEXT-C3']);
    $viewer = financialFallbackUiActor('finance.view');
    $this->actingAs($viewer)->get('/admin/finance/invoice?invoice_id='.$invoice->id)->assertOk()
        ->assertDontSee('INVALID-CIPHERTEXT-C3')->assertDontSee('查看完整凭证');
    $this->postJson('/admin/finance/offline-receipts/'.$receipt->id.'/details')->assertForbidden();
    expect(DB::table('audit_logs')->where('action', 'invoice.offline_receipt_details')->count())->toBe(0);
});

it('fully masks short external references instead of exposing their complete identifier on GET', function (): void {
    $operator = financialFallbackUiActor('finance.view', 'finance.offline-receipt');
    $invoice = financialFallbackUiInvoice();
    $input = financialFallbackUiOfflineInput();
    $input['external_reference'] = 'X7Q';
    app(RecordOfflineInvoiceReceiptAction::class)->execute($invoice, $operator, $input);
    $this->actingAs(financialFallbackUiActor('finance.view'))->get('/admin/finance/invoice?invoice_id='.$invoice->id)
        ->assertOk()->assertDontSee('X7Q');
    expect(financialFallbackUiData($invoice)['offlineReceipt']['masked_reference'])->toBe('••••');
});

it('projects only safe invoice item fields and never returns stored request operation or credential blobs', function (): void {
    $actor = financialFallbackUiActor('finance.view');
    $invoice = financialFallbackUiInvoice();
    $invoice->update(['line_items' => ['items' => [
        ['kind' => 'product', 'label' => '安全项目', 'qty' => 1, 'unit_price' => '10.00', 'amount' => '10.00', 'secret' => 'PRIVATE-C3-ITEM'],
        ['kind' => 'credential', 'label' => 'PRIVATE-C3-CREDENTIAL', 'qty' => 1, 'unit_price' => '0.00', 'amount' => '0.00'],
        ['kind' => 'setup_fee', 'label' => '<script>html-is-text</script>', 'qty' => [], 'unit_price' => [], 'amount' => 'INVALID'],
    ], 'purchase' => ['encrypted_params' => 'PRIVATE-C3-PARAMS', 'encrypted_request' => 'PRIVATE-C3-REQUEST'], 'encrypted_operation' => 'PRIVATE-C3-OPERATION']]);
    $response = $this->actingAs($actor)->get('/admin/finance/invoice?invoice_id='.$invoice->id.'&tab=items')->assertOk()->assertSee('安全项目')
        ->assertSee('&lt;script&gt;html-is-text&lt;/script&gt;', false)->assertDontSee('PRIVATE-C3');
    $data = financialFallbackUiData($invoice, 'tab=items');
    expect($data['invoiceItems'][0])->toBe(['label' => '安全项目', 'qty' => '1', 'unit_price' => '10.00', 'amount' => '10.00'])
        ->and(array_key_exists('line_items', $data['invoice']->getAttributes()))->toBeFalse()
        ->and($data['invoiceItems'][1]['qty'])->toBe('—')->and($data['invoiceItems'][1]['amount'])->toBe('—');
});

it('independently paginates local ledger and tasks and preserves safe context without private payloads', function (): void {
    $actor = financialFallbackUiActor('finance.view', 'tasks.view');
    $instance = Instance::factory()->create();
    $invoice = Invoice::factory()->paid()->create(['user_id' => $instance->user_id, 'product_id' => $instance->product_id, 'instance_id' => $instance->id]);
    for ($index = 0; $index < 23; $index++) {
        FinancialTransaction::create(['user_id' => $invoice->user_id, 'invoice_id' => $invoice->id, 'type' => TransactionType::PAYMENT,
            'amount' => '1.00', 'reference_key' => 'c3-ui-ledger:'.$index, 'meta' => ['private' => 'PRIVATE-C3-LEDGER']]);
        FulfillmentTask::factory()->create(['user_id' => $invoice->user_id, 'invoice_id' => $invoice->id, 'instance_id' => $instance->id,
            'idempotency_key' => 'c3-ui-task:'.$index, 'payload' => ['private' => 'PRIVATE-C3-TASK']]);
    }
    $this->actingAs($actor);
    $data = financialFallbackUiData($invoice, 'ledger_page=2&tasks_page=3&attempts_page=2&user_id='.$invoice->user_id.'&untrusted=x');
    expect($data['ledger']->currentPage())->toBe(2)->and($data['ledger']->count())->toBe(10)
        ->and($data['tasks']->currentPage())->toBe(3)->and($data['tasks']->count())->toBe(3)
        ->and($data['returnUrl'])->toBe('/admin/finance?user_id='.$invoice->user_id);
    parse_str(parse_url($data['ledger']->url(3), PHP_URL_QUERY), $query);
    expect($query)->toBe(['user_id' => (string) $invoice->user_id, 'invoice_id' => (string) $invoice->id,
        'attempts_page' => '2', 'tasks_page' => '3', 'tab' => 'payments', 'ledger_page' => '3']);
    foreach ($data['ledger'] as $entry) {
        expect(array_key_exists('meta', $entry->getAttributes()))->toBeFalse();
    }
    foreach ($data['tasks'] as $task) {
        expect(array_key_exists('payload', $task->getAttributes()))->toBeFalse();
    }
    $this->get('/admin/finance/invoice?invoice_id='.$invoice->id.'&ledger_page=2&tasks_page=3')->assertOk()->assertDontSee('PRIVATE-C3');
});

it('does not query task data without task permission and preserves the old paid refund path', function (): void {
    $actor = financialFallbackUiActor('finance.view', 'finance.refund');
    $invoice = Invoice::factory()->paid()->create();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $response = $this->actingAs($actor)->get('/admin/finance/invoice?invoice_id='.$invoice->id)->assertOk()->assertSee('确认全额退款');
    expect(collect($queries)->contains(fn (string $query): bool => str_contains($query, '"fulfillment_tasks"')))->toBeFalse();
    $xpath = financialFallbackUiXpath($response->getContent());
    expect($xpath->query('//form[@action="/admin/finance/invoices/'.$invoice->id.'/refund"]//input[@name="ui_form"][@value="refund-'.$invoice->id.'"]'))->toHaveCount(1)
        ->and($xpath->query('//form[contains(@action,"/offline-receipt")]'))->toHaveCount(0);
});

it('rejects malformed workbench ledger and task pages before rendering', function (string $query): void {
    $invoice = Invoice::factory()->create();
    $actor = financialFallbackUiActor('finance.view');
    $this->actingAs($actor)->getJson('/admin/finance/invoice?invoice_id='.$invoice->id.'&'.$query)->assertUnprocessable();
})->with(['ledger_page=0', 'ledger_page[]=1', 'tasks_page=-1', 'tasks_page[]=2']);
