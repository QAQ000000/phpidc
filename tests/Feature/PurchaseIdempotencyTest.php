<?php

declare(strict_types=1);

use App\Actions\ClaimCouponAction;
use App\Actions\CreateComboInvoiceAction;
use App\Actions\ExpireUnpaidInvoicesAction;
use App\Actions\ReencryptSecretsAction;
use App\Contracts\FulfillmentDriver;
use App\Contracts\LiveQuotable;
use App\Data\QuoteResult;
use App\Drivers\SelfHosted\RedemptionCodeDriver;
use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\RedemptionCode;
use App\Models\User;
use App\Services\DriverManager;
use App\Services\SettingService;
use App\Support\InstallationState;
use App\Support\PurchaseRequest;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Queue::fake();
    Http::preventStrayRequests();
});

function purchaseFixture(string $credit = '100.00', string $price = '10.00'): array
{
    return [User::factory()->create(['credit' => $credit]),
        Product::factory()->create(['local_prices' => ['onetime' => ['price' => $price, 'enabled' => true]]]),
        ['request_id' => (string) Str::ulid()]];
}

it('replays a lost purchase response without repeating discounts wallet changes or delivery', function (string $credit, string $price, bool $wallet, string $balance, string $frozen, string $gateway, int $tasks, int $ledger): void {
    [$user, $product, $input] = purchaseFixture($credit, $price);
    $coupon = Coupon::create(['code' => 'ONCE', 'type' => 'fixed', 'value' => '1.00', 'min_order_amount' => '0.00',
        'applies_to' => 'new_order', 'total_quota' => 1, 'per_user_limit' => 1, 'is_active' => true]);
    app(ClaimCouponAction::class)->execute($user, $coupon->code);
    $input['coupon_code'] = $coupon->code;
    $input['password'] = 'private-checkout-password';
    $action = app(CreateComboInvoiceAction::class);
    $invoice = $action->execute($user, $product, BillingCycle::ONETIME, [], $wallet, 'epay_wechat', $input);
    $before = $invoice->fresh()->getRawOriginal();
    // A retry must not depend on current catalog, eligibility or coupon availability.
    $product->update(['is_hidden' => true, 'currency' => 'USD', 'require_kyc' => true,
        'local_prices' => [], 'provisioning_rules' => ['hostname_mode' => 'custom']]);
    $coupon->update(['is_active' => false]);
    $again = $action->execute($user, $product, BillingCycle::ONETIME, [], $wallet, 'epay_wechat', $input);
    expect($again->id)->toBe($invoice->id)->and($again->getRawOriginal())->toBe($before)
        ->and($user->fresh()->credit)->toBe($balance)->and($user->fresh()->frozen_credit)->toBe($frozen)
        ->and($again->gateway_amount)->toBe($gateway)->and($coupon->fresh()->used_count)->toBe(1)
        ->and($invoice->purchase_request_id)->toBe($input['request_id']);
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseCount('coupon_usages', 1);
    $this->assertDatabaseCount('financial_transactions', $ledger);
    $this->assertDatabaseCount('instances', $tasks);
    $this->assertDatabaseCount('fulfillment_tasks', $tasks);
    $this->assertDatabaseCount('payment_attempts', 0);
    expect(DB::table('invoices')->value('line_items'))->not->toContain('private-checkout-password');
    Http::assertNothingSent();
})->with([
    'wallet' => ['100.00', '10.00', true, '91.00', '0.00', '0.00', 1, 2],
    'combo' => ['3.00', '10.00', true, '0.00', '3.00', '6.00', 0, 1],
    'gateway' => ['100.00', '10.00', false, '100.00', '0.00', '9.00', 0, 0],
    'free' => ['0.00', '1.00', true, '0.00', '0.00', '0.00', 1, 1],
]);

it('rejects a reused purchase key with changed caller intent before any financial or upstream work', function (array $changes): void {
    [$user, $product, $input] = purchaseFixture();
    $payload = $input + ['product_id' => $product->id, 'billing_cycle' => 'onetime', 'use_credit' => true,
        'gateway' => 'epay_wechat', 'hostname' => 'node', 'password' => 'private-password'];
    $this->actingAs($user)->postJson('/checkout', $payload)->assertCreated();
    $this->postJson('/checkout', array_replace($payload, $changes))->assertUnprocessable()->assertJsonValidationErrors('request_id');
    expect($user->fresh()->credit)->toBe('90.00')->and($user->fresh()->frozen_credit)->toBe('0.00');
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseCount('fulfillment_tasks', 1);
    $this->assertDatabaseCount('financial_transactions', 2);
    Http::assertNothingSent();
})->with([
    'product' => [['product_id' => 999999]], 'cycle' => [['billing_cycle' => 'monthly']],
    'configuration' => [['options' => ['configoption' => [50 => 501]]]],
    'private custom field' => [['options' => ['customfield' => [80 => 'private-field']]]],
    'coupon' => [['coupon_code' => 'ANOTHER']], 'wallet' => [['use_credit' => false]],
    'gateway' => [['gateway' => 'epay_alipay']], 'hostname' => [['hostname' => 'another-node']],
    'password' => [['password' => 'changed-secret']],
]);

it('keeps terminal purchase invoices terminal and never freezes returned credit again', function (InvoiceStatus $status): void {
    [$user, $product, $input] = purchaseFixture('3.00');
    $action = app(CreateComboInvoiceAction::class);
    $invoice = $action->execute($user, $product, BillingCycle::ONETIME, [], true, 'epay_wechat', $input);
    $invoice->update(['expires_at' => now()->subMinute()]);
    app(ExpireUnpaidInvoicesAction::class)->execute();
    $invoice->update(['status' => $status]);
    $before = $invoice->fresh()->getRawOriginal();
    expect($action->execute($user, $product, BillingCycle::ONETIME, [], true, 'epay_wechat', $input)->getRawOriginal())->toBe($before)
        ->and($user->fresh()->credit)->toBe('3.00')->and($user->fresh()->frozen_credit)->toBe('0.00');
    $this->assertDatabaseCount('financial_transactions', 2);
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseCount('fulfillment_tasks', 0);
})->with([InvoiceStatus::EXPIRED, InvoiceStatus::CANCELLED, InvoiceStatus::REFUNDED]);

it('scopes purchase keys to the owner and permits an explicit new purchase', function (): void {
    [$user, $product, $input] = purchaseFixture();
    $other = User::factory()->create(['credit' => '100.00']);
    $action = app(CreateComboInvoiceAction::class);
    $first = $action->execute($user, $product, BillingCycle::ONETIME, [], true, input: $input);
    $second = $action->execute($other, $product, BillingCycle::ONETIME, [], true, input: $input);
    $third = $action->execute($user, $product, BillingCycle::ONETIME, [], true, input: ['request_id' => (string) Str::ulid()]);
    expect($second->id)->not->toBe($first->id)->and($third->id)->not->toBe($first->id)
        ->and($user->fresh()->credit)->toBe('80.00')->and($other->fresh()->credit)->toBe('90.00');
    $this->assertDatabaseCount('invoices', 3);
    $this->assertDatabaseCount('fulfillment_tasks', 3);
    $this->actingAs($other)->get('/console/invoices/'.$first->id)->assertForbidden();
});

it('requires a caller purchase ULID in both HTTP and direct Action without generating a replacement', function (array $input): void {
    [$user, $product] = purchaseFixture();
    expect(fn () => app(CreateComboInvoiceAction::class)->execute($user, $product, BillingCycle::ONETIME, [], true, input: $input))
        ->toThrow(ValidationException::class);
    $this->actingAs($user)->postJson('/checkout', $input + ['product_id' => $product->id])->assertUnprocessable()->assertJsonValidationErrors('request_id');
    $this->assertDatabaseCount('invoices', 0);
    expect($user->fresh()->credit)->toBe('100.00');
    Http::assertNothingSent();
})->with(['missing' => [[]], 'invalid' => [['request_id' => 'wrong']], 'array' => [['request_id' => []]]]);

it('returns the stored HTTP invoice before current product validation and ignores client amounts', function (): void {
    [$user, $product, $input] = purchaseFixture();
    $payload = $input + ['product_id' => $product->id, 'use_credit' => true, 'gateway' => 'epay_wechat'];
    $first = $this->actingAs($user)->postJson('/checkout', $payload)->assertCreated();
    $product->update(['is_hidden' => true, 'provisioning_rules' => ['hostname_mode' => 'custom']]);
    $again = $this->postJson('/checkout', $payload + ['total_amount' => '0.01', 'credit_applied' => '0.01'])->assertCreated()
        ->assertJsonPath('invoice_id', $first->json('invoice_id'))->assertJsonPath('total_amount', '10.00');
    expect($again->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseCount('financial_transactions', 2);
});

it('canonicalizes configuration maps without storing credentials or accepting unsupported shapes', function (): void {
    $request = app(PurchaseRequest::class);
    $a = $request->snapshot(1, BillingCycle::MONTHLY, ['configoption' => [55 => '0', 50 => '501'], 'customfield' => [81 => null, 80 => 'private']], true, '', ['hostname' => '', 'password' => 'secret']);
    $b = $request->snapshot(1, BillingCycle::MONTHLY, ['customfield' => [80 => 'private', 81 => ''], 'configoption' => [50 => 501, 55 => 0]], true, null, ['password' => 'secret']);
    expect($a)->toBe($b);
    expect($request->snapshot(1, BillingCycle::ONETIME, [], false, null, []))
        ->toBe($request->snapshot(1, BillingCycle::ONETIME, ['configoption' => [], 'customfield' => []], false, '', []));
    foreach ([['unexpected' => 1], ['configoption' => [50 => true]], ['configoption' => [50 => '1.5']],
        ['customfield' => [80 => ['secret']]], ['customfield' => ['name' => 'value']]] as $options) {
        expect(fn () => $request->snapshot(1, BillingCycle::MONTHLY, $options, true, null, []))->toThrow(ValidationException::class);
    }
});

it('rechecks the persisted purchase under the user lock after an in-flight live quote', function (): void {
    [$user, $product, $input] = purchaseFixture();
    $driver = Mockery::mock(FulfillmentDriver::class.', '.LiveQuotable::class);
    $driver->shouldReceive('validateParams')->once()->andReturn([]);
    $winner = null;
    $level = DB::transactionLevel();
    $driver->shouldReceive('quotePurchase')->once()->andReturnUsing(function () use ($user, $product, $input, &$winner, $level): QuoteResult {
        expect(DB::transactionLevel())->toBe($level);
        // Another worker commits while this request is waiting on its quote.
        $winner = app(CreateComboInvoiceAction::class)->execute($user, $product, BillingCycle::ONETIME, [], true, input: $input);

        return new QuoteResult('0.00', '99.00');
    });
    $manager = Mockery::mock(DriverManager::class);
    $manager->shouldReceive('fulfillment')->once()->andReturn($driver);
    $manager->shouldReceive('fulfillment')->andReturn(new RedemptionCodeDriver);
    $action = new CreateComboInvoiceAction($manager, app(SettingService::class));
    $invoice = $action->execute($user, $product, BillingCycle::ONETIME, [], true, input: $input);
    expect($invoice->id)->toBe($winner->id)->and($invoice->total_amount)->toBe('10.00')->and($user->fresh()->credit)->toBe('90.00');
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseCount('fulfillment_tasks', 1);
});

it('replays normalized private configuration without contacting a live driver or regenerating credentials', function (): void {
    [$user, $product, $input] = purchaseFixture();
    $driver = Mockery::mock(FulfillmentDriver::class.', '.LiveQuotable::class);
    $driver->shouldReceive('validateParams')->once()->andReturn(['password' => 'generated-private-password']);
    $driver->shouldReceive('quotePurchase')->once()->andReturn(new QuoteResult('8.00', '10.00'));
    $manager = Mockery::mock(DriverManager::class);
    $manager->shouldReceive('fulfillment')->twice()->andReturn($driver);
    $action = new CreateComboInvoiceAction($manager, app(SettingService::class));
    $invoice = $action->execute($user, $product, BillingCycle::MONTHLY,
        ['configoption' => [55 => '0', 50 => '501'], 'customfield' => [81 => null, 80 => 'private-option']], true, input: $input);
    $product->update(['is_hidden' => true, 'provisioning_rules' => ['hostname_mode' => 'custom']]);
    $again = $action->execute($user, $product, BillingCycle::MONTHLY,
        ['customfield' => [80 => 'private-option', 81 => ''], 'configoption' => [50 => 501, 55 => 0]], true,
        input: ['request_id' => strtolower($input['request_id'])]);
    expect($again->id)->toBe($invoice->id)->and($user->fresh()->credit)->toBe('90.00');
    $saved = DB::table('invoices')->value('line_items');
    expect($saved)->not->toContain('private-option', 'generated-private-password');
    $intent = Crypt::decryptString($again->line_items['purchase']['encrypted_request']);
    expect($intent)->toContain('private-option')->not->toContain('generated-private-password');
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseCount('fulfillment_tasks', 1);
    Http::assertNothingSent();
});

it('rejects duplicate persisted keys while retaining nullable legacy invoice keys', function (): void {
    [$user, $product, $input] = purchaseFixture();
    $invoice = app(CreateComboInvoiceAction::class)->execute($user, $product, BillingCycle::ONETIME, [], true, input: $input);
    Invoice::factory()->count(2)->create(['user_id' => $user->id, 'purchase_request_id' => null]);
    expect(Invoice::where('user_id', $user->id)->whereNull('purchase_request_id')->count())->toBe(2);
    // Savepoint lets the test continue under both SQLite and MySQL constraint errors.
    expect(fn () => DB::transaction(fn () => Invoice::factory()->create(['user_id' => $user->id, 'purchase_request_id' => $invoice->purchase_request_id])))
        ->toThrow(QueryException::class);
    $this->assertDatabaseCount('invoices', 3);
});

it('fails visibly on corrupt purchase identity and preserves the original money and invoice', function (): void {
    [$user, $product, $input] = purchaseFixture();
    $action = app(CreateComboInvoiceAction::class);
    $invoice = $action->execute($user, $product, BillingCycle::ONETIME, [], true, input: $input);
    $items = $invoice->line_items;
    $items['purchase']['encrypted_request'] = 'corrupt';
    $invoice->update(['line_items' => $items]);
    expect(fn () => $action->execute($user, $product, BillingCycle::ONETIME, [], true, input: $input))->toThrow(DecryptException::class);
    expect($user->fresh()->credit)->toBe('90.00');
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseCount('financial_transactions', 2);
});

it('preserves purchase replay after rotating the encrypted intent to a new application key', function (): void {
    [$user, $product, $input] = purchaseFixture();
    $action = app(CreateComboInvoiceAction::class);
    $invoice = $action->execute($user, $product, BillingCycle::ONETIME, [], true, input: $input + ['password' => 'rotate-secret']);
    $old = config('app.key');
    $new = 'base64:'.base64_encode(random_bytes(32));
    $state = app(InstallationState::class);
    $state->write('installed.lock', $state->seal(['format' => 1, 'admin_id' => 1], $old));
    File::put(storage_path('framework/down'), '{}');
    $before = $invoice->line_items['purchase']['encrypted_request'];
    app(ReencryptSecretsAction::class)->execute($old, $new, 'Isolated purchase identity rotation', true);
    $encrypter = $state->encrypter($new);
    $saved = $invoice->fresh()->line_items['purchase']['encrypted_request'];
    expect($saved)->not->toBe($before)->and($encrypter->decryptString($saved))->toContain('rotate-secret');
    $previousEncrypter = app('encrypter');
    try {
        config(['app.key' => $new]);
        app()->instance('encrypter', $encrypter);
        Crypt::clearResolvedInstance('encrypter');
        expect($action->execute($user, $product, BillingCycle::ONETIME, [], true, input: $input + ['password' => 'rotate-secret'])->id)->toBe($invoice->id)
            ->and($user->fresh()->credit)->toBe('90.00');
    } finally {
        config(['app.key' => $old]);
        app()->instance('encrypter', $previousEncrypter);
        Crypt::clearResolvedInstance('encrypter');
    }
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseCount('financial_transactions', 2);
});

function purchaseFormKey(string $html): string
{
    preg_match('/name="request_id" value="([^"]+)"/', $html, $match);
    expect(Str::isUlid($match[1] ?? ''))->toBeTrue();

    return $match[1];
}

it('gives each purchase form its own key and keeps the failed product key on redisplay', function (): void {
    [$user, $product] = purchaseFixture();
    Product::factory()->create(['local_prices' => ['onetime' => ['price' => '2.00', 'enabled' => true]]]);
    RedemptionCode::factory()->create(['product_id' => $product->id]);
    $this->actingAs($user);
    $console = $this->get('/console')->assertOk();
    $key = purchaseFormKey($console->getContent());
    preg_match_all('/name="request_id" value="([^"]+)"/', $console->getContent(), $keys);
    expect($keys[1])->toHaveCount(2)->and(array_unique($keys[1]))->toHaveCount(2);
    expect(purchaseFormKey($this->get('/console')->assertOk()->getContent()))->not->toBe($key);
    $public = $this->get($product->publicPath())->assertOk();
    $publicKey = purchaseFormKey($public->getContent());
    expect($public->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $configured = $this->get('/console/products/'.$product->id.'/configure')->assertOk();
    expect(purchaseFormKey($configured->getContent()))->not->toBe($publicKey);
    $product->update(['provisioning_rules' => ['hostname_mode' => 'custom']]);
    $this->from($product->publicPath())->post('/checkout', ['product_id' => $product->id, 'request_id' => $publicKey, 'password' => 'not-flashed'])
        ->assertRedirect($product->publicPath())->assertSessionHasErrors('hostname');
    $again = $this->get($product->publicPath())->assertOk();
    expect(purchaseFormKey($again->getContent()))->toBe($publicKey);
    expect(session()->getOldInput('password'))->toBeNull();
});

it('keeps the purchase key through HTML and JSON local quotation without creating an invoice', function (): void {
    [$user, $product, $input] = purchaseFixture();
    $product->update(['local_prices' => ['onetime' => ['price' => '10.00', 'setup_fee' => '0.00', 'enabled' => true]]]);
    $driver = Mockery::mock(FulfillmentDriver::class.', '.LiveQuotable::class);
    $driver->shouldNotReceive('quotePurchase');
    $manager = Mockery::mock(DriverManager::class);
    $manager->shouldReceive('fulfillment')->andReturn($driver);
    app()->instance(DriverManager::class, $manager);
    $this->actingAs($user);
    $path = '/console/products/'.$product->id.'/quote';
    $payload = $input + ['billing_cycle' => 'onetime'];
    $html = $this->post($path, $payload)->assertOk();
    expect(purchaseFormKey($html->getContent()))->toBe($input['request_id']);
    $this->postJson($path, $payload)->assertOk()->assertJsonPath('price', '10.00');
    $this->assertDatabaseCount('invoices', 0);
});
