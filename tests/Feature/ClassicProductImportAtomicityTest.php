<?php

declare(strict_types=1);

use App\Actions\AssignUpstreamRoleAction;
use App\Actions\ImportClassicProductAction;
use App\Enums\BillingCycle;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductCustomField;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionItem;
use App\Models\UpstreamApi;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Http::preventStrayRequests();
});

function atomicClassicCatalog(int $type = 1): array
{
    return ['products' => ['name' => 'Complete cloud', 'p_uid' => '0', 'location_version' => 7, 'stock_control' => 1, 'qty' => 0],
        'product_pricings' => [['code' => 'CNY', 'monthly' => '8.00', 'msetupfee' => '2.00', 'annually' => '80.00', 'asetupfee' => '3.00']],
        'config_groups' => [['id' => 5, 'options' => [['id' => 50, 'option_type' => $type, 'option_name' => 'size|规格',
            'sub' => [['id' => 501, 'option_name' => 'small|小型', 'pricings' => [['code' => 'CNY', 'monthly' => '1.00', 'msetupfee' => '0.00']]]]]]]],
        'customfields' => [['id' => 80, 'fieldname' => 'Site', 'fieldtype' => 'text', 'showorder' => 1, 'showdetail' => 1]], 'advanced' => []];
}

function fakeAtomicClassicCatalog(array $catalog, array &$levels): stdClass
{
    $state = (object) ['catalog' => $catalog];
    Http::fake(function ($request) use ($state, &$levels) {
        $levels[] = DB::transactionLevel();

        return Http::response(match (parse_url($request->url(), PHP_URL_PATH)) {
            '/zjmf_api_login' => ['status' => 200, 'jwt' => 'atomic-test-jwt'],
            '/cart/get_product_config' => ['status' => 200, 'data' => $state->catalog],
            default => throw new RuntimeException('Unexpected import request.'),
        });
    });

    return $state;
}

it('publishes a complete import with one remote configuration read outside its write transaction', function (): void {
    $account = UpstreamApi::factory()->create();
    $levels = [];
    $level = DB::transactionLevel();
    $state = fakeAtomicClassicCatalog(atomicClassicCatalog(), $levels);

    $product = app(ImportClassicProductAction::class)->execute($account->id, '17', BillingCycle::MONTHLY, 'CNY', '20.00', 'Atomic import');

    expect($levels)->not->toBeEmpty()->each->toBe($level);
    expect($product->is_hidden)->toBeFalse()
        ->and(Product::publicCatalog()->whereKey($product->id)->exists())->toBeTrue()
        ->and($product->upstream_version)->toBe(7)
        ->and($product->provisioning_rules['classic_catalog']['product_publisher_uid'])->toBe(0)
        ->and($product->local_prices['monthly'])->toBe(['price' => '9.60', 'setup_fee' => '2.40', 'enabled' => true])
        ->and($product->local_prices['annually']['price'])->toBe('96.00')
        ->and($product->stock_control)->toBeTrue()->and($product->qty)->toBe(0)
        ->and($product->optionGroups()->count())->toBe(1)->and($product->customFields()->count())->toBe(1);
    Http::assertSentCount(2);
    $this->assertDatabaseHas('audit_logs', ['action' => 'upstream.classic_product_import', 'subject_id' => $product->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'upstream.catalog_sync', 'subject_id' => $product->id]);
});

it('rolls back new imports through every entry point when full configuration is unsupported', function (string $entry): void {
    $account = UpstreamApi::factory()->create();
    $levels = [];
    fakeAtomicClassicCatalog(atomicClassicCatalog(20), $levels);

    if ($entry === 'http') {
        $actor = User::factory()->create();
        app(AssignUpstreamRoleAction::class)->execute($actor, 'upstream-operator', 'Import test role');
        $this->actingAs($actor)->post('/admin/upstreams/import', ['account_id' => $account->id, 'pid' => 17,
            'cycle' => 'monthly', 'currency' => 'CNY', 'markup' => '20.00'])->assertSessionHasErrors('operation');
    } elseif ($entry === 'cli') {
        expect(fn () => $this->artisan('upstream:classic-import', ['account' => $account->id, 'pid' => '17', '--reason' => 'Unsupported import'])->run())
            ->toThrow(DomainException::class, 'Unsupported upstream option');
    } else {
        expect(fn () => app(ImportClassicProductAction::class)->execute($account->id, '17', BillingCycle::MONTHLY, 'CNY', '20.00', 'Unsupported import'))
            ->toThrow(DomainException::class, 'Unsupported upstream option');
    }

    expect(Product::count())->toBe(0)->and(Product::publicCatalog()->count())->toBe(0)
        ->and(Category::where('slug', 'classic-cloud')->count())->toBe(0)
        ->and(ProductOptionGroup::count())->toBe(0)->and(ProductOptionItem::count())->toBe(0)->and(ProductCustomField::count())->toBe(0);
    expect(DB::table('audit_logs')->whereIn('action', ['upstream.classic_product_import', 'upstream.catalog_sync'])->count())->toBe(0);
    Http::assertSentCount(2);
})->with(['shared action' => ['action'], 'administrator' => ['http'], 'console' => ['cli']]);

it('keeps the complete old product and visibility when a reimport fails', function (bool $hidden): void {
    $account = UpstreamApi::factory()->create();
    $levels = [];
    $state = fakeAtomicClassicCatalog(atomicClassicCatalog(), $levels);
    $product = app(ImportClassicProductAction::class)->execute($account->id, '17', BillingCycle::MONTHLY, 'CNY', '20.00', 'Initial import');
    $product->update(['is_hidden' => $hidden, 'name' => 'Existing complete product']);
    $before = $product->fresh()->getRawOriginal();
    $groups = DB::table('product_option_groups')->get()->toArray();
    $items = DB::table('product_option_items')->get()->toArray();
    $fields = DB::table('product_custom_fields')->get()->toArray();
    $auditCount = DB::table('audit_logs')->count();
    $catalog = atomicClassicCatalog(20);
    $catalog['products']['location_version'] = 8;
    $state->catalog = $catalog;

    expect(fn () => app(ImportClassicProductAction::class)->execute($account->id, '17', BillingCycle::MONTHLY, 'CNY', '80.00', 'Failed reimport'))
        ->toThrow(DomainException::class);
    expect($product->fresh()->getRawOriginal())->toBe($before)
        ->and(DB::table('product_option_groups')->get()->toArray())->toEqual($groups)
        ->and(DB::table('product_option_items')->get()->toArray())->toEqual($items)
        ->and(DB::table('product_custom_fields')->get()->toArray())->toEqual($fields)
        ->and(DB::table('audit_logs')->count())->toBe($auditCount);
})->with([false, true]);

it('preserves manual hiding during successful full reimports', function (): void {
    $account = UpstreamApi::factory()->create();
    $levels = [];
    fakeAtomicClassicCatalog(atomicClassicCatalog(), $levels);
    $product = app(ImportClassicProductAction::class)->execute($account->id, '17', BillingCycle::MONTHLY, 'CNY', '20.00', 'Initial import');
    $product->update(['is_hidden' => true]);

    $updated = app(ImportClassicProductAction::class)->execute($account->id, '17', BillingCycle::ANNUALLY, 'CNY', '30.00', 'Full reimport');

    expect($updated->id)->toBe($product->id)->and($updated->is_hidden)->toBeTrue()
        ->and($updated->local_prices['annually']['price'])->toBe('104.00')
        ->and(Product::publicCatalog()->whereKey($product->id)->exists())->toBeFalse();
});
