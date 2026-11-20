<?php

declare(strict_types=1);

namespace App\Support;

use App\Filament\Pages\AdminAccess;
use App\Filament\Pages\AdminAudit;
use App\Filament\Pages\AdminCustomers;
use App\Filament\Pages\AdminDashboard;
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

        if (AdminDashboard::canAccess()) {
            $builder->item(NavigationItem::make('经营总览')->key('/admin')->icon('heroicon-o-home')
                ->url('/admin')->activeWhen(fn (): bool => $activeUrl === '/admin'));
        }

        foreach ([
            '客户与客服' => [
                ['客户管理', '/admin/customers', AdminCustomers::class, 'heroicon-o-user-circle'],
                ['工单处理', '/admin/support', SupportTickets::class, 'heroicon-o-chat-bubble-left-right'],
            ],
            '商品与服务' => [
                ['服务实例', '/admin/upstream-instances', UpstreamInstances::class, 'heroicon-o-server-stack'],
                ['商品与上游', '/admin/upstreams', UpstreamAccounts::class, 'heroicon-o-cloud-arrow-down'],
                ['卡密库存', '/admin/inventory/cards', InventoryCards::class, 'heroicon-o-key'],
                ['待交付队列', '/admin/fulfillment', FulfillmentQueue::class, 'heroicon-o-truck'],
                ['库存概览', '/admin/inventory', InventoryOverview::class, 'heroicon-o-squares-2x2'],
            ],
            '财务' => [
                ['账单管理', '/admin/finance', FinanceInvoices::class, 'heroicon-o-document-text'],
                ['流水与回调', '/admin/finance/ledger', FinanceLedger::class, 'heroicon-o-banknotes'],
                ['应收管理', '/admin/finance/receivables', FinanceReceivables::class, 'heroicon-o-receipt-refund'],
                ['钱包结清', '/admin/wallet-clearances', WalletClearances::class, 'heroicon-o-wallet'],
                ['外部佣金提现', '/admin/external-payouts', ExternalAffiliatePayouts::class, 'heroicon-o-arrow-top-right-on-square'],
                ['佣金转钱包审核', '/admin/payouts', AffiliatePayouts::class, 'heroicon-o-arrows-right-left'],
                ['业务异常核对', '/admin/reconciliations', ReconciliationCases::class, 'heroicon-o-clipboard-document-check'],
            ],
            '运营' => [
                ['优惠券', '/admin/coupons', PromotionCoupons::class, 'heroicon-o-ticket'],
                ['推广返佣', '/admin/affiliates', AffiliateOperations::class, 'heroicon-o-user-group'],
                ['内容管理', '/admin/content', CmsContent::class, 'heroicon-o-document-duplicate'],
                ['下载资源', '/admin/downloads', DownloadOperations::class, 'heroicon-o-arrow-down-tray'],
            ],
            '系统配置' => [
                ['站点信息', '/admin/settings?tab=site', SystemSettings::class, 'heroicon-o-globe-alt'],
                ['支付插件', '/admin/settings?tab=payment-plugins', SystemSettings::class, 'heroicon-o-credit-card'],
                ['计费策略', '/admin/settings?tab=billing', SystemSettings::class, 'heroicon-o-calculator'],
                ['邮件插件', '/admin/settings?tab=mail-plugins', SystemSettings::class, 'heroicon-o-envelope'],
                ['短信插件', '/admin/settings?tab=sms-plugins', SystemSettings::class, 'heroicon-o-device-phone-mobile'],
                ['实名接口', '/admin/settings?tab=kyc-plugins', SystemSettings::class, 'heroicon-o-identification'],
                ['第三方登录', '/admin/settings?tab=login-plugins', SystemSettings::class, 'heroicon-o-finger-print'],
                ['自动化策略', '/admin/settings?tab=automation', SystemSettings::class, 'heroicon-o-cog-6-tooth'],
            ],
            '通知与运维' => [
                ['通知模板与日志', '/admin/notifications', NotificationOperations::class, 'heroicon-o-bell'],
                ['通知事件与投递', '/admin/notification-outbox', NotificationOutbox::class, 'heroicon-o-inbox-stack'],
                ['任务监控与恢复', '/admin/tasks', AdminTasks::class, 'heroicon-o-queue-list'],
                ['管理员与角色', '/admin/access', AdminAccess::class, 'heroicon-o-users'],
                ['操作审计', '/admin/audit', AdminAudit::class, 'heroicon-o-shield-check'],
            ],
        ] as $label => $entries) {
            $items = [];
            $active = false;

            foreach ($entries as [$title, $url, $page, $icon]) {
                if (! $page::canAccess()) {
                    continue;
                }

                $isActive = $activeUrl === $url;
                $active = $active || $isActive;
                $items[] = NavigationItem::make($title)
                    ->key($url)
                    ->icon($icon)
                    ->url($url)
                    ->activeWhen(fn (): bool => $isActive);
            }

            if ($items !== []) {
                $groupIcon = match ($label) {
                    '客户与客服' => 'heroicon-o-users',
                    '商品与服务' => 'heroicon-o-cube',
                    '财务' => 'heroicon-o-banknotes',
                    '运营' => 'heroicon-o-megaphone',
                    '系统配置' => 'heroicon-o-cog-6-tooth',
                    '通知与运维' => 'heroicon-o-wrench-screwdriver',
                };
                $groups[] = NavigationGroup::make($label)->icon($groupIcon)->items($items)
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

        if (preg_match('#^/admin/customers/[1-9][0-9]*$#', $path) === 1) {
            return '/admin/customers';
        }

        if ($path === '/admin/upstreams/catalog') {
            return '/admin/upstreams';
        }

        if ($path === '/admin/machine-operation-review'
            || preg_match('#^/admin/instances/[1-9][0-9]*(?:/tasks/[1-9][0-9]*/retry)?$#', $path) === 1
            || preg_match('#^/admin/upstream-instances/[1-9][0-9]*/(?:machine(?:/command)?|power-status|sync)$#', $path) === 1
            || preg_match('#^/admin/upstream-instances/[^/]+/mapping-review(?:/check)?$#', $path) === 1) {
            return '/admin/upstream-instances';
        }

        return $path;
    }
}
