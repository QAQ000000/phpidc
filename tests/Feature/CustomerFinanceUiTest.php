<?php

declare(strict_types=1);

use App\Actions\ConfigureEpayAction;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Queue::fake();
    Http::preventStrayRequests();
});

function customerFinanceXpath(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

    return new DOMXPath($document);
}

function customerFinanceSetting(string $key, string $value): void
{
    [$group, $name] = explode('.', $key, 2);
    DB::table('system_settings')->updateOrInsert(['group' => $group, 'key' => $name], ['value' => $value, 'is_encrypted' => false]);
}

it('keeps filtered and paginated invoice links scoped to the customer without revealing stored private facts', function (): void {
    $owner = User::factory()->create();
    $product = Product::factory()->create(['name' => 'Customer finance cloud']);
    $records = Invoice::factory()->count(21)->paid()->create(['user_id' => $owner->id, 'product_id' => $product->id,
        'line_items' => ['encrypted_purchase_payload' => 'PRIVATE-CUSTOMER-INVOICE-SNAPSHOT']]);
    $foreign = Invoice::factory()->paid()->create(['invoice_no' => 'FOREIGN-CUSTOMER-FINANCE', 'product_id' => $product->id]);
    $response = $this->actingAs($owner)->get('/console/invoices?q=Customer&status=Paid')->assertOk()
        ->assertSee($records->last()->invoice_no)->assertDontSee($records->first()->invoice_no)
        ->assertDontSee($foreign->invoice_no)->assertDontSee('PRIVATE-CUSTOMER-INVOICE-SNAPSHOT');
    $xpath = customerFinanceXpath($response->getContent());
    expect($xpath->query('//form[@method="get"][@action="/console/invoices"]//input[@name="q"][@value="Customer"]'))->toHaveCount(1)
        ->and($xpath->query('//select[@name="status"]/option[@value="Paid"][@selected]'))->toHaveCount(1)
        ->and($xpath->query('//table[@aria-label="账单记录"]/thead/tr/th[@scope="col"]'))->toHaveCount(5)
        ->and($xpath->query('//table[@aria-label="账单记录"]/tbody/tr'))->toHaveCount(20);
    parse_str(parse_url($xpath->query('//nav[@aria-label="分页"]/a[@rel="next"]')->item(0)->getAttribute('href'), PHP_URL_QUERY), $query);
    expect($query)->toBe(['q' => 'Customer', 'status' => 'Paid', 'page' => '2']);
    $this->get('/console/invoices?'.http_build_query($query))->assertOk()->assertSee($records->first()->invoice_no)->assertDontSee($foreign->invoice_no);
    Http::assertNothingSent();
});

it('shows empty customer finance records and preserves a literal zero filter', function (): void {
    $this->actingAs(User::factory()->create())->get('/console/invoices?q=0')->assertOk()->assertSee('暂无符合条件的账单。')
        ->assertSee('name="q" value="0"', false);
    $this->get('/console/wallet')->assertOk()->assertSee('暂无符合条件的账单。')->assertSee('充值暂未开放。');
    Http::assertNothingSent();
});

it('shows exact historical allocation and escaped safe line items without exposing supplier costs or service credentials', function (): void {
    $owner = User::factory()->create();
    $instance = Instance::factory()->active()->create(['user_id' => $owner->id, 'encrypted_credentials' => ['password' => 'PRIVATE-CUSTOMER-HOST-PASSWORD']]);
    $invoice = Invoice::factory()->paid()->create(['user_id' => $owner->id, 'instance_id' => $instance->id,
        'total_amount' => '1234567890.12', 'credit_applied' => '3.00', 'gateway_amount' => '1234567887.12',
        'discount_amount' => '5.00', 'upstream_expected_cost' => '16.83', 'line_items' => [
            'items' => [['label' => '<script>privateItem()</script>', 'qty' => 1, 'unit_price' => '1234567895.12', 'amount' => '1234567895.12', 'debug' => 'PRIVATE-CUSTOMER-PAYMENT-DEBUG'],
                ['label' => '优惠券', 'qty' => 1, 'unit_price' => '-5.00', 'amount' => '-5.00']],
            'encrypted_request' => 'PRIVATE-CUSTOMER-PURCHASE-REQUEST',
        ]]);
    $original = $invoice->refresh()->getRawOriginal();
    $response = $this->actingAs($owner)->get('/console/invoices/'.$invoice->id)->assertOk()->assertSee('1234567890.12')
        ->assertSee('1234567887.12')->assertSee('3.00')->assertSee('-5.00')->assertSee('&lt;script&gt;privateItem()&lt;/script&gt;', false)
        ->assertDontSee('<script>privateItem()</script>', false)->assertDontSee('16.83')->assertDontSee('PRIVATE-CUSTOMER');
    $xpath = customerFinanceXpath($response->getContent());
    expect($xpath->query('//a[@href="/console/instances/'.$instance->id.'"]'))->toHaveCount(1)
        ->and($xpath->query('//form[@action="/console/invoices/'.$invoice->id.'/pay"]'))->toHaveCount(0)
        ->and($invoice->fresh()->getRawOriginal())->toBe($original);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('renders malformed nonsecret item values without dumping private structures', function (): void {
    $owner = User::factory()->create();
    $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'line_items' => ['items' => [
        ['label' => ['secret' => 'PRIVATE-INVALID-LABEL'], 'qty' => [], 'unit_price' => [], 'amount' => []],
    ]]]);
    $this->actingAs($owner)->get('/console/invoices/'.$invoice->id)->assertOk()->assertSee('账单项目')->assertDontSee('PRIVATE-INVALID-LABEL');
    Http::assertNothingSent();
});

it('keeps foreign invoice details payment and inconsistent linked services private after the finance redesign', function (): void {
    $owner = User::factory()->create();
    $foreign = Invoice::factory()->create(['invoice_no' => 'FOREIGN-PRIVATE-FINANCE-INVOICE']);
    $foreignInstance = Instance::factory()->active()->create(['display_name' => 'FOREIGN-PRIVATE-SERVICE']);
    $own = Invoice::factory()->create(['user_id' => $owner->id, 'instance_id' => $foreignInstance->id]);
    $this->actingAs($owner)->get('/console/invoices/'.$foreign->id)->assertForbidden()->assertDontSee($foreign->invoice_no);
    $this->post('/console/invoices/'.$foreign->id.'/pay')->assertForbidden()->assertDontSee($foreign->invoice_no);
    $response = $this->get('/console/invoices/'.$own->id)->assertOk()->assertDontSee($foreignInstance->display_name);
    expect(customerFinanceXpath($response->getContent())->query('//a[@href="/console/instances/'.$foreignInstance->id.'"]'))->toHaveCount(0);
    $this->assertDatabaseCount('payment_attempts', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
    Http::assertNothingSent();
});

it('retains the exact payable conditions and owner POST with CSRF for continued payment', function (string $state, bool $payable): void {
    $owner = User::factory()->create();
    $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'gateway' => 'epay_wechat',
        'status' => $state === 'paid' ? InvoiceStatus::PAID : ($state === 'expired' ? InvoiceStatus::EXPIRED : InvoiceStatus::UNPAID),
        'expires_at' => match ($state) {
            'no deadline' => null, 'past deadline' => now()->subMinute(), default => now()->addHour()
        }]);
    if ($state === 'disabled channel') {
        customerFinanceSetting('payment.epay_wechat_enabled', '0');
    }
    $original = $invoice->refresh()->getRawOriginal();
    $response = $this->actingAs($owner)->get('/console/invoices/'.$invoice->id)->assertOk();
    $xpath = customerFinanceXpath($response->getContent());
    expect($xpath->query('//form[@method="post"][@action="/console/invoices/'.$invoice->id.'/pay"]//input[@name="_token"]'))->toHaveCount($payable ? 1 : 0)
        ->and($invoice->fresh()->getRawOriginal())->toBe($original);
    $this->assertDatabaseCount('payment_attempts', 0);
    Http::assertNothingSent();
})->with([['unpaid', true], ['no deadline', true], ['paid', false], ['expired', false], ['past deadline', false], ['disabled channel', false]]);

it('retains automatic renewal allocation fields instead of offering a direct payment shortcut', function (): void {
    $owner = User::factory()->create();
    $instance = Instance::factory()->active()->create(['user_id' => $owner->id]);
    $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'instance_id' => $instance->id, 'type' => InvoiceType::RENEW,
        'gateway' => 'epay_wechat', 'line_items' => ['automatic_renewal' => ['selection_pending' => true]]]);
    $response = $this->actingAs($owner)->get('/console/invoices/'.$invoice->id)->assertOk()->assertSee('确认续费付款方式');
    $xpath = customerFinanceXpath($response->getContent());
    $form = '//form[@method="post"][@action="/console/instances/'.$instance->id.'/operations"]';
    expect($xpath->query($form.'//input[@name="_token"]'))->toHaveCount(1)
        ->and($xpath->query($form.'//input[@name="action"][@value="renew"]'))->toHaveCount(1)
        ->and($xpath->query($form.'//input[@name="confirm"][@value="1"][@type="hidden"]'))->toHaveCount(1)
        ->and($xpath->query($form.'//input[@name="use_credit"][@value="1"]'))->toHaveCount(1)
        ->and($xpath->query($form.'//select[@name="gateway"]'))->toHaveCount(1)
        ->and($xpath->query('//form[@action="/console/invoices/'.$invoice->id.'/pay"]'))->toHaveCount(0)
        ->and(Str::isUlid($xpath->query($form.'//input[@name="request_id"]')->item(0)->getAttribute('value')))->toBeTrue();
    Http::assertNothingSent();
});

it('preserves owner wallet balances and the real topup request contract without adding a reason or confirmation', function (): void {
    customerFinanceSetting('wallet.topup_enabled', '1');
    app(ConfigureEpayAction::class)->execute('https://customer-finance-pay.test', 'merchant', 'PRIVATE-FINANCE-KEY', 'Isolated finance UI gateway');
    $owner = User::factory()->create(['credit' => '12.34', 'frozen_credit' => '5.67', 'wallet_clearance_frozen' => '8.90']);
    Invoice::factory()->paid()->create(['type' => InvoiceType::WALLET_TOPUP, 'invoice_no' => 'FOREIGN-TOPUP-ONLY']);
    $response = $this->actingAs($owner)->get('/console/wallet')->assertOk()->assertSee('12.34')->assertSee('5.67')->assertSee('8.90')
        ->assertSee('1.00–10000.00 元')->assertDontSee('FOREIGN-TOPUP-ONLY')->assertDontSee('PRIVATE-FINANCE-KEY');
    $xpath = customerFinanceXpath($response->getContent());
    $form = '//form[@method="post"][@action="/console/wallet/topups"]';
    $request = $xpath->query($form.'//input[@name="request_id"]')->item(0)->getAttribute('value');
    expect(Str::isUlid($request))->toBeTrue()->and($xpath->query($form.'//input[@name="_token"]'))->toHaveCount(1)
        ->and($xpath->query($form.'//input[@name="amount"][@inputmode="decimal"][@maxlength="13"][@required]'))->toHaveCount(1)
        ->and($xpath->query($form.'//input[@name="reason" or @name="confirm" or @name="use_credit"]'))->toHaveCount(0);
    $this->post('/console/wallet/topups', ['request_id' => $request, 'amount' => '10.00', 'gateway' => 'epay_wechat',
        'user_id' => 99999, 'total_amount' => '0.01', 'credit_applied' => '10.00'])->assertRedirect();
    $created = Invoice::where('user_id', $owner->id)->sole();
    expect($created->type)->toBe(InvoiceType::WALLET_TOPUP)->and($created->total_amount)->toBe('10.00')
        ->and($created->credit_applied)->toBe('0.00')->and($created->gateway_amount)->toBe('10.00')
        ->and($owner->fresh()->only(['credit', 'frozen_credit', 'wallet_clearance_frozen']))->toBe(['credit' => '12.34', 'frozen_credit' => '5.67', 'wallet_clearance_frozen' => '8.90']);
    $this->assertDatabaseCount('financial_transactions', 0);
    Http::assertNothingSent();
});

it('renders rejected wallet inputs safely and retains the original amount limits', function (array|string $amount): void {
    customerFinanceSetting('wallet.topup_enabled', '1');
    $owner = User::factory()->create();
    $request = (string) Str::ulid();
    $this->actingAs($owner)->from('/console/wallet')->post('/console/wallet/topups', [
        'request_id' => $request, 'amount' => $amount, 'gateway' => 'epay_wechat',
    ])->assertRedirect('/console/wallet')->assertSessionHasErrors('amount');
    $response = $this->get('/console/wallet')->assertOk()->assertSee('role="alert"', false)->assertDontSee('PRIVATE-ARRAY-AMOUNT');
    $xpath = customerFinanceXpath($response->getContent());
    expect($xpath->query('//form[@action="/console/wallet/topups"]//input[@name="request_id"][@value="'.$request.'"]'))->toHaveCount(1);
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
    Http::assertNothingSent();
})->with([['0.99'], ['10000.01'], [['PRIVATE-ARRAY-AMOUNT']]]);

it('keeps credited topups distinct from service delivery and never provides a refund or delivery action', function (): void {
    $owner = User::factory()->create();
    $invoice = Invoice::factory()->paid()->create(['user_id' => $owner->id, 'type' => InvoiceType::WALLET_TOPUP, 'product_id' => null]);
    $this->actingAs($owner)->get('/console/invoices/'.$invoice->id)->assertOk()->assertSee('充值已到账')
        ->assertSee('已到账')->assertSee('暂不支持充值退款')->assertDontSee('交付信息等待确认')->assertDontSee('领取卡密')->assertDontSee('历史商品');
    Http::assertNothingSent();
});

it('shows only safe payment links and preserves external allocation without treating initiation as settlement', function (bool $success): void {
    app(ConfigureEpayAction::class)->execute('https://customer-finance-pay.test', 'merchant', 'PRIVATE-FINANCE-KEY', 'Isolated finance UI gateway');
    $owner = User::factory()->create(['credit' => '9.00', 'frozen_credit' => '3.00']);
    $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'gateway' => 'epay_wechat',
        'credit_applied' => '3.00', 'gateway_amount' => '7.00', 'total_amount' => '10.00']);
    $original = $invoice->refresh()->getRawOriginal();
    Http::fake(['https://customer-finance-pay.test/mapi.php' => Http::response($success
        ? ['code' => 1, 'payurl' => 'https://customer-finance-pay.test/pay/safe-order', 'qrcode' => 'https://customer-finance-pay.test/qr/safe-order']
        : ['code' => -1, 'msg' => 'PRIVATE-PROVIDER-FAILURE'])]);
    $response = $this->actingAs($owner)->post('/console/invoices/'.$invoice->id.'/pay')->assertOk()->assertSee('7.00')
        ->assertDontSee('PRIVATE-FINANCE-KEY')->assertDontSee('PRIVATE-PROVIDER-FAILURE');
    $xpath = customerFinanceXpath($response->getContent());
    expect($xpath->query('//a[@href="/console/invoices/'.$invoice->id.'"]'))->toHaveCount(1);
    if ($success) {
        expect($xpath->query('//a[@href="https://customer-finance-pay.test/pay/safe-order"][@rel="noreferrer"]'))->toHaveCount(1)
            ->and($xpath->query('//a[@href="https://customer-finance-pay.test/qr/safe-order"][@rel="noreferrer"]'))->toHaveCount(1);
        $response->assertSee('支付已发起');
    } else {
        $response->assertSee('暂时无法发起支付')->assertSee('role="alert"', false);
    }
    Http::assertSent(fn ($request): bool => $request['money'] === '7.00');
    Http::assertSentCount(1);
    expect(PaymentAttempt::where('invoice_id', $invoice->id)->sole()->amount)->toBe('7.00')
        ->and($invoice->fresh()->getRawOriginal())->toBe($original)
        ->and($owner->fresh()->only(['credit', 'frozen_credit']))->toBe(['credit' => '9.00', 'frozen_credit' => '3.00']);
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('payment_callbacks', 0);
    Queue::assertNothingPushed();
})->with([true, false]);
