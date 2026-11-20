<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\TransactionType;
use App\Filament\Pages\FinanceInvoice;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
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

function invoiceWorkspaceActor(string ...$abilities): User
{
    $actor = User::factory()->create();
    foreach (['finance.view', ...$abilities] as $ability) {
        $actor->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }

    return $actor;
}

function invoiceWorkspaceData(Invoice $invoice, array $query = []): array
{
    $request = Request::create('/admin/finance/invoice?'.http_build_query(['invoice_id' => $invoice->id] + $query));
    app()->instance('request', $request);
    app()->instance('originalRequest', $request);
    $page = app(FinanceInvoice::class);
    $page->invoiceId = $invoice->id;

    return (new ReflectionMethod($page, 'getViewData'))->invoke($page);
}

it('loads only the selected invoice history with explicit safe projections', function (string $tab, array $loaded, array $forbidden): void {
    $invoice = Invoice::factory()->paid()->create(['line_items' => ['private' => 'PRIVATE-U2C']]);
    $this->actingAs(invoiceWorkspaceActor('tasks.view'));
    DB::enableQueryLog();
    DB::flushQueryLog();
    $data = invoiceWorkspaceData($invoice, ['tab' => $tab]);
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();
    foreach ($loaded as $table) {
        expect($queries->contains(fn (string $sql): bool => str_contains($sql, 'from "'.$table.'"')))->toBeTrue();
    }
    foreach ($forbidden as $table) {
        expect($queries->contains(fn (string $sql): bool => str_contains($sql, 'from "'.$table.'"')))->toBeFalse();
    }
    foreach (['attempts' => 'payments', 'callbacks' => 'payments', 'ledger' => 'ledger', 'tasks' => 'tasks'] as $key => $ownerTab) {
        expect($data[$key] === null)->toBe($tab !== $ownerTab);
    }
    expect($data['tab'])->toBe($tab)->and($data['recoveryInfo'])->toBeNull()
        ->and(array_key_exists('line_items', $data['invoice']->getAttributes()))->toBeFalse()
        ->and($queries->contains(fn (string $sql): bool => preg_match('/select \* from "(?:payment_attempts|payment_callbacks|financial_transactions|fulfillment_tasks)"/', $sql) === 1))->toBeFalse();
})->with([
    'overview' => ['overview', ['offline_invoice_receipts', 'financial_transactions'], ['payment_attempts', 'payment_callbacks', 'fulfillment_tasks']],
    'items' => ['items', ['invoices'], ['offline_invoice_receipts', 'financial_transactions', 'payment_attempts', 'payment_callbacks', 'fulfillment_tasks']],
    'payments' => ['payments', ['payment_attempts', 'payment_callbacks'], ['offline_invoice_receipts', 'financial_transactions', 'fulfillment_tasks']],
    'ledger' => ['ledger', ['financial_transactions'], ['offline_invoice_receipts', 'payment_attempts', 'payment_callbacks', 'fulfillment_tasks']],
    'tasks' => ['tasks', ['fulfillment_tasks'], ['offline_invoice_receipts', 'financial_transactions', 'payment_attempts', 'payment_callbacks']],
]);

it('rejects unknown or malformed invoice tabs without a historical fallback', function (string $query): void {
    $invoice = Invoice::factory()->create();
    $this->actingAs(invoiceWorkspaceActor())->getJson('/admin/finance/invoice?invoice_id='.$invoice->id.'&'.$query)
        ->assertUnprocessable()->assertJsonPath('message', '账单页签无效。');
})->with(['tab=unknown', 'tab[]=overview', 'tab=']);

it('requires independent task permission and rejects tabs that do not belong to the invoice type', function (): void {
    $invoice = Invoice::factory()->paid()->create();
    $this->actingAs(invoiceWorkspaceActor())->get('/admin/finance/invoice?invoice_id='.$invoice->id.'&tab=tasks')->assertForbidden();
    $this->get('/admin/finance/invoice?invoice_id='.$invoice->id.'&tab=recovery')->assertForbidden();
    $invoice->update(['type' => InvoiceType::WALLET_TOPUP]);
    $this->actingAs(invoiceWorkspaceActor('tasks.view'))->get('/admin/finance/invoice?invoice_id='.$invoice->id.'&tab=tasks')->assertForbidden();
    $this->get('/admin/finance/invoice?invoice_id='.$invoice->id)->assertOk()
        ->assertDontSee('tab=tasks', false)->assertDontSee('tab=recovery', false)->assertSee('已到账');
});

it('keeps paid status separate from receipt evidence and ignores late or duplicate wallet credits', function (): void {
    $invoice = Invoice::factory()->paid()->create(['gateway' => 'epay_wechat']);
    FinancialTransaction::create(['invoice_id' => $invoice->id, 'user_id' => $invoice->user_id,
        'type' => TransactionType::CREDIT_IN, 'amount' => '876.54', 'reference_key' => 'credit_in:callback:999']);
    FinancialTransaction::create(['invoice_id' => $invoice->id, 'user_id' => $invoice->user_id,
        'type' => TransactionType::CREDIT_IN, 'amount' => '765.43', 'reference_key' => 'overpaid:'.$invoice->id]);
    $this->actingAs(invoiceWorkspaceActor())->get('/admin/finance/invoice?invoice_id='.$invoice->id)->assertOk()
        ->assertSee('已入账')->assertSee('未记录实际收款凭证')->assertDontSee('876.54')->assertDontSee('765.43');
    expect(invoiceWorkspaceData($invoice)['receiptFacts'])->toBe([]);
    $this->assertDatabaseCount('financial_transactions', 2);
});

it('shows exact original wallet payment and topup postings without loading private metadata', function (): void {
    $invoice = Invoice::factory()->paid()->create(['type' => InvoiceType::WALLET_TOPUP]);
    foreach ([['settle:', TransactionType::CREDIT_SETTLE, '3.00'], ['payment:', TransactionType::PAYMENT, '7.00'], ['topup:', TransactionType::CREDIT_IN, '7.00']] as [$prefix, $type, $amount]) {
        FinancialTransaction::create(['invoice_id' => $invoice->id, 'user_id' => $invoice->user_id, 'type' => $type,
            'amount' => $amount, 'reference_key' => $prefix.$invoice->id, 'meta' => ['secret' => 'PRIVATE-U2C-RECEIPT']]);
    }
    $this->actingAs(invoiceWorkspaceActor());
    $data = invoiceWorkspaceData($invoice);
    expect(array_column($data['receiptFacts'], 'label'))->toEqualCanonicalizing(['钱包已结算', '付款流水已记录', '充值已入钱包']);
    foreach ($data['receiptFacts'] as $fact) {
        expect(array_keys($fact))->toBe(['id', 'amount', 'created_at', 'label']);
    }
    $this->get('/admin/finance/invoice?invoice_id='.$invoice->id)->assertOk()->assertSee('7.00 CNY')->assertSee('3.00 CNY')
        ->assertSee('充值已入钱包')->assertDontSee('PRIVATE-U2C-RECEIPT')->assertDontSee('未记录实际收款凭证');
    $this->assertDatabaseCount('financial_transactions', 3);
});

it('keeps current tab and safe source while dropping inactive pagination from links', function (): void {
    $invoice = Invoice::factory()->paid()->create();
    $this->actingAs(invoiceWorkspaceActor('customers.view', 'tasks.view'));
    $source = ['kind' => 'customer', 'id' => $invoice->user_id, 'query' => ['tab' => 'invoices', 'invoices_page' => 3], 'section' => 'customer-invoices'];
    $data = invoiceWorkspaceData($invoice, ['tab' => 'ledger', 'ledger_page' => 2, 'attempts_page' => 8, 'tasks_page' => 7, 'from' => $source, 'untrusted' => 'drop']);
    parse_str(parse_url($data['ledger']->url(3), PHP_URL_QUERY), $query);
    expect($query)->toBe(['invoice_id' => (string) $invoice->id, 'tab' => 'ledger', 'from' => [
        'kind' => 'customer', 'id' => (string) $invoice->user_id,
        'query' => ['invoices_page' => '3', 'tab' => 'invoices'], 'section' => 'customer-invoices'], 'ledger_page' => '3']);
    parse_str(parse_url($data['tabLinks']['payments']['url'], PHP_URL_QUERY), $link);
    expect($link['tab'])->toBe('payments')->and($link)->not->toHaveKeys(['ledger_page', 'attempts_page', 'tasks_page', 'untrusted'])
        ->and($link['from'])->toBe($query['from']);
});

it('restores a rejected financial dialog on its selected history tab without changing money or restoring private evidence', function (string $operation): void {
    $invoice = Invoice::factory()->create(['line_items' => ['purchase' => ['encrypted_params' => Crypt::encryptString('{}')]]]);
    $path = '/admin/finance/invoice?invoice_id='.$invoice->id.'&callbacks_page=2&tab=payments';
    if ($operation === 'refund') {
        $invoice->update(['status' => InvoiceStatus::PAID, 'paid_at' => now()]);
        $actor = invoiceWorkspaceActor('finance.refund');
        $postPath = '/admin/finance/invoices/'.$invoice->id.'/refund?tab=payments&callbacks_page=2';
        $input = ['ui_form' => 'refund-'.$invoice->id, 'mode' => 'external_record', 'reason' => 'U2C-RESTORE-FINANCE', 'keep_service' => '1'];
        $dialog = 'invoice-refund';
    } else {
        $actor = invoiceWorkspaceActor('finance.offline-receipt');
        $postPath = '/admin/finance/invoices/'.$invoice->id.'/offline-receipt?tab=payments&callbacks_page=2';
        $input = ['_admin_form' => 'offline-receipt', 'invoice_id' => (string) $invoice->id,
            'request_id' => (string) Str::ulid(), 'amount' => '10.00', 'currency' => 'CNY',
            'external_channel' => 'bank_transfer', 'external_reference' => 'PRIVATE-U2C-REFERENCE', 'evidence' => 'PRIVATE-U2C-EVIDENCE',
            'received_at' => now()->subMinute()->format('Y-m-d\TH:i'), 'reason' => 'U2C-RESTORE-FINANCE'];
        $dialog = 'invoice-offline-receipt';
    }
    $this->actingAs($actor)->from($path)->post($postPath, $input)->assertRedirect($path)->assertSessionHasErrors('confirm');
    $response = $this->get($path)->assertOk()->assertSee('U2C-RESTORE-FINANCE')
        ->assertSee('支付尝试')->assertDontSee('PRIVATE-U2C-REFERENCE')->assertDontSee('PRIVATE-U2C-EVIDENCE');
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    $xpath = new DOMXPath($document);
    expect($xpath->query('//dialog[@id="'.$dialog.'"][@x-init="$nextTick(() => $el.showModal())"]'))->toHaveCount(1)
        ->and($xpath->query('//nav[@aria-label="账单页签"]//a[@aria-current="page"]')->item(0)->textContent)->toBe('支付记录')
        ->and($xpath->query('//nav[@aria-label="账单页签"]//a[not(contains(concat(" ", normalize-space(@class), " "), " admin-workspace-tab "))]'))->toHaveCount(0)
        ->and($xpath->query('//nav[@aria-label="账单页签"]//a[@aria-current="page"]'))->toHaveCount(1);
    if ($operation === 'offline-receipt') {
        expect($xpath->query('//dialog[@id="'.$dialog.'"]//input[@name="external_reference"][@value=""]'))->toHaveCount(1)
            ->and($xpath->query('//dialog[@id="'.$dialog.'"]//textarea[@name="evidence"]')->item(0)->textContent)->toBe('');
    }
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('offline_invoice_receipts', 0);
})->with(['refund', 'offline-receipt']);
