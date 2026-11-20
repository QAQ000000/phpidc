<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\AdminAccess;
use App\Filament\Pages\AdminAudit;
use App\Filament\Pages\AdminCustomer;
use App\Filament\Pages\AdminCustomers;
use App\Filament\Pages\AdminDashboard;
use App\Filament\Pages\AdminTasks;
use App\Filament\Pages\AffiliateOperations;
use App\Filament\Pages\AffiliatePayouts;
use App\Filament\Pages\ClassicCatalog;
use App\Filament\Pages\CmsContent;
use App\Filament\Pages\DownloadOperations;
use App\Filament\Pages\ExternalAffiliatePayouts;
use App\Filament\Pages\FinanceInvoice;
use App\Filament\Pages\FinanceInvoices;
use App\Filament\Pages\FinanceLedger;
use App\Filament\Pages\FinanceReceivables;
use App\Filament\Pages\FulfillmentQueue;
use App\Filament\Pages\InstanceMappingReview;
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
use App\Http\Middleware\EnsureAccountAccess;
use App\Http\Middleware\EnsureInstalledMiddleware;
use App\Support\AdminNavigation;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationBuilder;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
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
            ->brandName('产品运营')->favicon('/favicon.svg')->homeUrl('/admin')
            ->colors(['primary' => '#2f54ea'])->maxContentWidth('full')->sidebarCollapsibleOnDesktop()
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn (): string => Blade::render('@livewireStyles<link rel="stylesheet" href="/css/admin-shell.css?v=20261120">'))
            ->renderHook(PanelsRenderHook::SCRIPTS_AFTER, fn (): string => Blade::render('@livewireScripts'))
            ->renderHook(PanelsRenderHook::TOPBAR_START, fn (): View => view('filament.admin-global-search'))
            ->navigation(fn (NavigationBuilder $builder): NavigationBuilder => app(AdminNavigation::class)->build($builder))
            ->pages([AdminDashboard::class, AdminCustomers::class, AdminCustomer::class, PromotionCoupons::class, AffiliateOperations::class, AffiliatePayouts::class, SupportTickets::class, NotificationOperations::class, NotificationOutbox::class, DownloadOperations::class, InventoryOverview::class, InventoryCards::class, FulfillmentQueue::class, UpstreamAccounts::class, UpstreamInstances::class, ClassicCatalog::class, MachineOperationReview::class, InstanceMappingReview::class, CmsContent::class,
                FinanceInvoices::class, FinanceInvoice::class, FinanceLedger::class, FinanceReceivables::class, ExternalAffiliatePayouts::class, WalletClearances::class, ReconciliationCases::class, AdminTasks::class, AdminAccess::class, AdminAudit::class, SystemSettings::class])
            ->middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class,
                AuthenticateSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class,
                SubstituteBindings::class, DisableBladeIconComponents::class, DispatchServingFilamentEvent::class,
                EnsureInstalledMiddleware::class, EnsureAccountAccess::class])
            ->authMiddleware([Authenticate::class]);
    }
}
