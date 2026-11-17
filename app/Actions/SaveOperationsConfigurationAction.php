<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\AffiliateRule;
use App\Models\Coupon;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\NotificationManager;
use App\Support\Money;
use App\Support\OperationsAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveOperationsConfigurationAction
{
    public function execute(User $actor, string $kind, array $input, string $reason, ?int $id = null): Model
    {
        [$class, $permission, $rules] = match ($kind) {
            'coupon' => [Coupon::class, 'promotion.manage', [
                'code' => ['required', 'string', 'max:64', Rule::unique('coupons', 'code')->ignore($id)],
                'type' => ['required', Rule::in(['fixed', 'percent'])],
                'value' => ['required', 'regex:/^\d{1,8}\.\d{2}$/'], 'min_order_amount' => ['required', 'regex:/^\d{1,8}\.\d{2}$/'],
                'applies_to' => ['required', Rule::in(['new_order', 'renew', 'both'])], 'product_ids' => ['nullable', 'array'], 'product_ids.*' => ['integer', 'exists:products,id'],
                'total_quota' => ['nullable', 'integer', 'min:0'], 'per_user_limit' => ['required', 'integer', 'min:1'],
                'valid_from' => ['nullable', 'date'], 'valid_until' => ['nullable', 'date', Rule::when(! empty($input['valid_from']), ['after:valid_from'])],
                'is_active' => ['required', 'boolean'], 'claim_conditions' => ['nullable', 'array:registered_days_gt,registered_days_lt,require_kyc,require_bound_channels'],
                'claim_conditions.registered_days_gt' => ['sometimes', 'integer', 'min:0'], 'claim_conditions.registered_days_lt' => ['sometimes', 'integer', 'min:1'],
                'claim_conditions.require_kyc' => ['sometimes', 'boolean'], 'claim_conditions.require_bound_channels' => ['sometimes', 'array'],
                'claim_conditions.require_bound_channels.*' => [Rule::in(['email', 'sms', 'telegram'])],
            ]],
            'rule' => [AffiliateRule::class, 'affiliate.manage', [
                'name' => ['required', 'string', 'max:255'], 'commission_rate' => ['required', 'regex:/^\d{1,3}\.\d{2}$/'],
                'priority' => ['required', 'integer', 'min:0', 'max:2147483647'], 'is_active' => ['required', 'boolean'],
                'product_ids' => ['nullable', 'array'], 'product_ids.*' => ['integer', 'exists:products,id'],
            ]],
            'template' => [NotificationTemplate::class, 'notification.manage', [
                'event_key' => ['required', Rule::in(NotificationManager::EVENTS)], 'channel' => ['required', Rule::in(['email', 'sms', 'telegram'])],
                'subject' => ['nullable', 'string', 'max:255'], 'body_markdown' => ['required', 'string', 'max:20000'],
                'external_template_id' => ['nullable', 'string', 'max:255'], 'variable_mapping' => ['nullable', 'array'],
                'variable_mapping.*' => [Rule::in(NotificationManager::VARIABLES)], 'is_enabled' => ['required', 'boolean'],
            ]],
            default => throw new \InvalidArgumentException('Unknown operations configuration.'),
        };
        Gate::forUser($actor)->authorize($permission);
        validator(['reason' => $reason], ['reason' => ['required', 'string', 'max:255']])->validate();
        $data = validator($input, $rules)->validate();
        if (($kind === 'coupon' && (Money::isZero($data['value']) || ($data['type'] === 'percent' && Money::gt($data['value'], '100.00'))))
            || ($kind === 'rule' && Money::gt($data['commission_rate'], '100.00'))) {
            throw ValidationException::withMessages(['value' => '比例应在允许范围内，优惠金额必须大于零。']);
        }
        if ($kind === 'template') {
            preg_match_all('/\{\{([^{}]+)\}\}/', ($data['subject'] ?? '').$data['body_markdown'], $matches);
            if (array_diff($matches[1], NotificationManager::VARIABLES) !== []) {
                throw ValidationException::withMessages(['body_markdown' => '模板只允许指定的安全变量。']);
            }
            validator($data, ['channel' => [Rule::unique('notification_templates', 'channel')->where('event_key', $data['event_key'])->ignore($id)]])->validate();
        }

        return DB::transaction(function () use ($actor, $class, $kind, $permission, $data, $reason, $id): Model {
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize($permission);
            $model = $id === null ? new $class : $class::whereKey($id)->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($kind, $model);
            $products = array_values(array_unique($data['product_ids'] ?? []));
            if ($kind === 'rule') {
                unset($data['product_ids']);
            }
            $model->fill($data)->save();
            if ($kind === 'rule') {
                DB::table('affiliate_rule_product')->where('affiliate_rule_id', $model->id)->delete();
                foreach ($products as $productId) {
                    DB::table('affiliate_rule_product')->insert(['affiliate_rule_id' => $model->id, 'product_id' => $productId]);
                }
            }
            OperationsAudit::record($actor, $kind.'.save', $class, $model->id, $reason, $before, $this->snapshot($kind, $model));

            return $model;
        });
    }

    private function snapshot(string $kind, Model $model): array
    {
        if (! $model->exists) {
            return [];
        }
        if ($kind === 'coupon') {
            return $model->only(['min_order_amount', 'applies_to', 'product_ids', 'total_quota', 'per_user_limit', 'claim_conditions', 'valid_from', 'valid_until', 'is_active']) + ['coupon_code' => $model->getAttribute('code'), 'coupon_type' => $model->getAttribute('type'), 'coupon_value' => $model->getAttribute('value')];
        }
        if ($kind === 'rule') {
            return $model->only(['name', 'commission_rate', 'priority', 'is_active']) + ['product_ids' => DB::table('affiliate_rule_product')->where('affiliate_rule_id', $model->getKey())->pluck('product_id')->all()];
        }

        return $model->only(['event_key', 'channel', 'external_template_id', 'variable_mapping', 'is_enabled'])
            + ['template_sha256' => hash('sha256', ($model->getAttribute('subject') ?? '')."\n".$model->getAttribute('body_markdown'))];
    }
}
