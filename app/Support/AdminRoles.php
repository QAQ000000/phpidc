<?php

declare(strict_types=1);

namespace App\Support;

class AdminRoles
{
    /** @return array<string, list<string>> */
    public static function permissions(): array
    {
        return [
            'inventory-viewer' => ['inventory.view'],
            'inventory-operator' => ['inventory.view', 'inventory.import', 'inventory.retry'],
            'upstream-viewer' => ['upstream.view'],
            'upstream-operator' => ['upstream.view', 'upstream.configure', 'upstream.import'],
            'upstream-lifecycle' => ['upstream.view', 'upstream.lifecycle'],
            'upstream-migrator' => ['upstream.view', 'upstream.hotswap'],
            'cms-viewer' => ['cms.view'],
            'cms-editor' => ['cms.view', 'cms.edit'],
            'cms-publisher' => ['cms.view', 'cms.edit', 'cms.publish'],
            'finance-viewer' => ['finance.view'],
            'finance-operator' => ['finance.view', 'finance.refund'],
            'finance-payout' => ['finance.view', 'finance.payout', 'affiliate.view'],
            'promotion-viewer' => ['promotion.view'],
            'promotion-operator' => ['promotion.view', 'promotion.manage'],
            'affiliate-viewer' => ['affiliate.view'],
            'affiliate-operator' => ['affiliate.view', 'affiliate.manage'],
            'support-viewer' => ['support.view'],
            'support-agent' => ['support.view', 'support.reply'],
            'notification-viewer' => ['notification.view'],
            'notification-operator' => ['notification.view', 'notification.manage'],
            'download-viewer' => ['download.view'],
            'download-operator' => ['download.view', 'download.manage'],
            'task-viewer' => ['tasks.view'],
            'audit-viewer' => ['audit.view'],
            'access-manager' => ['access.view', 'access.manage'],
        ];
    }

    /** @return list<string> */
    public static function webAssignable(): array
    {
        return array_values(array_diff(array_keys(self::permissions()), ['access-manager']));
    }
}
