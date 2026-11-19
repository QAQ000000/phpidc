<?php

declare(strict_types=1);

namespace App\Support;

use App\Filament\Pages\AdminAccess;
use App\Filament\Pages\AdminAudit;
use App\Filament\Pages\AdminTasks;
use App\Filament\Pages\AffiliateOperations;
use App\Filament\Pages\AffiliatePayouts;
use App\Filament\Pages\CmsContent;
use App\Filament\Pages\DownloadOperations;
use App\Filament\Pages\ExternalAffiliatePayouts;
use App\Filament\Pages\FinanceInvoices;
use App\Filament\Pages\FinanceLedger;
use App\Filament\Pages\FinanceReceivables;
use App\Filament\Pages\FulfillmentQueue;
use App\Filament\Pages\InventoryCards;
use App\Filament\Pages\InventoryOverview;
use App\Filament\Pages\NotificationOperations;
use App\Filament\Pages\NotificationOutbox;
use App\Filament\Pages\PromotionCoupons;
use App\Filament\Pages\ReconciliationCases;
use App\Filament\Pages\SupportTickets;
use App\Filament\Pages\SystemSettings;
use App\Filament\Pages\UpstreamAccounts;
use App\Filament\Pages\UpstreamInstances;
use App\Filament\Pages\WalletClearances;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;

use function Filament\Support\original_request;

class AdminNavigation
{
    public function build(NavigationBuilder $builder): NavigationBuilder
    {
        $activeUrl = $this->activeUrl();
        $groups = [];

        foreach ([
            '商品与服务' => [
                ['库存概览', '/admin/inventory', InventoryOverview::class],
                ['卡密库存', '/admin/inventory/cards', InventoryCards::class],
                ['已付待交付', '/admin/fulfillment', FulfillmentQueue::class],
                ['上游商品与连接', '/admin/upstreams', UpstreamAccounts::class],
                ['上游服务', '/admin/upstream-instances', UpstreamInstances::class],
            ],
            '财务与结算' => [
                ['财务账单', '/admin/finance', FinanceInvoices::class],
                ['流水与支付回调', '/admin/finance/ledger', FinanceLedger::class],
                ['退款佣金应收', '/admin/finance/receivables', FinanceReceivables::class],
                ['钱包结清', '/admin/wallet-clearances', WalletClearances::class],
                ['外部佣金提现', '/admin/external-payouts', ExternalAffiliatePayouts::class],
                ['佣金转钱包审核', '/admin/payouts', AffiliatePayouts::class],
                ['支付方式', '/admin/settings?tab=payment-plugins', SystemSettings::class],
                ['计费策略', '/admin/settings?tab=billing', SystemSettings::class],
            ],
            '运营与内容' => [
                ['优惠券', '/admin/coupons', PromotionCoupons::class],
                ['推广规则与佣金', '/admin/affiliates', AffiliateOperations::class],
                ['工单处理', '/admin/support', SupportTickets::class],
                ['商城与内容', '/admin/content', CmsContent::class],
                ['下载资源', '/admin/downloads', DownloadOperations::class],
            ],
            '消息与通知' => [
                ['通知模板与日志', '/admin/notifications', NotificationOperations::class],
                ['通知事件与投递', '/admin/notification-outbox', NotificationOutbox::class],
                ['邮件插件', '/admin/settings?tab=mail-plugins', SystemSettings::class],
                ['短信插件', '/admin/settings?tab=sms-plugins', SystemSettings::class],
            ],
            '系统设置' => [
                ['站点信息', '/admin/settings?tab=site', SystemSettings::class],
                ['实名认证', '/admin/settings?tab=kyc-plugins', SystemSettings::class],
                ['第三方登录', '/admin/settings?tab=login-plugins', SystemSettings::class],
                ['自动化策略', '/admin/settings?tab=automation', SystemSettings::class],
            ],
            '系统运维' => [
                ['任务监控与恢复', '/admin/tasks', AdminTasks::class],
                ['业务异常核对', '/admin/reconciliations', ReconciliationCases::class],
                ['账户角色管理', '/admin/access', AdminAccess::class],
                ['操作审计', '/admin/audit', AdminAudit::class],
            ],
        ] as $label => $entries) {
            $items = [];
            $active = false;

            foreach ($entries as [$title, $url, $page]) {
                if (! $page::canAccess()) {
                    continue;
                }

                $isActive = $activeUrl === $url;
                $active = $active || $isActive;
                $items[] = NavigationItem::make($title)
                    ->key($url)
                    ->url($url)
                    ->activeWhen(fn (): bool => $isActive);
            }

            if ($items !== []) {
                $groups[] = NavigationGroup::make($label)->items($items)
                    ->collapsed(! $active)->collapsible();
            }
        }

        return $builder->groups($groups);
    }

    private function activeUrl(): string
    {
        $request = original_request();
        $path = '/'.$request->path();

        if ($path === '/admin/settings') {
            $tab = $request->query('tab', 'payment');
            $tab = is_string($tab) ? $tab : 'payment';
            $tab = match ($tab) {
                'payment', 'payment-plugins' => 'payment-plugins',
                'mail', 'mail-plugins' => 'mail-plugins',
                'sms', 'sms-plugins' => 'sms-plugins',
                'kyc', 'kyc-plugins' => 'kyc-plugins',
                'github', 'google', 'qq', 'wechat', 'telegram', 'login-plugins' => 'login-plugins',
                'site', 'billing', 'automation', 'upstreams' => $tab,
                default => 'payment-plugins',
            };

            return $tab === 'upstreams' ? '/admin/upstreams' : '/admin/settings?tab='.$tab;
        }

        if ($path === '/admin/finance/invoice') {
            return '/admin/finance';
        }

        if ($path === '/admin/machine-operation-review'
            || preg_match('#^/admin/upstream-instances/[^/]+/mapping-review(?:/check)?$#', $path) === 1) {
            return '/admin/upstream-instances';
        }

        return $path;
    }
}
