<?php

declare(strict_types=1);

namespace App\Support;

class AdminRoles
{
    /** @return array<string, list<string>> */
    public static function permissions(): array
    {
        return [
            'customer-viewer' => ['customers.view'],
            'customer-operator' => ['customers.view', 'customers.manage', 'customers.password-reset', 'customers.status.manage'],
            'settings-viewer' => ['settings.view'],
            'settings-payment' => ['settings.view', 'settings.payment.manage'],
            'settings-notification' => ['settings.view', 'settings.notification.manage'],
            'settings-identity' => ['settings.view', 'settings.identity.manage'],
            'settings-site' => ['settings.view', 'settings.site.manage'],
            'settings-billing' => ['settings.view', 'settings.billing.manage'],
            'inventory-viewer' => ['inventory.view'],
            'inventory-operator' => ['inventory.view', 'inventory.import', 'inventory.retry'],
            'upstream-viewer' => ['upstream.view'],
            'upstream-operator' => ['upstream.view', 'upstream.configure', 'upstream.import'],
            'upstream-lifecycle' => ['upstream.view', 'upstream.lifecycle'],
            'upstream-migrator' => ['upstream.view', 'upstream.hotswap'],
            'upstream-mapping-reviewer' => ['upstream.view', 'upstream.mapping-review'],
            'upstream-orphan-reviewer' => ['upstream.view', 'upstream.orphan-reconcile'],
            'cms-viewer' => ['cms.view'],
            'cms-editor' => ['cms.view', 'cms.edit'],
            'cms-publisher' => ['cms.view', 'cms.edit', 'cms.publish'],
            'finance-viewer' => ['finance.view'],
            'finance-operator' => ['finance.view', 'finance.refund'],
            'finance-wallet-adjuster' => ['finance.view', 'finance.wallet-adjust'],
            'finance-offline-receipt' => ['finance.view', 'finance.offline-receipt'],
            'finance-payout' => ['finance.view', 'finance.payout', 'affiliate.view'],
            'finance-receivable' => ['finance.view', 'finance.receivable'],
            'finance-receivable-reviewer' => ['finance.view', 'finance.receivable-review'],
            'finance-reconciliation' => ['finance.view', 'finance.reconcile'],
            'finance-wallet-settings' => ['finance.view', 'finance.wallet.configure'],
            'external-payout-reviewer' => ['finance.view', 'finance.external-payout.approve'],
            'external-payout-operator' => ['finance.view', 'finance.external-payout.execute', 'finance.external-payout.details'],
            'external-payout-confirmer' => ['finance.view', 'finance.external-payout.confirm', 'finance.external-payout.reconcile', 'finance.external-payout.details'],
            'wallet-clearance-reviewer' => ['finance.view', 'finance.wallet-clearance.approve'],
            'wallet-clearance-operator' => ['finance.view', 'finance.wallet-clearance.execute', 'finance.wallet-clearance.details'],
            'wallet-clearance-confirmer' => ['finance.view', 'finance.wallet-clearance.confirm', 'finance.wallet-clearance.reconcile', 'finance.wallet-clearance.details'],
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

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            'customer-viewer' => '客户资料查看',
            'customer-operator' => '客户基础管理',
            'settings-viewer' => '系统配置查看', 'settings-payment' => '支付接口配置',
            'settings-notification' => '邮件与短信接口配置', 'settings-identity' => '实名与登录接口配置',
            'settings-site' => '站点信息配置', 'settings-billing' => '计费与调度参数配置',
            'inventory-viewer' => '库存查看', 'inventory-operator' => '库存操作',
            'upstream-viewer' => '上游查看', 'upstream-operator' => '上游配置与导入',
            'upstream-lifecycle' => '服务暂停与终止', 'upstream-migrator' => '服务换绑',
            'upstream-mapping-reviewer' => '配置映射核准', 'upstream-orphan-reviewer' => '旧资源结案',
            'cms-viewer' => '内容查看', 'cms-editor' => '内容编辑', 'cms-publisher' => '内容发布',
            'finance-viewer' => '财务查看', 'finance-operator' => '全额退款', 'finance-payout' => '佣金转钱包审批',
            'finance-wallet-adjuster' => '客户钱包加扣款', 'finance-offline-receipt' => '普通线下收款登记',
            'finance-receivable' => '应收清偿与冲正', 'finance-receivable-reviewer' => '应收证据复核',
            'finance-reconciliation' => '原收款补记与核对', 'finance-wallet-settings' => '充值设置',
            'external-payout-reviewer' => '外部佣金提现审批', 'external-payout-operator' => '外部佣金转账执行',
            'external-payout-confirmer' => '外部佣金付款确认',
            'wallet-clearance-reviewer' => '钱包结清审批', 'wallet-clearance-operator' => '钱包结清转账执行',
            'wallet-clearance-confirmer' => '钱包结清付款确认',
            'promotion-viewer' => '优惠券查看', 'promotion-operator' => '优惠券管理',
            'affiliate-viewer' => '推广查看', 'affiliate-operator' => '佣金规则管理',
            'support-viewer' => '工单查看', 'support-agent' => '工单处理',
            'notification-viewer' => '通知查看', 'notification-operator' => '通知管理',
            'download-viewer' => '下载资源查看', 'download-operator' => '下载资源管理',
            'task-viewer' => '交付任务查看', 'audit-viewer' => '操作审计查看', 'access-manager' => '账户角色管理',
        ];
    }

    /** @return array<string, array{label: string, roles: list<string>}> */
    public static function presets(): array
    {
        return [
            'operations' => ['label' => '日常运营', 'roles' => ['customer-operator', 'inventory-operator', 'cms-publisher', 'promotion-operator',
                'affiliate-operator', 'support-agent', 'notification-operator', 'download-operator', 'task-viewer']],
            'finance_readonly' => ['label' => '财务只读', 'roles' => ['customer-viewer', 'finance-viewer', 'affiliate-viewer', 'audit-viewer']],
            'upstream' => ['label' => '上游运维', 'roles' => ['upstream-operator', 'upstream-lifecycle', 'task-viewer']],
            'all_business' => ['label' => '全部业务权限（含退款和出款）', 'roles' => self::webAssignable()],
        ];
    }
}
