<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\CallUpstreamAction;
use App\Actions\ResolveProductOptionsAction;
use App\Contracts\ConfigurationInspectable;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InvoiceStatus;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\InstanceMappingReview;
use App\Models\InstanceUpstreamBinding;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionItem;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Support\MachineOperation;
use App\Support\Money;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use JsonException;

class InstanceMappingEvidence
{
    public function inspect(Instance $instance, Product $product, string $kind = 'mapping'): array
    {
        $instance = $instance->fresh(['upstreamApi']);
        $product = $product->fresh(['optionGroups.items']);
        $this->eligible($instance, $product, $kind);
        $before = $this->localFingerprint($instance, $product);
        $driver = app(DriverManager::class)->fulfillment($instance->driver_type, $instance->upstream_api_id);
        if (! $driver instanceof ConfigurationInspectable) {
            $this->invalid('当前驱动没有只读配置核验能力。');
        }
        $facts = app(CallUpstreamAction::class)->execute(fn () => $driver->inspectConfiguration($instance, $product));
        $instance = $instance->fresh(['upstreamApi']);
        $product = $product->fresh(['optionGroups.items']);
        if (! hash_equals($before, $this->localFingerprint($instance, $product))) {
            $this->invalid('读取期间本地绑定、目录或计费信息发生变化，请重新检查。');
        }

        return ['facts' => $facts, 'snapshot' => $this->snapshot($instance, $product, $facts, $kind)];
    }

    /** Must be called inside the transaction, before snapshot() and any mutation. */
    public function lock(Instance $instance, Product $product, User $actor): array
    {
        $users = User::withTrashed()->whereKey([$instance->user_id, $actor->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $owner = $users->get($instance->user_id);
        $lockedActor = $users->get($actor->id);
        abort_unless($owner !== null && $lockedActor !== null && ! $owner->trashed() && $owner->anonymized_at === null
            && ! $lockedActor->trashed() && $lockedActor->anonymized_at === null, 403);
        $invoices = Invoice::where('instance_id', $instance->id)->orderBy('id')->lockForUpdate()->get();
        PaymentAttempt::whereIn('invoice_id', $invoices->modelKeys())->orderBy('id')->lockForUpdate()->get();
        $locked = Instance::whereKey($instance->id)->lockForUpdate()->firstOrFail();
        abort_unless($locked->user_id === $owner->id, 403);
        FulfillmentTask::where('instance_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
        $target = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
        $groups = ProductOptionGroup::where('product_id', $target->id)->orderBy('id')->lockForUpdate()->get();
        ProductOptionItem::whereIn('option_group_id', $groups->modelKeys())->orderBy('id')->lockForUpdate()->get();
        $account = UpstreamApi::whereKey($locked->upstream_api_id)->lockForUpdate()->firstOrFail();
        InstanceUpstreamBinding::where('instance_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
        $locked->setRelation('upstreamApi', $account);

        return [$locked, $target->load('optionGroups.items'), $lockedActor];
    }

    public function snapshot(Instance $instance, Product $product, array $facts, string $kind = 'mapping'): array
    {
        $this->eligible($instance, $product, $kind);
        $cycle = $instance->billing_cycle->value;
        $catalog = $facts['catalog'];
        $remote = $catalog['products'];
        $payType = $remote['pay_type'] ?? null;
        $payType = is_string($payType) ? json_decode($payType, true, flags: JSON_THROW_ON_ERROR) : $payType;
        $storedPayType = $product->provisioning_rules['classic_catalog']['pay_type'] ?? null;
        $storedPayType = is_string($storedPayType) ? json_decode($storedPayType, true, flags: JSON_THROW_ON_ERROR) : $storedPayType;
        if (! is_array($payType) || ! isset($payType['pay_type']) || $payType['pay_type'] !== 'recurring'
            || $this->fingerprint($payType) !== $this->fingerprint(is_array($storedPayType) ? $storedPayType : [])
            || (int) ($remote['location_version'] ?? -1) !== $product->upstream_version
            || (int) ($product->provisioning_rules['classic_catalog']['version'] ?? -2) !== $product->upstream_version) {
            $this->invalid('目标目录版本或周期规则与认证目录不一致，请先同步。');
        }
        $baseCost = $this->remotePrice($catalog['product_pricings'] ?? [], $instance->billing_cycle->priceKey(), $instance->billing_cycle->setupFeeKey());
        $this->sameUpstreamPrice($product->upstream_base_prices[$cycle] ?? [], $baseCost);
        $basePrice = $this->localPrice($product->local_prices[$cycle] ?? []);
        $groups = $product->optionGroups;
        if ($groups->contains(fn (ProductOptionGroup $group): bool => ! $group->is_hidden && $group->sync_source !== 'upstream')) {
            $this->invalid('目标商品包含未纳入认证目录的本地配置，无法建立完整计费基线。');
        }
        $localGroups = $groups->where('is_hidden', false)->where('sync_source', 'upstream')->keyBy('upstream_group_id');
        $remoteGroups = [];
        foreach ($catalog['config_groups'] as $container) {
            foreach ($container['options'] ?? [] as $option) {
                $remoteGroups[(int) $option['id']] = $option;
            }
        }
        if (count($remoteGroups) !== $localGroups->count()) {
            $this->invalid('本地目录没有完整同步全部目标配置组。');
        }
        $actual = collect($facts['actual_options'])->keyBy('group_id');
        if ($actual->count() !== $localGroups->count()) {
            $this->invalid('实际配置没有完整覆盖目标目录。');
        }
        $selected = [];
        foreach ($remoteGroups as $id => $option) {
            $group = $localGroups->get($id);
            $fact = $actual->get($id);
            $type = (int) $option['option_type'];
            if ($group === null || $fact === null || (int) ($group->upstream_meta['option_type'] ?? 0) !== $type
                || $fact['option_type'] !== $type || in_array($type, [15, 16, 17, 18, 19], true) || ! empty($option['qty_stage'])) {
                $this->invalid('存在缺失映射、配置类型差异或不能证明的阶梯价格。');
            }
            $quantity = in_array($type, [4, 7, 9, 11, 14], true);
            $expectedType = $quantity ? 'quantity' : ($type === 3 ? 'toggle' : 'select');
            if ($group->type !== $expectedType || $group->is_upgradeable !== (bool) ($option['upgrade'] ?? false)
                || ($quantity && ((int) $group->min_value !== (int) ($option['qty_minimum'] ?? 0)
                    || (int) $group->max_value !== (int) ($option['qty_maximum'] ?? 2147483647)
                    || (int) $group->step !== max(1, (int) ($option['qty_step'] ?? 1))))) {
                $this->invalid('本地配置类型、升级能力或数量边界与认证目录不一致。');
            }
            $items = $group->items->where('is_hidden', false)->keyBy('upstream_sub_id');
            if ($items->count() !== count($option['sub'] ?? [])) {
                $this->invalid('目标配置项没有完整同步。');
            }
            foreach ($option['sub'] ?? [] as $sub) {
                $item = $items->get((int) $sub['id']);
                if ($item === null) {
                    $this->invalid('目标配置项 ID 未同步。');
                }
                $prices = $this->remotePrice($sub['pricings'] ?? [], $instance->billing_cycle->priceKey(), $instance->billing_cycle->setupFeeKey());
                $this->sameUpstreamPrice($item->upstream_base_prices[$cycle] ?? [], $prices);
                $this->localPrice($item->local_prices[$cycle] ?? []);
                $bounds = collect($group->upstream_meta['subs'] ?? [])->firstWhere('id', $sub['id']);
                if ($bounds === null || ($quantity && ((int) ($bounds['qty_minimum'] ?? 0) !== (int) ($sub['qty_minimum'] ?? 0)
                    || (int) ($bounds['qty_maximum'] ?? PHP_INT_MAX) !== (int) ($sub['qty_maximum'] ?? PHP_INT_MAX)))) {
                    $this->invalid('目标子配置数量边界未同步。');
                }
            }
            $selected[$id] = $quantity ? $fact['qty'] : $fact['sub_id'];
        }
        if ($this->fingerprint($catalog['advanced'] ?? []) !== $this->fingerprint($product->provisioning_rules['classic_catalog']['dependencies'] ?? [])) {
            $this->invalid('配置依赖规则未同步。');
        }
        $resolved = app(ResolveProductOptionsAction::class)->execute($product, $cycle, ['configoption' => $selected], false);
        $optionPrice = Money::zero();
        $optionCost = Money::zero();
        foreach ($resolved['pairs'] as $pair) {
            $fact = $actual->get($pair['upstream_group_id']);
            $group = $localGroups->get($pair['upstream_group_id']);
            if ($fact['sub_id'] !== $pair['upstream_sub_id'] && ! ($group->type === 'toggle' && $pair['qty'] === 0)) {
                $this->invalid('实际子配置与数量解析出的子配置不一致，不能猜测计费项。');
            }
            $item = $group->items->firstWhere('id', $pair['local_item_id']);
            $optionPrice = Money::add($optionPrice, Money::mul($pair['unit_price'], $pair['qty']));
            $optionCost = Money::add($optionCost, Money::mul(Money::of($item->upstream_base_prices[$cycle]['price']), $pair['qty']));
        }
        $newPrice = Money::add($basePrice, $optionPrice);
        $renewalCost = Money::of($facts['renewal_cost']);
        if (Money::gt($newPrice, '9999999999.99') || Money::gt($renewalCost, '9999999999.99')) {
            $this->invalid('目标价格或成本超过可存储范围。');
        }
        $snapshot = ['version' => 1, 'kind' => $kind, 'instance_id' => $instance->id, 'user_id' => $instance->user_id,
            'original_product_id' => $instance->product_id, 'target_product_id' => $product->id,
            'local_fingerprint' => $this->localFingerprint($instance, $product),
            'binding_fingerprint' => $this->bindingFingerprint($instance), 'account_fingerprint' => $this->accountFingerprint($instance),
            'catalog_fingerprint' => $this->catalogFingerprint($product), 'host' => $facts['host'],
            'actual_options' => $facts['actual_options'], 'resolved_pairs' => $resolved['pairs'], 'upstream_configoption' => $resolved['configoption'],
            'binding_pay_type' => $payType, 'billing_cycle' => $cycle, 'currency' => 'CNY',
            'base_price' => $basePrice, 'base_cost' => $baseCost['price'], 'option_price' => $optionPrice, 'option_cost' => $optionCost,
            'new_recurring_amount' => $newPrice, 'catalog_cost' => Money::add($baseCost['price'], $optionCost), 'renewal_cost' => $renewalCost,
            'original_recurring_amount' => $instance->recurring_amount, 'original_is_price_locked' => $instance->is_price_locked,
            'original_next_due_at' => $instance->next_due_at?->utc()->toIso8601String(),
            'price_baseline_matches' => Money::eq($newPrice, $instance->recurring_amount)];
        if ($kind === 'price_confirmation') {
            $mapping = $this->mapping($instance);
            if ($mapping->target_product_id !== $product->id || $mapping->snapshot['binding_fingerprint'] !== $snapshot['binding_fingerprint']
                || $this->fingerprint($mapping->snapshot['resolved_pairs']) !== $this->fingerprint($instance->active_config['options'] ?? [])) {
                $this->invalid('当前配置与原映射核准事实不一致，请重新核对映射。');
            }
            $snapshot['parent_mapping_review_id'] = $mapping->id;
        }

        return $snapshot;
    }

    public function mapping(Instance $instance): InstanceMappingReview
    {
        $review = InstanceMappingReview::find($instance->active_config['mapping_review_id'] ?? null);
        if ($review === null || $review->kind !== 'mapping' || $review->instance_id !== $instance->id || $review->user_id !== $instance->user_id
            || ! hash_equals($review->review_fingerprint, $this->fingerprint($review->snapshot))) {
            $this->invalid('缺少有效且不可覆盖的原映射核准记录。');
        }

        return $review;
    }

    public function token(array $snapshot, User $actor, string $requestId): array
    {
        $checked = now()->timestamp;

        return ['snapshot' => $snapshot, 'request_id' => $requestId, 'checked_at' => now()->toIso8601String(), 'expires_at' => now()->addMinutes(5)->toIso8601String(),
            'review_token' => Crypt::encryptString(json_encode(['version' => 1, 'actor_id' => $actor->id, 'request_id' => $requestId,
                'checked_at' => $checked, 'expires_at' => $checked + 300, 'snapshot' => $snapshot], JSON_THROW_ON_ERROR))];
    }

    public function decode(string $token, User $actor, Instance $instance, string $requestId, string $kind): array
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException $error) {
            $this->invalid('核准凭据无效，请重新检查。');
        }
        if (! is_array($data) || ($data['version'] ?? null) !== 1 || ($data['actor_id'] ?? null) !== $actor->id
            || ($data['request_id'] ?? null) !== $requestId || ($data['snapshot']['instance_id'] ?? null) !== $instance->id
            || ($data['snapshot']['kind'] ?? null) !== $kind || ! is_int($data['checked_at'] ?? null) || ! is_int($data['expires_at'] ?? null)) {
            $this->invalid('核准凭据与本次操作身份不一致。');
        }

        return $data;
    }

    public function freshToken(array $token): void
    {
        if ($token['checked_at'] > now()->timestamp || $token['expires_at'] <= now()->timestamp || $token['expires_at'] - $token['checked_at'] !== 300) {
            $this->invalid('核准凭据已过期，请重新检查。');
        }
    }

    public function replay(User $actor, Instance $instance, string $requestId, array $token, string $reason): ?InstanceMappingReview
    {
        $review = InstanceMappingReview::where('actor_id', $actor->id)->where('request_id', $requestId)->first();
        if ($review !== null && ($review->instance_id !== $instance->id || $review->user_id !== $instance->user_id
            || $review->kind !== $token['snapshot']['kind'] || $review->target_product_id !== $token['snapshot']['target_product_id']
            || $review->reason !== $reason || ! hash_equals($review->review_fingerprint, $this->fingerprint($token['snapshot']))
            || ! hash_equals($review->review_fingerprint, $this->fingerprint($review->snapshot)))) {
            $this->invalid('该请求 ID 已用于不同的核准意图，不能覆盖历史记录。');
        }

        return $review;
    }

    public function fingerprint(array $facts): string
    {
        return hash('sha256', json_encode(Arr::sortRecursive($facts), JSON_THROW_ON_ERROR));
    }

    public function invalid(string $message): never
    {
        throw ValidationException::withMessages(['mapping' => $message]);
    }

    private function eligible(Instance $instance, Product $product, string $kind): void
    {
        $user = User::withTrashed()->find($instance->user_id);
        if ($user === null || $user->trashed() || $user->anonymized_at !== null || ! $instance->status->isBillable()
            || ! $instance->billing_cycle->isRenewable() || $instance->currency !== 'CNY' || $product->currency !== 'CNY'
            || $product->driver_type !== $instance->driver_type || $product->upstream_api_id !== $instance->upstream_api_id
            || (string) $product->upstream_pid !== (string) ($instance->active_config['upstream_pid'] ?? '')
            || $instance->upstream_instance_id === null || ! $instance->upstreamApi?->is_active
            || isset($instance->active_config['cancellation']) || app(MachineOperation::class)->blocked($instance)
            || Invoice::where('instance_id', $instance->id)->where('status', InvoiceStatus::UNPAID)->exists()
            || FulfillmentTask::where('instance_id', $instance->id)->whereIn('status', [FulfillmentTaskStatus::PENDING, FulfillmentTaskStatus::RUNNING, FulfillmentTaskStatus::FAILED])->exists()) {
            $this->invalid('账户、绑定、币种、周期或未决账单/任务不满足核准条件。');
        }
        if ($kind === 'mapping' && ! ($instance->active_config['mapping_requires_review'] ?? false)
            || $kind === 'price_confirmation' && (($instance->active_config['mapping_requires_review'] ?? false)
                || ! ($instance->active_config['price_requires_review'] ?? false) || $instance->product_id !== $product->id)) {
            $this->invalid('当前服务不处于相应的映射或价格确认阶段。');
        }
        $bindings = InstanceUpstreamBinding::where('instance_id', $instance->id)->whereNull('unbound_at')->get();
        if ($bindings->count() !== 1 || $bindings->first()->upstream_api_id !== $instance->upstream_api_id
            || $bindings->first()->upstream_instance_id !== $instance->upstream_instance_id || $bindings->first()->driver_type !== $instance->driver_type) {
            $this->invalid('当前绑定历史缺失或相互矛盾，不能核准。');
        }
    }

    private function localFingerprint(Instance $instance, Product $product): string
    {
        return $this->fingerprint(['instance' => $instance->only(['id', 'user_id', 'product_id', 'driver_type', 'status', 'upstream_api_id',
            'upstream_instance_id', 'billing_cycle', 'currency', 'recurring_amount', 'is_price_locked', 'next_due_at']),
            'active_config_fingerprint' => $this->fingerprint($instance->active_config ?? []), 'binding' => $this->bindingFingerprint($instance),
            'account' => $this->accountFingerprint($instance), 'catalog' => $this->catalogFingerprint($product)]);
    }

    private function bindingFingerprint(Instance $instance): string
    {
        return $this->fingerprint(['instance_id' => $instance->id, 'user_id' => $instance->user_id, 'driver_type' => $instance->driver_type->value,
            'upstream_api_id' => $instance->upstream_api_id, 'upstream_instance_id' => $instance->upstream_instance_id,
            'downstream_token_hash' => hash('sha256', (string) $instance->downstream_token),
            'history' => InstanceUpstreamBinding::where('instance_id', $instance->id)->whereNull('unbound_at')->orderBy('id')
                ->get(['id', 'driver_type', 'upstream_api_id', 'upstream_instance_id', 'bound_at'])->toArray()]);
    }

    private function accountFingerprint(Instance $instance): string
    {
        return $this->fingerprint(['account' => $instance->upstreamApi?->only(['id', 'driver', 'is_active', 'base_url', 'username', 'auth_header_mode', 'config']) ?? [],
            'credential_fingerprint' => hash('sha256', (string) $instance->upstreamApi?->getRawOriginal('encrypted_secret'))]);
    }

    private function catalogFingerprint(Product $product): string
    {
        return $this->fingerprint(['product' => $product->only(['id', 'driver_type', 'upstream_api_id', 'upstream_pid', 'currency', 'upstream_version',
            'upstream_base_prices', 'local_prices', 'markup_percent', 'provisioning_rules']),
            'groups' => $product->optionGroups->map(fn (ProductOptionGroup $group): array => $group->only(['id', 'product_id', 'name', 'type',
                'upstream_group_id', 'sync_source', 'is_hidden', 'is_upgradeable', 'min_value', 'max_value', 'step', 'upstream_meta'])
                + ['items' => $group->items->map(fn (ProductOptionItem $item): array => $item->only(['id', 'option_group_id', 'upstream_sub_id', 'label',
                    'upstream_base_prices', 'local_prices', 'is_hidden']))->all()])->all()]);
    }

    private function remotePrice(array $rows, ?string $key, ?string $setupKey): array
    {
        $matches = collect($rows)->where('code', 'CNY');
        $row = $matches->first();
        if ($matches->count() !== 1 || $key === null || $setupKey === null || ! isset($row[$key], $row[$setupKey])
            || (! is_int($row[$key]) && ! is_string($row[$key])) || (! is_int($row[$setupKey]) && ! is_string($row[$setupKey]))
            || str_starts_with((string) $row[$key], '-') || str_starts_with((string) $row[$setupKey], '-')) {
            $this->invalid('认证目录缺少当前周期明确的 CNY 价格与初装费。');
        }

        return ['price' => Money::of($row[$key]), 'setup_fee' => Money::of($row[$setupKey]), 'enabled' => true];
    }

    private function sameUpstreamPrice(array $local, array $remote): void
    {
        if (! ($local['enabled'] ?? false) || ! isset($local['price'], $local['setup_fee'])
            || Money::of($local['price']) !== $remote['price'] || Money::of($local['setup_fee']) !== $remote['setup_fee']) {
            $this->invalid('本地成本基线与认证目录不一致，请先同步。');
        }
    }

    private function localPrice(array $price): string
    {
        if (! ($price['enabled'] ?? false) || ! isset($price['price'])) {
            $this->invalid('目标商品或配置缺少明确的本地周期售价。');
        }

        return Money::of($price['price']);
    }
}
