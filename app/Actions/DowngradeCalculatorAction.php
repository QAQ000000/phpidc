<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\FulfillmentTaskStatus;
use App\Enums\InvoiceStatus;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\ProductOptionGroup;
use App\Models\User;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DowngradeCalculatorAction
{
    public function execute(Instance $instance, array $options, User $actor, string $reason): string
    {
        if ($actor->id !== $instance->user_id) {
            Gate::forUser($actor)->authorize('upstream.lifecycle');
        }
        if (trim($reason) === '') {
            throw new DomainException('A downgrade reason is required.');
        }

        return DB::transaction(function () use ($instance, $options, $actor, $reason): string {
            User::whereKey($instance->user_id)->lockForUpdate()->firstOrFail();
            $unpaid = Invoice::where('instance_id', $instance->id)->where('status', InvoiceStatus::UNPAID)->lockForUpdate()->exists();
            $locked = Instance::whereKey($instance->id)->lockForUpdate()->firstOrFail();
            if ($unpaid || ($locked->active_config['mapping_requires_review'] ?? false) || ($locked->active_config['price_requires_review'] ?? false) || isset($locked->active_config['cancellation']) || ! $locked->status->isBillable() || FulfillmentTask::where('instance_id', $locked->id)->whereIn('status', [FulfillmentTaskStatus::PENDING, FulfillmentTaskStatus::RUNNING, FulfillmentTaskStatus::FAILED])->exists()) {
                throw new DomainException('Resolve pending operations before changing renewal prices.');
            }
            $groupIds = array_column($locked->active_config['options'] ?? [], 'local_group_id');
            $groupIds = array_merge($groupIds, array_column($locked->active_config['billing_adjustment']['options'] ?? [], 'local_group_id'));
            if (ProductOptionGroup::where(fn ($query) => $query->where('product_id', $locked->product_id)->where('is_hidden', false))
                ->orWhereIn('id', $groupIds)->get()
                ->contains(fn (ProductOptionGroup $group): bool => (int) ($group->upstream_meta['option_type'] ?? 0) === 20)) {
                throw new DomainException('Hierarchical configurations do not support verified configuration downgrades.');
            }
            $target = app(ResolveProductOptionsAction::class)->execute($locked->product, $locked->billing_cycle->value, $options, false);
            $old = collect($locked->active_config['billing_adjustment']['options'] ?? $locked->active_config['options'] ?? [])->keyBy('local_group_id');
            $price = $locked->recurring_amount;
            $changed = false;
            foreach ($target['pairs'] ?? [] as $pair) {
                $before = $old->get($pair['local_group_id']);
                if ($before === null) {
                    throw new DomainException('Existing configuration snapshot is missing.');
                }
                if ($before['value'] === $pair['value'] && $before['qty'] === $pair['qty']) {
                    continue;
                }
                $group = ProductOptionGroup::findOrFail($pair['local_group_id']);
                $bounds = $group->downgrade_bounds ?? [];
                if (! ($bounds['allow_downgrade'] ?? false) || in_array($group->upstream_meta['option_type'] ?? null, [15, 16, 17, 18, 19], true)
                    || ($group->type === 'quantity' && ($pair['qty'] < ($bounds['min_value'] ?? $group->min_value) || $pair['qty'] > $before['qty']))
                    || ($group->type !== 'quantity' && ! in_array($pair['local_item_id'], $bounds['allowed_item_ids'] ?? [], true))) {
                    throw new DomainException('Downgrade is outside approved operator bounds.');
                }
                $previous = Money::mul(Money::of($before['unit_price']), $before['qty']);
                $next = Money::mul($pair['unit_price'], $pair['qty']);
                if (Money::gt($next, $previous)) {
                    throw new DomainException('A downgrade cannot increase an option price.');
                }
                $price = Money::sub($price, Money::sub($previous, $next));
                $changed = true;
            }
            if (! $changed) {
                throw new DomainException('Select an actual downgrade.');
            }
            $config = $locked->active_config;
            $config['billing_adjustment'] = ['options' => $target['pairs'], 'applied_at' => now()->toIso8601String(), 'remote_config_unchanged' => true];
            $before = $locked->recurring_amount;
            $locked->update(['recurring_amount' => $price, 'is_price_locked' => true, 'active_config' => $config]);
            DB::table('audit_logs')->insert(['actor_type' => 'user', 'actor_id' => $actor->id, 'action' => 'instance.downgrade_price', 'reason' => $reason,
                'subject_type' => Instance::class, 'subject_id' => $locked->id, 'before' => json_encode(['recurring_amount' => $before]),
                'after' => json_encode(['recurring_amount' => $price, 'refund' => '0.00', 'remote_config_unchanged' => true]), 'created_at' => now(), 'updated_at' => now()]);

            return $price;
        });
    }
}
