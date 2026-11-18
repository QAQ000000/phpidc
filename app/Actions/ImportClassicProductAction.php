<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\BillingCycle;
use App\Enums\DriverType;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\DriverManager;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ImportClassicProductAction
{
    public function execute(int $accountId, string $pid, BillingCycle $cycle, string $currency, string $markup, string $reason, ?User $actor = null): Product
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('upstream.import');
        }
        if (trim($reason) === '' || ! ctype_digit($pid) || (int) $pid < 1 || ! preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new DomainException('A product ID, currency and operator reason are required.');
        }
        $markup = Money::of($markup);
        if (Money::gt($markup, '999999.99')) {
            throw new DomainException('Markup exceeds the column limit.');
        }
        $driver = app(DriverManager::class)->classicAccount($accountId);
        $probe = new Product(['upstream_pid' => $pid]);
        $configuration = app(CallUpstreamAction::class)->execute(fn () => $driver->productConfiguration($probe));
        $row = collect($configuration['product_pricings'] ?? [])->firstWhere('code', $currency);
        $value = $cycle === BillingCycle::FREE ? '0.00' : ($row[$cycle->priceKey()] ?? null);
        if ($value === null || str_starts_with((string) $value, '-')) {
            throw new DomainException('No enabled price exists for the selected cycle and currency.');
        }
        $cost = Money::of($value);
        $setup = $cycle->setupFeeKey() === null ? Money::zero() : ($row[$cycle->setupFeeKey()] ?? null);
        if ($setup === null) {
            throw new DomainException('The selected cycle has no explicit setup fee.');
        }
        $setup = Money::of($setup);
        $price = Money::add($cost, Money::percentOf($cost, $markup));
        $setupPrice = Money::add($setup, Money::percentOf($setup, $markup));

        return DB::transaction(function () use ($accountId, $pid, $cycle, $currency, $markup, $reason, $configuration, $cost, $price, $setup, $setupPrice, $actor): Product {
            $product = Product::where('upstream_api_id', $accountId)->where('upstream_pid', $pid)->lockForUpdate()->first();
            if ($product !== null && ($product->driver_type !== DriverType::ZJMF_API || $product->currency !== $currency)) {
                throw new DomainException('Existing mapping has a different driver or currency.');
            }
            if ($product === null) {
                $category = Category::firstOrCreate(['slug' => 'classic-cloud'], ['name' => '云服务']);
                $product = new Product(['category_id' => $category->id, 'slug' => 'cloud-'.Str::ulid(),
                    'driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $accountId, 'upstream_pid' => $pid, 'currency' => $currency]);
            }
            $prices = $product->upstream_base_prices ?? [];
            $prices[$cycle->value] = ['price' => $cost, 'setup_fee' => $setup, 'enabled' => true];
            $local = $product->local_prices ?? [];
            $local[$cycle->value] = ['price' => $price, 'setup_fee' => $setupPrice, 'enabled' => true];
            $product->fill(['name' => $configuration['products']['name'] ?? 'Cloud '.$pid, 'markup_percent' => $markup,
                'upstream_base_prices' => $prices, 'local_prices' => $local])->save();
            DB::table('audit_logs')->insert(['actor_type' => $actor ? 'user' : 'system', 'actor_id' => $actor?->id, 'action' => 'upstream.classic_product_import', 'subject_type' => Product::class,
                'subject_id' => $product->id, 'reason' => $reason, 'after' => json_encode(['upstream_pid' => $pid, 'cycle' => $cycle->value,
                    'currency' => $currency, 'markup_percent' => $markup]), 'created_at' => now(), 'updated_at' => now()]);

            return $product;
        });
    }
}
