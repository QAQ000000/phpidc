<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\DriverType;
use App\Models\Category;
use App\Models\Product;
use App\Models\UpstreamApi;
use App\Services\DriverManager;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateSub2ApiProductAction
{
    public function execute(UpstreamApi $account, string $name, string $price, string $faceValue, string $currency, string $reason): Product
    {
        app(DriverManager::class)->sub2ApiAccount($account->id);
        $price = Money::of($price);
        if (trim($name) === '' || trim($reason) === '' || ! preg_match('/^[A-Z]{3}$/D', $currency)
            || ! preg_match('/^[1-9]\d{0,7}$/D', $faceValue) || Money::gt($price, '9999999999.99')) {
            throw new DomainException('Valid name, price, integer face value, ISO currency and operator reason are required.');
        }

        return DB::transaction(function () use ($account, $name, $price, $faceValue, $currency, $reason): Product {
            $category = Category::firstOrCreate(['slug' => 'api-credit'], ['name' => 'API 充值卡']);
            $product = Product::create(['category_id' => $category->id, 'name' => $name, 'slug' => 'sub2api-'.Str::ulid(),
                'driver_type' => DriverType::SUB2API, 'upstream_api_id' => $account->id, 'currency' => $currency, 'markup_percent' => '0.00',
                'description' => '付款后自动生成一张 '.Money::of($faceValue).' 单位余额兑换码，请登录对应 Sub2API 站点兑换。',
                'local_prices' => ['onetime' => ['price' => $price, 'setup_fee' => '0.00', 'enabled' => true]],
                'provisioning_rules' => ['sub2api_face_value' => $faceValue]]);
            DB::table('audit_logs')->insert(['actor_type' => 'system', 'action' => 'sub2api.product_create', 'subject_type' => Product::class,
                'subject_id' => $product->id, 'reason' => $reason, 'after' => json_encode(['upstream_api_id' => $account->id, 'price' => $price, 'face_value' => Money::of($faceValue), 'currency' => $currency]),
                'created_at' => now(), 'updated_at' => now()]);

            return $product;
        });
    }
}
