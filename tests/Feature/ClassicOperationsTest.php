<?php

declare(strict_types=1);

use App\Actions\ConfigureClassicUpstreamAction;
use App\Models\Instance;
use App\Models\Product;
use App\Models\UpstreamApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

it('encrypts classic API secrets and maps period prices without rebinding existing instances', function (): void {
    $this->artisan('upstream:classic-configure', ['name' => 'Supplier', 'url' => 'https://classic.test',
        'username' => 'supplier@example.test', '--reason' => 'Initial setup'])
        ->expectsQuestion('Classic client-center API secret', 'PRIVATE-API-SECRET')->assertSuccessful();
    $account = UpstreamApi::sole();
    expect($account->encrypted_secret)->toBe('PRIVATE-API-SECRET')->and($account->toArray())->not->toHaveKey('encrypted_secret')
        ->and(DB::table('upstream_apis')->value('encrypted_secret'))->not->toContain('PRIVATE-API-SECRET')
        ->and(DB::table('audit_logs')->value('after'))->not->toContain('PRIVATE-API-SECRET');
    Http::preventStrayRequests();
    Http::fake(['*/zjmf_api_login' => Http::response(['status' => 200, 'jwt' => 'jwt']),
        '*/cart/get_product_config*' => Http::response(['status' => 200, 'data' => ['products' => ['name' => 'Cloud'],
            'product_pricings' => [['code' => 'CNY', 'monthly' => '500.00', 'msetupfee' => '8.00']]]])]);
    $args = ['account' => $account->id, 'pid' => '17', 'cycle' => 'monthly', '--reason' => 'Import supplier product'];
    $this->artisan('upstream:classic-import', $args)->assertSuccessful();
    $product = Product::sole();
    $instance = Instance::factory()->create(['product_id' => $product->id, 'upstream_api_id' => $account->id]);
    $before = $instance->fresh()->getRawOriginal();
    $this->artisan('upstream:classic-import', $args)->assertSuccessful();
    expect(Product::count())->toBe(1)->and($product->upstream_base_prices['monthly']['price'])->toBe('8.00')
        ->and($product->local_prices['monthly']['price'])->toBe('9.60')->and($instance->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseHas('audit_logs', ['action' => 'upstream.classic_product_import', 'reason' => 'Import supplier product']);
});

it('requires an explicit reason before configuring an upstream account', function (): void {
    expect(fn () => app(ConfigureClassicUpstreamAction::class)->execute('Supplier', 'https://classic.test', 'user', 'secret', ''))
        ->toThrow(DomainException::class);
    $this->assertDatabaseCount('upstream_apis', 0);
});
