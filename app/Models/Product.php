<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DriverType;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'driver_type' => DriverType::class,
            'upstream_base_prices' => 'array',
            'local_prices' => 'array',
            'provisioning_rules' => 'array',
            'is_hidden' => 'boolean',
            'require_kyc' => 'boolean',
            'require_phone' => 'boolean',
            'stock_control' => 'boolean',
            'price_locked' => 'boolean',
            'send_welcome_email' => 'boolean',
            // Money and percentages are strings, never floats (INV-M1).
            'markup_percent' => 'string',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<UpstreamApi, $this> */
    public function upstreamApi(): BelongsTo
    {
        return $this->belongsTo(UpstreamApi::class);
    }

    /** @return HasMany<ProductOptionGroup, $this> */
    public function optionGroups(): HasMany
    {
        return $this->hasMany(ProductOptionGroup::class);
    }

    /** @return HasMany<ProductCustomField, $this> */
    public function customFields(): HasMany
    {
        return $this->hasMany(ProductCustomField::class);
    }

    public function instances(): HasMany
    {
        return $this->hasMany(Instance::class);
    }

    /** @param Builder<self> $query */
    public function scopePublicCatalog(Builder $query): void
    {
        $query->where('is_hidden', false)->whereHas('category', fn (Builder $category) => $category->where('is_hidden', false))
            ->where(fn (Builder $driver) => $driver
                ->where(fn (Builder $local) => $local->whereIn('driver_type', [DriverType::REDEMPTION, DriverType::SUB2API])->whereNull('upstream_api_id'))
                ->orWhere(fn (Builder $remote) => $remote->where('driver_type', DriverType::SUB2API)
                    ->whereHas('upstreamApi', fn (Builder $account) => $account->where('is_active', true)->where('driver', DriverType::SUB2API)
                        ->where('config->mode', 'remote_api')->where('config->protocol', 'balance-codes-v1')))
                ->orWhere(fn (Builder $upstream) => $upstream->where('driver_type', DriverType::ZJMF_API)
                    ->whereHas('upstreamApi', fn (Builder $account) => $account->where('is_active', true)->where('driver', DriverType::ZJMF_API))));
    }

    /** @return HasMany<RedemptionCode, $this> */
    public function redemptionCodes(): HasMany
    {
        return $this->hasMany(RedemptionCode::class);
    }

    /** @param Builder<self> $query */
    public function scopeLocalCardPool(Builder $query): void
    {
        $query->whereIn('driver_type', [DriverType::REDEMPTION, DriverType::SUB2API])->whereNull('upstream_api_id');
    }

    public function publicPath(): string
    {
        return '/store/'.rawurlencode($this->category->slug).'/'.rawurlencode($this->slug);
    }
}
