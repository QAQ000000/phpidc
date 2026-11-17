<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\DriverType;
use App\Models\Category;
use App\Models\Product;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateLocalCardProductAction
{
    public function execute(string $name, string $price, string $setupFee, string $currency, string $reason, DriverType $driverType = DriverType::REDEMPTION): Product
    {
        if (trim($name) === '' || trim($reason) === '' || ! preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new DomainException('A name, reason and ISO currency code are required.');
        }
        $price = Money::of($price);
        $setupFee = Money::of($setupFee);
        if (! in_array($driverType, [DriverType::REDEMPTION, DriverType::SUB2API], true)) {
            throw new DomainException('Only local card-pool drivers can use this product command.');
        }
        if (Money::gt(Money::add($price, $setupFee), '9999999999.99')) {
            throw new DomainException('Price exceeds the invoice column limit.');
        }

        return DB::transaction(function () use ($name, $price, $setupFee, $currency, $reason, $driverType): Product {
            $category = match ($driverType) {
                DriverType::SUB2API => Category::firstOrCreate(['slug' => 'api-credit'], ['name' => 'API 充值卡']),
                default => Category::firstOrCreate(['slug' => 'card-keys'], ['name' => '卡密']),
            };
            $product = Product::create(['category_id' => $category->id, 'name' => $name, 'slug' => 'card-'.Str::ulid(),
                'driver_type' => $driverType, 'currency' => $currency, 'markup_percent' => '0.00',
                'local_prices' => ['onetime' => ['price' => $price, 'setup_fee' => $setupFee, 'enabled' => true]]]);
            DB::table('audit_logs')->insert(['actor_type' => 'system', 'action' => 'cards.product_create', 'subject_type' => Product::class,
                'subject_id' => $product->id, 'reason' => $reason, 'after' => json_encode(['driver_type' => $driverType->value, 'price' => $price, 'setup_fee' => $setupFee, 'currency' => $currency]),
                'created_at' => now(), 'updated_at' => now()]);

            return $product;
        });
    }
}
