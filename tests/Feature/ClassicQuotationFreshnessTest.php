<?php

declare(strict_types=1);

use App\Enums\DriverType;
use App\Models\Product;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Services\DriverManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

it('keeps currency price and availability live while quoting without changing customer funds', function (): void {
    $this->installApplicationForTest();
    $user = User::factory()->create(['credit' => '100.00']);
    $account = UpstreamApi::factory()->create();
    $product = Product::factory()->create(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id,
        'upstream_pid' => '17', 'markup_percent' => '20.00',
        'upstream_base_prices' => ['monthly' => ['price' => '8.00', 'setup_fee' => '0.00', 'enabled' => true]]]);
    $state = (object) ['currency' => 1, 'total' => '8.00', 'setup' => '0.00', 'hidden' => 0, 'code' => 'CNY',
        'users' => 0, 'stocks' => 0, 'quotedCurrencies' => []];
    $level = DB::transactionLevel();
    Http::preventStrayRequests();
    Http::fake(function ($request) use ($state, $level) {
        expect(DB::transactionLevel())->toBe($level);
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/user_info') {
            $state->users++;

            return Http::response(['status' => 200, 'user' => ['currency' => $state->currency]]);
        }
        if ($path === '/cart/stock_control') {
            $state->stocks++;

            return Http::response(['status' => 200, 'data' => ['product' => ['hidden' => $state->hidden, 'stock_control' => 1, 'qty' => 10]]]);
        }
        if ($path === '/cart/get_total') {
            $state->quotedCurrencies[] = $request['currencyid'];

            return Http::response(['status' => 200, 'products' => ['total' => $state->total, 'setupfee_total' => $state->setup],
                'currency' => ['code' => $state->code]]);
        }

        return match ($path) {
            '/zjmf_api_login' => Http::response(['status' => 200, 'jwt' => 'quote-jwt']),
            default => throw new RuntimeException('Unexpected quote request: '.$path),
        };
    });
    // Interactive previews use local estimates. Preserve live freshness at the
    // driver boundary used again when the customer submits an order.
    $driver = app(DriverManager::class)->classicAccount($account->id);
    $first = $driver->quotePurchase($product, 'monthly', []);
    expect($first->downstreamPrice)->toBe('9.60')->and($first->setupFee)->toBe('0.00');
    $state->currency = 2;
    $state->total = '21.00';
    $state->setup = '1.00';
    $second = $driver->quotePurchase($product, 'monthly', []);
    expect($second->downstreamPrice)->toBe('24.00')->and($second->setupFee)->toBe('1.20');
    expect($state->quotedCurrencies)->toBe([1, 2]);

    $state->hidden = 1;
    try {
        $driver->quotePurchase($product, 'monthly', []);
        $this->fail('An unavailable upstream product must reject a live quote.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('product_id');
    }
    expect($state->quotedCurrencies)->toBe([1, 2]);

    $state->hidden = 0;
    $state->code = 'USD';
    try {
        $driver->quotePurchase($product, 'monthly', []);
        $this->fail('A changed upstream currency must reject a live quote.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('currency');
    }
    expect($state->users)->toBe(4)->and($state->stocks)->toBe(4)
        ->and($state->quotedCurrencies)->toBe([1, 2, 2])
        ->and($user->fresh()->credit)->toBe('100.00')->and($user->fresh()->frozen_credit)->toBe('0.00');
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
    Http::assertNotSent(fn ($request) => in_array(parse_url($request->url(), PHP_URL_PATH), ['/cart/add_to_shop', '/cart/settle', '/apply_credit'], true));
});
