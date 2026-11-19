<?php

declare(strict_types=1);

namespace App\Actions;

use App\Contracts\SyncableCatalog;
use App\Data\CatalogSnapshotResult;
use App\Data\SyncCatalogResult;
use App\Enums\BillingCycle;
use App\Models\Product;
use App\Models\ProductCustomField;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionItem;
use App\Models\User;
use App\Services\DriverManager;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SyncClassicCatalogAction
{
    public function execute(Product $product, string $reason, ?User $actor = null): SyncCatalogResult
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('upstream.import');
        }
        if (trim($reason) === '') {
            throw new DomainException('A catalog sync reason is required.');
        }
        $driver = app(DriverManager::class)->fulfillment($product->driver_type, $product->upstream_api_id);
        if (! $driver instanceof SyncableCatalog) {
            throw new DomainException('This driver has no catalog capability.');
        }
        $snapshot = app(CallUpstreamAction::class)->execute(fn () => $driver->syncProductConfig($product));

        return $this->applySnapshot($product, $snapshot, $reason, $actor);
    }

    /** Persist an already-read snapshot so imports can commit the complete product atomically. */
    public function applySnapshot(Product $product, CatalogSnapshotResult $snapshot, string $reason, ?User $actor = null): SyncCatalogResult
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('upstream.import');
        }
        if (trim($reason) === '') {
            throw new DomainException('A catalog sync reason is required.');
        }

        return DB::transaction(function () use ($product, $snapshot, $reason, $actor): SyncCatalogResult {
            $locked = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($locked->driver_type !== $product->driver_type || $locked->upstream_api_id !== $product->upstream_api_id
                || (string) $locked->upstream_pid !== $snapshot->upstreamPid || $locked->upstream_version > $snapshot->version) {
                throw new DomainException('Product mapping or version changed during catalog read.');
            }
            $prices = $this->prices($snapshot->prices, $locked->currency, $this->isFree($snapshot->product));
            if ($prices === []) {
                throw new DomainException('Catalog contains no matching-currency prices.');
            }
            $rules = $locked->provisioning_rules ?? [];
            $rules['classic_catalog'] = ['version' => $snapshot->version, 'dependencies' => $snapshot->dependencies,
                'password' => $snapshot->product['password'] ?? null, 'host' => $snapshot->product['host'] ?? null,
                'pay_type' => $snapshot->product['pay_type'] ?? null];
            $publisher = $snapshot->product['p_uid'] ?? null;
            if ((is_int($publisher) || is_string($publisher))
                && filter_var($publisher, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) !== false) {
                $rules['classic_catalog']['product_publisher_uid'] = (int) $publisher;
            }
            $locked->update(['upstream_version' => $snapshot->version, 'upstream_base_prices' => $prices,
                'local_prices' => $this->marked($prices, $locked->markup_percent), 'provisioning_rules' => $rules,
                'stock_control' => (bool) ($snapshot->product['stock_control'] ?? false), 'qty' => (int) ($snapshot->product['qty'] ?? 0)]);
            $ids = [];
            $items = 0;
            foreach ($snapshot->optionGroups as $container) {
                foreach ($container['options'] ?? [] as $option) {
                    $id = (int) ($option['id'] ?? 0);
                    $type = (int) ($option['option_type'] ?? 0);
                    if ($id < 1 || $type < 1 || $type > 19) {
                        throw new DomainException('Unsupported upstream option identifier or type.');
                    }
                    $existing = ProductOptionGroup::where('product_id', $locked->id)->where('upstream_group_id', $id)->first();
                    if ($existing !== null && $existing->sync_source !== 'upstream') {
                        throw new DomainException('Catalog mapping conflicts with a local option.');
                    }
                    $quantity = in_array($type, [4, 7, 9, 11, 14, 15, 16, 17, 18, 19], true);
                    $group = ProductOptionGroup::updateOrCreate(['product_id' => $locked->id, 'upstream_group_id' => $id],
                        ['name' => $this->label($option['option_name'] ?? 'Option'), 'type' => $quantity ? 'quantity' : ($type === 3 ? 'toggle' : 'select'),
                            'min_value' => $quantity ? (int) ($option['qty_minimum'] ?? 0) : null,
                            'max_value' => $quantity ? (int) ($option['qty_maximum'] ?? 2147483647) : null,
                            'step' => max(1, (int) ($option['qty_step'] ?? 1)), 'unit' => $option['unit'] ?? null,
                            'is_upgradeable' => (bool) ($option['upgrade'] ?? false), 'sync_source' => 'upstream',
                            'is_hidden' => false, 'sort_order' => (int) ($option['order'] ?? 0),
                            'upstream_meta' => ['option_type' => $type, 'container_id' => $container['id'] ?? null, 'qty_stage' => $option['qty_stage'] ?? null,
                                'subs' => array_map(fn (array $sub) => array_intersect_key($sub, array_flip(['id', 'qty_minimum', 'qty_maximum', 'qty', 'sort_order'])), $option['sub'] ?? [])]]);
                    $ids[] = $group->id;
                    $subs = [];
                    foreach ($option['sub'] ?? [] as $sub) {
                        if ((int) ($sub['id'] ?? 0) < 1) {
                            throw new DomainException('Invalid upstream sub-option ID.');
                        }
                        $subPrices = $this->prices($sub['pricings'] ?? [], $locked->currency, $this->isFree($snapshot->product));
                        $item = ProductOptionItem::updateOrCreate(['option_group_id' => $group->id, 'upstream_sub_id' => $sub['id']],
                            ['label' => $this->label($sub['option_name'] ?? 'Option'), 'upstream_base_prices' => $subPrices,
                                'local_prices' => $this->marked($subPrices, $locked->markup_percent), 'is_hidden' => false,
                                'sort_order' => (int) ($sub['sort_order'] ?? 0)]);
                        $subs[] = $item->id;
                        $items++;
                    }
                    ProductOptionItem::where('option_group_id', $group->id)->whereNotIn('id', $subs)->update(['is_hidden' => true]);
                }
            }
            $hidden = ProductOptionGroup::where('product_id', $locked->id)->where('sync_source', 'upstream')->whereNotIn('id', $ids)->update(['is_hidden' => true]);
            $fields = [];
            foreach ($snapshot->customFields as $field) {
                $type = $field['fieldtype'] ?? '';
                if (! in_array($type, ['text', 'textarea', 'dropdown', 'select', 'password', 'link', 'tickbox'], true) || (int) ($field['id'] ?? 0) < 1) {
                    throw new DomainException('Unsupported custom field type or identifier.');
                }
                $regex = $field['regexpr'] ?? null;
                if (is_string($regex) && $regex !== '' && @preg_match('/'.str_replace('/', '\/', $regex).'/', '') === false) {
                    throw new DomainException('Invalid custom field validation expression.');
                }
                $existing = ProductCustomField::where('product_id', $locked->id)->where('upstream_field_id', $field['id'])->first();
                if ($existing !== null && $existing->sync_source !== 'upstream') {
                    throw new DomainException('Custom field mapping belongs to a local field.');
                }
                $record = ProductCustomField::updateOrCreate(['product_id' => $locked->id, 'upstream_field_id' => $field['id']],
                    ['name' => $field['fieldname'], 'field_type' => in_array($type, ['dropdown', 'tickbox'], true) ? 'select' : ($type === 'link' ? 'text' : $type),
                        'options' => $type === 'tickbox' ? ['', 'on'] : array_values(array_filter(array_map('trim', explode(',', $field['fieldoptions'] ?? '')))),
                        'validation_regex' => $regex ?: null, 'is_required' => (bool) ($field['required'] ?? false),
                        'show_on_order' => (bool) ($field['showorder'] ?? true), 'show_on_detail' => (bool) ($field['showdetail'] ?? true), 'sync_source' => 'upstream', 'is_hidden' => false]);
                $fields[] = $record->id;
            }
            ProductCustomField::where('product_id', $locked->id)->where('sync_source', 'upstream')->whereNotIn('id', $fields)->update(['is_hidden' => true]);
            DB::table('audit_logs')->insert(['actor_type' => $actor ? 'user' : 'system', 'actor_id' => $actor?->id, 'action' => 'upstream.catalog_sync',
                'subject_type' => Product::class, 'subject_id' => $locked->id, 'reason' => $reason,
                'after' => json_encode(['version' => $snapshot->version, 'options' => $items, 'hidden' => $hidden]), 'created_at' => now(), 'updated_at' => now()]);

            return new SyncCatalogResult(1, $items, $hidden, $snapshot->version);
        });
    }

    private function isFree(array $product): bool
    {
        $pay = $product['pay_type'] ?? [];
        $pay = is_string($pay) ? json_decode($pay, true, flags: JSON_THROW_ON_ERROR) : $pay;

        return ($pay['pay_type'] ?? null) === 'free';
    }

    private function label(string $label): string
    {
        return (string) last(explode('|', $label));
    }

    private function prices(array $rows, string $currency, bool $free): array
    {
        $row = collect($rows)->firstWhere('code', $currency);
        if ($free) {
            return ['free' => ['price' => Money::zero(), 'setup_fee' => Money::zero(), 'enabled' => true]];
        }
        if ($row === null) {
            return [];
        }
        $prices = [];
        foreach (BillingCycle::cases() as $cycle) {
            $value = $cycle->priceKey() === null ? null : ($row[$cycle->priceKey()] ?? null);
            if ($value === null || str_starts_with((string) $value, '-')) {
                continue;
            }
            $setup = $row[$cycle->setupFeeKey()] ?? null;
            if ($setup === null) {
                throw new DomainException('An enabled catalog cycle has no explicit setup fee.');
            }
            $prices[$cycle->value] = ['price' => Money::of($value), 'setup_fee' => Money::of($setup), 'enabled' => true];
        }

        return $prices;
    }

    private function marked(array $prices, string $markup): array
    {
        foreach ($prices as &$price) {
            $price['price'] = Money::add($price['price'], Money::percentOf($price['price'], $markup));
            $price['setup_fee'] = Money::add($price['setup_fee'], Money::percentOf($price['setup_fee'], $markup));
        }

        return $prices;
    }
}
