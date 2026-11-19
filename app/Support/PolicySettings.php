<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Validation\ValidationException;

class PolicySettings
{
    /** @return array<string, array{label: string, permission: string, fields: array<string, array{label: string, type: string, default: int, min: int, max: int, help: string}>}> */
    public static function sections(): array
    {
        return [
            'billing' => ['label' => '计费策略', 'permission' => 'settings.billing.manage', 'fields' => [
                'billing.invoice_expiry_minutes' => ['label' => '普通账单有效期（分钟）', 'type' => 'number', 'default' => 30, 'min' => 1, 'max' => 10080,
                    'help' => '仅影响新建普通账单和续费恢复报价的付款窗口，不重算已有账单到期时间。'],
                'billing.renewal_notice_days' => ['label' => '自动续费账单提前量（天）', 'type' => 'number', 'default' => 7, 'min' => 0, 'max' => 365,
                    'help' => '下一轮生命周期处理按此窗口创建续费账单并提醒；小时、日周期分别最多提前一小时、一天。'],
                'billing.renewal_grace_days' => ['label' => '续费账单宽限期（天）', 'type' => 'number', 'default' => 7, 'min' => 1, 'max' => 365,
                    'help' => '新续费根账单有效至服务到期加宽限期，且至少保留当前时间起三十分钟；已有账单不变。'],
                'affiliate.freeze_days' => ['label' => '新佣金冻结期（天）', 'type' => 'number', 'default' => 7, 'min' => 0, 'max' => 365,
                    'help' => '写入新账单的分佣快照，从实际付款时间起算；历史快照和已有佣金成熟时间不变。'],
            ]],
            'automation' => ['label' => '自动化策略', 'permission' => 'settings.billing.manage', 'fields' => [
                'lifecycle.urgent_batch' => ['label' => '生命周期紧急批量', 'type' => 'number', 'default' => 100, 'min' => 1, 'max' => 100,
                    'help' => '独立派发最多选择此数量；统一维护紧急批次最多五十项，与轮询共用单轮一百项、五秒预算。'],
                'lifecycle.rotation_batch' => ['label' => '生命周期轮询批量', 'type' => 'number', 'default' => 100, 'min' => 1, 'max' => 100,
                    'help' => '独立派发最多选择此数量；统一维护轮询批次最多五十项，与紧急批次共用单轮一百项、五秒预算。'],
                'lifecycle.catalog_batch' => ['label' => '商品目录派发批量', 'type' => 'number', 'default' => 100, 'min' => 1, 'max' => 100,
                    'help' => '限制后续目录派发数量；统一维护仍受单轮一百项、五秒预算约束，不改变任务周期或启动状态。'],
            ]],
        ];
    }

    /** @return list<string> */
    public static function keysForSection(string $section): array
    {
        $definition = self::sections()[$section] ?? null;
        if ($definition === null) {
            throw ValidationException::withMessages(['settings' => '不支持的策略配置分组。']);
        }

        return array_keys($definition['fields']);
    }

    /** @return array<string, int> */
    public static function validate(string $section, array $settings): array
    {
        $keys = self::keysForSection($section);
        if ($settings === [] || array_diff(array_keys($settings), $keys) !== []) {
            throw ValidationException::withMessages(['settings' => '请提交本组允许的策略字段。']);
        }
        $validated = [];
        foreach ($settings as $key => $value) {
            $field = self::sections()[$section]['fields'][$key];
            if (! is_int($value) && (! is_string($value) || preg_match('/^(0|[1-9][0-9]*)$/D', $value) !== 1)) {
                throw ValidationException::withMessages(['settings' => $field['label'].'须为整数。']);
            }
            validator(['value' => $value], ['value' => ['required', 'integer', 'between:'.$field['min'].','.$field['max']]],
                [], ['value' => $field['label']])->validate();
            $validated[$key] = (int) $value;
        }

        return $validated;
    }
}
