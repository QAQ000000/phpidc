<?php

declare(strict_types=1);

namespace App\Support;

class AdminAuditProjection
{
    /** An allowlist, never a denylist of possible secret field names. */
    public static function json(?string $json): string
    {
        $data = json_decode($json ?? '', true);
        if (! is_array($data)) {
            return '{}';
        }
        $safe = [];
        foreach (['status', 'credit', 'frozen_credit', 'mode', 'keep_service', 'task_id', 'task_status', 'attempts', 'role', 'roles', 'count',
            'coupon_code', 'coupon_type', 'coupon_value', 'min_order_amount', 'applies_to', 'total_quota', 'per_user_limit', 'commission_rate', 'priority', 'event_key', 'channel', 'template_sha256', 'affiliate_balance', 'amount', 'access_level', 'is_active', 'is_enabled', 'sha256', 'user_id', 'product_id', 'batch_no', 'batch', 'imported', 'driver_type', 'upstream_api_id', 'instance_id'] as $key) {
            $value = $data[$key] ?? null;
            if ($key === 'roles' && is_array($value)) {
                $safe[$key] = array_values(array_intersect(array_filter($value, 'is_string'), array_keys(AdminRoles::permissions())));
            } elseif (is_scalar($value)) {
                $safe[$key] = is_string($value) ? mb_substr($value, 0, 255) : $value;
            }
        }

        return json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
