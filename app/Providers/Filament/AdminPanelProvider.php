<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\AdminAccess;
use App\Filament\Pages\AdminAudit;
use App\Filament\Pages\AdminTasks;
use App\Filament\Pages\AffiliateOperations;
use App\Filament\Pages\AffiliatePayouts;
use App\Filament\Pages\CmsContent;
use App\Filament\Pages\DownloadOperations;
use App\Filament\Pages\ExternalAffiliatePayouts;
use App\Filament\Pages\FinanceInvoice;
use App\Filament\Pages\FinanceInvoices;
use App\Filament\Pages\FinanceLedger;
use App\Filament\Pages\FinanceReceivables;
use App\Filament\Pages\FulfillmentQueue;
use App\Filament\Pages\InventoryCards;
use App\Filament\Pages\InventoryOverview;
use App\Filament\Pages\MachineOperationReview;
use App\Filament\Pages\NotificationOperations;
use App\Filament\Pages\NotificationOutbox;
use App\Filament\Pages\PromotionCoupons;
use App\Filament\Pages\ReconciliationCases;
use App\Filament\Pages\SupportTickets;
use App\Filament\Pages\SystemSettings;
use App\Filament\Pages\UpstreamAccounts;
use App\Filament\Pages\UpstreamInstances;
use App\Filament\Pages\WalletClearances;
use App\Http\Middleware\EnsureInstalledMiddleware;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->default()->id('admin')->path('admin')->login()->authGuard('web')
            ->brandName('产品运营')->homeUrl(function (): string {
                foreach (['promotion.view' => '/admin/coupons', 'affiliate.view' => '/admin/affiliates', 'support.view' => '/admin/support', 'notification.view' => '/admin/notifications', 'download.view' => '/admin/downloads', 'inventory.view' => '/admin/inventory', 'upstream.view' => '/admin/upstreams', 'cms.view' => '/admin/content',
                    'finance.view' => '/admin/finance', 'tasks.view' => '/admin/tasks', 'access.view' => '/admin/access', 'audit.view' => '/admin/audit', 'settings.view' => '/admin/settings'] as $ability => $url) {
                    if (auth()->user()?->can($ability)) {
                        return $url;
                    }
                }

                return '/admin/login';
            })
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn (): string => Blade::render('@livewireStyles'))
            ->renderHook(PanelsRenderHook::SCRIPTS_AFTER, fn (): string => Blade::render('@livewireScripts'))
            ->pages([PromotionCoupons::class, AffiliateOperations::class, AffiliatePayouts::class, SupportTickets::class, NotificationOperations::class, NotificationOutbox::class, DownloadOperations::class, InventoryOverview::class, InventoryCards::class, FulfillmentQueue::class, UpstreamAccounts::class, UpstreamInstances::class, MachineOperationReview::class, CmsContent::class,
                FinanceInvoices::class, FinanceInvoice::class, FinanceLedger::class, FinanceReceivables::class, ExternalAffiliatePayouts::class, WalletClearances::class, ReconciliationCases::class, AdminTasks::class, AdminAccess::class, AdminAudit::class, SystemSettings::class])
            ->middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class,
                AuthenticateSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class,
                SubstituteBindings::class, DisableBladeIconComponents::class, DispatchServingFilamentEvent::class,
                EnsureInstalledMiddleware::class])
            ->authMiddleware([Authenticate::class]);
    }
}
