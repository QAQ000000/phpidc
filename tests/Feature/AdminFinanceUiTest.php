<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentCallbackOutcome;
use App\Filament\Pages\FinanceInvoice;
use App\Filament\Pages\FinanceInvoices;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\PaymentCallback;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

function financeUiActor(string ...$roles): User
{
    $actor = User::factory()->create();
    foreach ($roles ?: ['finance-viewer'] as $role) {
        app(AssignAdminRoleAction::class)->execute($actor, $role, 'Isolated financial presentation acceptance');
    }

    return $actor;
}

function financeUiXpath(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

    return new DOMXPath($document);
}

function financeUiData(string $query = '', ?Invoice $invoice = null): array
{
    $request = Request::create('/admin/finance'.($invoice ? '/invoice' : '').($query === '' ? '' : '?'.$query));
    app()->instance('request', $request);
    app()->instance('originalRequest', $request);
    $page = app($invoice ? FinanceInvoice::class : FinanceInvoices::class);
    if ($invoice) {
        $page->invoiceId = $invoice->id;
    }

    return (new ReflectionMethod($page, 'getViewData'))->invoke($page);
}

it('renders a single sidebar and exact invoice filters without granting write forms to viewers', function (): void {
    $invoice = Invoice::factory()->paid()->create(['invoice_no' => 'UI-EXACT-INVOICE']);
    Invoice::factory()->paid()->create(['invoice_no' => 'UI-EXACT-INVOICE-SUFFIX']);
    $response = $this->actingAs(financeUiActor())->get('/admin/finance?'.http_build_query([
        'invoice_no' => $invoice->invoice_no, 'user_id' => $invoice->user_id, 'status' => 'Paid', 'type' => 'new_order',
    ]))->assertOk()->assertSee($invoice->invoice_no)->assertDontSee('UI-EXACT-INVOICE-SUFFIX');
    $xpath = financeUiXpath($response->getContent());

    expect($xpath->query('//nav[contains(concat(" ", normalize-space(@class), " "), " fi-sidebar-nav ")]'))->toHaveCount(1)
        ->and($xpath->query('//main//nav[not(@aria-label="结果分页")]'))->toHaveCount(0)
        ->and($xpath->query('//form[@action="/admin/finance"][@method="get"][@aria-label="筛选条件"]'))->toHaveCount(1)
        ->and($xpath->query('//input[@name="invoice_no"][@value="UI-EXACT-INVOICE"]'))->toHaveCount(1)
        ->and($xpath->query('//input[@name="user_id"][@value="'.$invoice->user_id.'"]'))->toHaveCount(1)
        ->and($xpath->query('//select[@name="status"]/option[@value="Paid"][@selected]'))->toHaveCount(1)
        ->and($xpath->query('//select[@name="type"]/option[@value="new_order"][@selected]'))->toHaveCount(1)
        ->and($xpath->query('//div[@role="region"][@aria-label="财务账单列表"]/table/thead/tr/th[@scope="col"]'))->toHaveCount(6)
        ->and($xpath->query('//form[@action="/admin/finance/wallet-topup-settings"]'))->toHaveCount(0);
});

it('paginates invoice facts stably with only approved filters and no stored order snapshots', function (): void {
    $owner = User::factory()->create();
    $invoices = Invoice::factory()->count(23)->create(['user_id' => $owner->id, 'status' => InvoiceStatus::PAID,
        'line_items' => ['private' => 'PRIVATE-FINANCE-LINE-ITEMS']]);
    $data = financeUiData('user_id='.$owner->id.'&status=Paid&type=new_order&page=2&injected=untrusted');

    expect($data['invoices']->total())->toBe(23)->and($data['invoices']->perPage())->toBe(20)
        ->and($data['invoices']->pluck('id')->all())->toBe($invoices->take(3)->reverse()->pluck('id')->all());
    foreach ($data['invoices'] as $invoice) {
        expect(array_key_exists('line_items', $invoice->getAttributes()))->toBeFalse()
            ->and(array_key_exists('upstream_snapshot', $invoice->getAttributes()))->toBeFalse();
    }
    parse_str(parse_url($data['invoices']->url(1), PHP_URL_QUERY), $query);
    expect($query)->toBe(['user_id' => (string) $owner->id, 'status' => 'Paid', 'type' => 'new_order', 'page' => '1']);
});

it('treats invoice number zero as a real exact filter and provides a reset when it has no match', function (): void {
    $invoice = Invoice::factory()->create(['invoice_no' => '0']);
    Invoice::factory()->create(['invoice_no' => 'ZERO-OTHER']);
    $this->actingAs(financeUiActor())->get('/admin/finance?invoice_no=0')->assertOk()->assertDontSee('ZERO-OTHER');
    expect(financeUiData('invoice_no=0')['invoices']->pluck('id')->all())->toBe([$invoice->id]);
    $invoice->delete();
    $response = $this->get('/admin/finance?invoice_no=0')->assertOk()->assertSee('没有符合条件的账单。')
        ->assertSee('调整筛选条件或重置后重试。');
    $xpath = financeUiXpath($response->getContent());
    expect($xpath->query('//input[@name="invoice_no"][@value="0"]'))->toHaveCount(1)
        ->and($xpath->query('//table/tbody/tr/td//a[@href="/admin/finance"][normalize-space(.)="重置筛选"]'))->toHaveCount(1);
});

it('renders exact amounts and distinguishes credited topups from product fulfillment', function (): void {
    $invoice = Invoice::factory()->paid()->create(['invoice_no' => 'UI-TOPUP', 'type' => InvoiceType::WALLET_TOPUP,
        'product_id' => null, 'total_amount' => '1234567890.12', 'gateway_amount' => '1234567890.12']);
    $this->actingAs(financeUiActor('finance-operator', 'task-viewer'))->get('/admin/finance')->assertOk()
        ->assertSee('1234567890.12')->assertSee('钱包充值')->assertSee('已到账')->assertSee('核查到账');
    $response = $this->get('/admin/finance/invoice?invoice_id='.$invoice->id)->assertOk()->assertSee('已到账')
        ->assertSee('暂不支持充值退款')->assertDontSee('确认全额退款')->assertDontSee('关联任务');
    expect(financeUiXpath($response->getContent())->query('//form[contains(@action,"/refund")]'))->toHaveCount(0);
});

it('rejects malformed financial list filters rather than casting them or silently dropping a status', function (string $query): void {
    $this->actingAs(financeUiActor())->getJson('/admin/finance?'.$query)->assertUnprocessable()
        ->assertJsonPath('message', '账单筛选参数无效。');
})->with(['invoice_no[]=x', 'invoice_no='.str_repeat('x', 65), 'user_id[]=1', 'user_id=0',
    'status[]=Paid', 'status=paid', 'type[]=new_order', 'type=not-real', 'page=0', 'page[]=2']);

it('rejects invalid financial detail selectors without selecting another record', function (string $query): void {
    Invoice::factory()->create();
    $this->actingAs(financeUiActor())->get('/admin/finance/invoice'.$query)->assertNotFound();
})->with(['', '?invoice_id=0', '?invoice_id=-1', '?invoice_id=1.5', '?invoice_id=not-an-id', '?invoice_id[]=1', '?invoice_id=999999']);

it('rejects invalid payment record pagination on financial details', function (string $query): void {
    $invoice = Invoice::factory()->create();
    $this->actingAs(financeUiActor())->getJson('/admin/finance/invoice?invoice_id='.$invoice->id.'&'.$query)
        ->assertUnprocessable()->assertJsonPath('message', '支付记录分页参数无效。');
})->with(['attempts_page=0', 'attempts_page[]=1', 'attempts_page=1.5', 'callbacks_page=-1', 'callbacks_page[]=2']);

it('independently paginates safe payment records while retaining the invoice and safe list return context', function (): void {
    $invoice = Invoice::factory()->paid()->create(['invoice_no' => 'UI-DETAIL-PAYMENTS', 'gateway' => 'epay_wechat']);
    $attempts = collect();
    for ($i = 0; $i < 23; $i++) {
        $attempts->push(PaymentAttempt::create(['invoice_id' => $invoice->id, 'gateway' => 'epay_wechat',
            'out_trade_no' => 'UI-EXACT-ATTEMPT-'.$i, 'amount' => '10.00', 'status' => 'created',
            'request_payload' => ['key' => 'PRIVATE-FINANCE-ATTEMPT']]));
        PaymentCallback::factory()->create(['invoice_id' => $invoice->id, 'gateway' => 'epay_wechat',
            'gateway_trade_no' => 'UI-EXACT-CALLBACK-'.$i, 'raw_payload' => ['sign' => 'PRIVATE-FINANCE-CALLBACK'],
            'outcome' => PaymentCallbackOutcome::PROCESSED_PAID]);
    }
    $query = 'invoice_id='.$invoice->id.'&invoice_no=0&status=Paid&page=2&tab=payments&attempts_page=2&callbacks_page=2&injected=untrusted';
    $this->actingAs(financeUiActor());
    $data = financeUiData($query, $invoice);
    expect($data['attempts']->pluck('id')->all())->toBe($attempts->slice(3, 10)->reverse()->pluck('id')->all())
        ->and($data['returnUrl'])->toBe('/admin/finance?invoice_no=0&status=Paid&page=2');
    parse_str(parse_url($data['attempts']->url(3), PHP_URL_QUERY), $attemptQuery);
    parse_str(parse_url($data['callbacks']->url(1), PHP_URL_QUERY), $callbackQuery);
    expect($attemptQuery)->toBe(['invoice_id' => (string) $invoice->id, 'invoice_no' => '0', 'status' => 'Paid', 'page' => '2', 'callbacks_page' => '2', 'tab' => 'payments', 'attempts_page' => '3'])
        ->and($callbackQuery)->toBe(['invoice_id' => (string) $invoice->id, 'invoice_no' => '0', 'status' => 'Paid', 'page' => '2', 'attempts_page' => '2', 'tab' => 'payments', 'callbacks_page' => '1']);
    foreach ($data['attempts'] as $attempt) {
        expect(array_key_exists('request_payload', $attempt->getAttributes()))->toBeFalse();
    }
    foreach ($data['callbacks'] as $callback) {
        expect(array_key_exists('raw_payload', $callback->getAttributes()))->toBeFalse();
    }
    $response = $this->actingAs(financeUiActor())->get('/admin/finance/invoice?'.$query)->assertOk()
        ->assertSee('UI-EXACT-ATTEMPT-12')->assertSee('UI-EXACT-CALLBACK-12')->assertDontSee('UI-EXACT-ATTEMPT-22')
        ->assertDontSee('PRIVATE-FINANCE')->assertDontSee('确认全额退款');
    $xpath = financeUiXpath($response->getContent());
    expect($xpath->query('//nav[@aria-label="结果分页"]'))->toHaveCount(2)
        ->and($xpath->query('//a[@href="/admin/finance?invoice_no=0&status=Paid&page=2"][normalize-space(.)="返回账单"]'))->toHaveCount(1);
});

it('keeps refund evidence fields and opens only the matching failed refund with nonsecret input restored', function (): void {
    $instance = Instance::factory()->create(['status' => InstanceStatus::ACTIVE]);
    $invoice = Invoice::factory()->paid()->create(['instance_id' => $instance->id, 'user_id' => $instance->user_id, 'product_id' => $instance->product_id]);
    $path = '/admin/finance/invoice?invoice_id='.$invoice->id.'&tab=overview';
    $this->actingAs(financeUiActor('finance-operator'))->from($path)->post('/admin/finance/invoices/'.$invoice->id.'/refund?tab=overview', [
        'ui_form' => 'refund-'.$invoice->id, 'mode' => 'external_record', 'reason' => 'Existing external evidence', 'keep_service' => '1',
    ])->assertRedirect($path)->assertSessionHasErrors(['confirm', 'external_confirm']);
    $response = $this->get($path)->assertOk()->assertSee('退款说明（保留服务时必填）')
        ->assertSee('x-show="mode === \'external_record\'"', false)->assertSee(':disabled="mode !== \'external_record\'"', false);
    $xpath = financeUiXpath($response->getContent());
    $form = '//dialog[@id="invoice-refund"][@x-init="$nextTick(() => $el.showModal())"]//form[@action="/admin/finance/invoices/'.$invoice->id.'/refund?tab=overview"]';
    expect($xpath->query($form.'//input[@name="_token"]'))->toHaveCount(1)
        ->and($xpath->query($form.'//input[@name="reason"][@value="Existing external evidence"][@maxlength="255"]'))->toHaveCount(1)
        ->and($xpath->query($form.'//select[@name="mode"]/option[@value="external_record"][@selected]'))->toHaveCount(1)
        ->and($xpath->query($form.'//input[@name="keep_service"][@value="1"]'))->toHaveCount(1)
        ->and($xpath->query($form.'//input[@name="external_confirm"][@value="1"]'))->toHaveCount(1)
        ->and($xpath->query($form.'//input[@name="confirm"][@required]'))->toHaveCount(1);
    $this->assertDatabaseCount('financial_transactions', 0);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::PAID);
    $other = Invoice::factory()->paid()->create();
    $this->get('/admin/finance/invoice?invoice_id='.$other->id)->assertOk()->assertDontSee('Existing external evidence');
});

it('retains topup scope reason and confirmation and restores only that failed settings form', function (): void {
    $this->actingAs(financeUiActor('finance-wallet-settings'))->from('/admin/finance')->post('/admin/finance/wallet-topup-settings', [
        'ui_form' => 'wallet-topup-settings', 'enabled' => '0', 'reason' => 'Review pending settlements',
    ])->assertRedirect('/admin/finance')->assertSessionHasErrors('confirm');
    $response = $this->get('/admin/finance')->assertOk()->assertSee('1.00–10000.00 CNY')->assertSee('充值暂不支持退款。');
    $xpath = financeUiXpath($response->getContent());
    $form = '//details[@open]/form[@action="/admin/finance/wallet-topup-settings"]';
    expect($xpath->query($form.'//input[@name="_token"]'))->toHaveCount(1)
        ->and($xpath->query($form.'//select[@name="enabled"]/option[@value="0"][@selected]'))->toHaveCount(1)
        ->and($xpath->query($form.'//input[@name="reason"][@value="Review pending settlements"][@required][@maxlength="255"]'))->toHaveCount(1)
        ->and($xpath->query($form.'//input[@name="confirm"][@required]'))->toHaveCount(1);
    expect(DB::table('audit_logs')->where('action', 'wallet.topup.configure')->count())->toBe(0);
});

it('shows an initial empty ledger without suggesting that a filter hid existing invoices', function (): void {
    $this->actingAs(financeUiActor())->get('/admin/finance')->assertOk()->assertSee('没有符合条件的账单。')
        ->assertSee('新建账单后，可在此核查支付与资金分配。')->assertDontSee('调整筛选条件或重置后重试。');
});

it('renders failed financial forms safely after a rejected array reason without changing financial state', function (string $kind): void {
    if ($kind === 'refund') {
        $invoice = Invoice::factory()->paid()->create();
        $actor = financeUiActor('finance-operator');
        $path = '/admin/finance/invoice?invoice_id='.$invoice->id.'&tab=overview';
        $postPath = '/admin/finance/invoices/'.$invoice->id.'/refund?tab=overview';
        $input = ['ui_form' => 'refund-'.$invoice->id, 'mode' => 'refund_wallet', 'confirm' => '1'];
    } else {
        $actor = financeUiActor('finance-wallet-settings');
        $path = '/admin/finance';
        $postPath = '/admin/finance/wallet-topup-settings';
        $input = ['ui_form' => 'wallet-topup-settings', 'enabled' => '0', 'confirm' => '1'];
    }
    $this->actingAs($actor)->from($path)->post($postPath, $input + ['reason' => ['INVALID-ARRAY-REASON']])
        ->assertRedirect($path)->assertSessionHasErrors('reason');
    $response = $this->get($path)->assertOk()->assertDontSee('INVALID-ARRAY-REASON');
    $xpath = financeUiXpath($response->getContent());
    $form = $kind === 'refund' ? '//dialog[@id="invoice-refund"][@x-init="$nextTick(() => $el.showModal())"]//form' : '//details[@open]/form';
    expect($xpath->query($form.'[@action="'.$postPath.'"]//input[@name="reason"][@value=""]'))->toHaveCount(1);
    $this->assertDatabaseCount('financial_transactions', 0);
    expect(DB::table('audit_logs')->whereIn('action', ['invoice.refund', 'wallet.topup.configure'])->count())->toBe(0);
})->with(['refund', 'wallet settings']);
