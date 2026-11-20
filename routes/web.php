<?php

declare(strict_types=1);

use App\Http\Controllers\AdminCustomerController;
use App\Http\Controllers\AdminFinancialFallbackController;
use App\Http\Controllers\AdminInstanceOperationsController;
use App\Http\Controllers\AdminOperationsController;
use App\Http\Controllers\AdminSearchController;
use App\Http\Controllers\AffiliateReceivableController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\ClassicOperationsController;
use App\Http\Controllers\CmsOperationsController;
use App\Http\Controllers\CustomerAccountClosureController;
use App\Http\Controllers\CustomerAuthController;
use App\Http\Controllers\CustomerEntitlementArchiveController;
use App\Http\Controllers\CustomerIdentityController;
use App\Http\Controllers\CustomerInstanceMappingPriceController;
use App\Http\Controllers\CustomerInstancePanelController;
use App\Http\Controllers\CustomerKycController;
use App\Http\Controllers\CustomerMachineController;
use App\Http\Controllers\CustomerOperationsController;
use App\Http\Controllers\CustomerSalesController;
use App\Http\Controllers\ExternalAffiliatePayoutController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\InstanceMappingReviewController;
use App\Http\Controllers\InventoryOperationsController;
use App\Http\Controllers\MachineOperationReviewController;
use App\Http\Controllers\NotificationOutboxController;
use App\Http\Controllers\OperationsAdminController;
use App\Http\Controllers\PublicContentController;
use App\Http\Controllers\ReceivableCheckController;
use App\Http\Controllers\ReconciliationController;
use App\Http\Controllers\RenewalRecoveryController;
use App\Http\Controllers\ServiceBillingController;
use App\Http\Controllers\Sub2ApiSettingsController;
use App\Http\Controllers\SystemSettingsController;
use App\Http\Controllers\WalletClearanceController;
use App\Http\Controllers\WalletTopupController;
use App\Http\Controllers\WalletTopupSettingsController;
use App\Http\Middleware\NegotiateMarkdown;
use Illuminate\Support\Facades\Route;

Route::get('/', [PublicContentController::class, 'home'])->name('home');
Route::get('/store', [PublicContentController::class, 'store'])->name('store.index');
Route::get('/store/{category}', [PublicContentController::class, 'store'])->name('store.category');
Route::get('/store/{category}/{product}.md', [PublicContentController::class, 'product'])->middleware(NegotiateMarkdown::class);
Route::get('/store/{category}/{product}', [PublicContentController::class, 'product'])->middleware(NegotiateMarkdown::class)->name('store.product');
Route::get('/{type}', [PublicContentController::class, 'articles'])->whereIn('type', ['news', 'help'])->name('cms.index');
Route::get('/{type}/{category}', [PublicContentController::class, 'articles'])->whereIn('type', ['news', 'help'])->name('cms.category');
Route::get('/{type}/{category}/{article}.md', [PublicContentController::class, 'article'])->whereIn('type', ['news', 'help'])->middleware(NegotiateMarkdown::class);
Route::get('/{type}/{category}/{article}', [PublicContentController::class, 'article'])->whereIn('type', ['news', 'help'])->middleware(NegotiateMarkdown::class)->name('cms.article');
Route::get('/sitemap.xml', [PublicContentController::class, 'sitemap']);
Route::get('/llms.txt', [PublicContentController::class, 'llms']);

// Web installer (charter §3.6). Exempt from the install redirect; returns 404
// once installed.lock exists.
Route::get('/install', [InstallController::class, 'show'])->name('install.show');
Route::post('/install', [InstallController::class, 'store'])->name('install.store');

Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout.store');

Route::get('/login', [CustomerAuthController::class, 'form'])->middleware('guest')->name('login');
Route::post('/login', [CustomerAuthController::class, 'login'])->middleware(['guest', 'throttle:login']);
Route::get('/register', [CustomerAuthController::class, 'registrationForm'])->middleware('guest')->name('register');
Route::post('/register', [CustomerAuthController::class, 'register'])->middleware(['guest', 'throttle:register']);
Route::post('/logout', [CustomerAuthController::class, 'logout']);
Route::get('/forgot-password', [CustomerIdentityController::class, 'forgot'])->name('password.request');
Route::post('/forgot-password', [CustomerIdentityController::class, 'sendReset'])->middleware('throttle:password-email');
Route::get('/reset-password/{token}', [CustomerIdentityController::class, 'resetForm'])->name('password.reset');
Route::post('/reset-password', [CustomerIdentityController::class, 'reset'])->middleware('throttle:password-reset');
Route::post('/auth/{provider}/redirect', [CustomerIdentityController::class, 'socialBegin'])->middleware('throttle:oauth-begin');
Route::get('/auth/telegram/widget', [CustomerIdentityController::class, 'telegramWidget'])->middleware('throttle:telegram-widget');
Route::get('/auth/{provider}/callback', [CustomerIdentityController::class, 'socialCallback'])->middleware('throttle:oauth-callback');
Route::middleware('auth')->group(function (): void {
    Route::get('/console/receivable-checks/{user}/{action}/{requestId}', [ReceivableCheckController::class, 'show'])->withTrashed();
    Route::post('/console/receivable-checks/{user}/{action}/{requestId}', [ReceivableCheckController::class, 'retry'])->withTrashed()->middleware('throttle:receivable-check');
    Route::get('/console/account', [CustomerIdentityController::class, 'account']);
    Route::get('/console/account/close', [CustomerAccountClosureController::class, 'show']);
    Route::post('/console/account/close', [CustomerAccountClosureController::class, 'store'])->middleware('throttle:account-close');
    Route::post('/email/verification-notification', [CustomerIdentityController::class, 'sendVerification'])->middleware('throttle:email-verify');
    Route::get('/email/verify/{id}/{hash}', [CustomerIdentityController::class, 'verify'])->middleware('signed:relative')->name('verification.verify');
    Route::post('/console/account/oauth/{provider}/unlink', [CustomerIdentityController::class, 'unlink'])->middleware('throttle:oauth-unlink');
    Route::post('/console/account/telegram/notifications', [CustomerIdentityController::class, 'telegramNotifications'])->middleware('throttle:telegram-binding');
    Route::post('/console/account/phone/send', [CustomerIdentityController::class, 'sendPhone'])->middleware('throttle:phone-send');
    Route::post('/console/account/phone/confirm', [CustomerIdentityController::class, 'confirmPhone'])->middleware('throttle:phone-confirm');
    Route::post('/console/account/kyc', [CustomerKycController::class, 'initiate'])->middleware('throttle:kyc-start');
    Route::get('/console/account/kyc/return', [CustomerKycController::class, 'returned']);
    Route::post('/console/account/kyc/{verification}/authorize', [CustomerKycController::class, 'authorization'])->middleware('throttle:kyc-authorize');
    Route::post('/console/account/kyc/{verification}/refresh', [CustomerKycController::class, 'refresh'])->middleware('throttle:kyc-refresh');
});
Route::middleware('auth')->group(function (): void {
    Route::get('/admin/search', AdminSearchController::class);
    Route::post('/admin/customers/{customer}/profile', [AdminCustomerController::class, 'profile']);
    Route::post('/admin/customers/{customer}/password-reset', [AdminCustomerController::class, 'passwordReset'])->middleware('throttle:admin-customer-reset');
    Route::post('/admin/customers/{customer}/access', [AdminCustomerController::class, 'access']);
    Route::post('/admin/customers/{customer}/wallet-adjust', [AdminFinancialFallbackController::class, 'wallet']);
    Route::post('/admin/finance/invoices/{invoice}/offline-receipt', [AdminFinancialFallbackController::class, 'offline']);
    Route::post('/admin/finance/offline-receipts/{receipt}/details', [AdminFinancialFallbackController::class, 'details']);
    Route::get('/console', [CustomerSalesController::class, 'index']);
    Route::get('/console/wallet', [WalletTopupController::class, 'index']);
    Route::post('/console/wallet/topups', [WalletTopupController::class, 'store'])->middleware('throttle:wallet-topup');
    Route::get('/console/wallet/clearances', [WalletClearanceController::class, 'index']);
    Route::post('/console/wallet/clearances', [WalletClearanceController::class, 'store'])->middleware('throttle:wallet-clearance');
    Route::get('/console/affiliate/external', [ExternalAffiliatePayoutController::class, 'index']);
    Route::post('/console/affiliate/external', [ExternalAffiliatePayoutController::class, 'store'])->middleware('throttle:external-payout');
    Route::get('/console/invoices', [CustomerSalesController::class, 'invoices']);
    Route::get('/console/instances', [CustomerSalesController::class, 'instances']);
    Route::get('/console/invoices/{invoice}', [CustomerSalesController::class, 'invoice']);
    Route::post('/console/invoices/{invoice}/pay', [CustomerSalesController::class, 'pay']);
    Route::post('/console/invoices/{invoice}/renewal-recovery/quote', [RenewalRecoveryController::class, 'quote'])->middleware('throttle:quote');
    Route::post('/console/invoices/{invoice}/renewal-recovery', [RenewalRecoveryController::class, 'store']);
    Route::get('/console/instances/{instance}', [CustomerSalesController::class, 'instance']);
    Route::get('/console/instances/{instance}/archive', [CustomerEntitlementArchiveController::class, 'show']);
    Route::post('/console/instances/{instance}/archive', [CustomerEntitlementArchiveController::class, 'store'])->middleware('throttle:entitlement-archive');
    Route::get('/console/instances/{instance}/mapping-price', [CustomerInstanceMappingPriceController::class, 'show']);
    Route::post('/console/instances/{instance}/mapping-price/preview', [CustomerInstanceMappingPriceController::class, 'preview'])->middleware('throttle:quote');
    Route::post('/console/instances/{instance}/mapping-price', [CustomerInstanceMappingPriceController::class, 'store'])->middleware('throttle:mapping-price');
    Route::post('/console/instances/{instance}/panel-login', [CustomerInstancePanelController::class, 'open'])->middleware('throttle:panel-login');
    Route::get('/console/instances/{instance}/telemetry', [CustomerInstancePanelController::class, 'telemetryPage']);
    Route::post('/console/instances/{instance}/telemetry/chart/{chart}', [CustomerInstancePanelController::class, 'chart'])
        ->middleware('throttle:telemetry');
    Route::post('/console/instances/{instance}/telemetry', [CustomerInstancePanelController::class, 'telemetry'])->middleware('throttle:telemetry');
    Route::post('/console/instances/{instance}/power-status', [CustomerInstancePanelController::class, 'powerStatus'])->middleware('throttle:power-status');
    Route::post('/console/instances/{instance}/machine', [CustomerMachineController::class, 'show'])->middleware('throttle:machine-read');
    Route::post('/console/instances/{instance}/machine/command', [CustomerMachineController::class, 'execute'])->middleware('throttle:machine-command');
    Route::get('/console/instances/{instance}/vnc', [CustomerMachineController::class, 'vnc'])->middleware('signed:relative')->name('instance.vnc');
    Route::get('/console/products/{product}/configure', [ServiceBillingController::class, 'configure']);
    Route::post('/console/products/{product}/quote', [ServiceBillingController::class, 'quote'])->middleware('throttle:quote');
    Route::post('/console/instances/{instance}/operations', [ServiceBillingController::class, 'bill']);
});
Route::middleware('auth')->group(function (): void {
    Route::post('/admin/upstreams/configure', [ClassicOperationsController::class, 'configure']);
    Route::post('/admin/upstreams/sub2api/configure', [Sub2ApiSettingsController::class, 'store']);
    Route::post('/admin/settings/identity/{section}', [SystemSettingsController::class, 'identity']);
    Route::post('/admin/settings/payment', [SystemSettingsController::class, 'payment']);
    Route::post('/admin/settings/plugins/{category}/{plugin}/status', [SystemSettingsController::class, 'pluginStatus']);
    Route::post('/admin/settings/site', [SystemSettingsController::class, 'site']);
    Route::post('/admin/settings/policy/{section}', [SystemSettingsController::class, 'policy']);
    Route::post('/admin/settings/test/{channel}', [SystemSettingsController::class, 'test'])->middleware('throttle:settings-test');
    Route::post('/admin/upstreams/import', [ClassicOperationsController::class, 'import']);
    Route::post('/admin/upstream-options/{group}/bounds', [ClassicOperationsController::class, 'bounds']);
    Route::post('/admin/upstream-tasks/{task}/retry', [ClassicOperationsController::class, 'retry']);
    Route::post('/admin/upstreams/{product}/sync', [ClassicOperationsController::class, 'sync']);
    Route::post('/admin/upstream-instances/{instance}/control', [ClassicOperationsController::class, 'control']);
    Route::post('/admin/upstream-instances/{instance}/machine', [AdminInstanceOperationsController::class, 'controls'])->middleware('throttle:admin-machine-read');
    Route::post('/admin/upstream-instances/{instance}/machine/command', [AdminInstanceOperationsController::class, 'command'])->middleware('throttle:admin-machine-command');
    Route::post('/admin/upstream-instances/{instance}/power-status', [AdminInstanceOperationsController::class, 'power'])->middleware('throttle:admin-power-status');
    Route::post('/admin/upstream-instances/{instance}/sync', [AdminInstanceOperationsController::class, 'sync'])->middleware('throttle:admin-instance-sync');
    Route::post('/admin/instances/{instance}/tasks/{task}/retry', [AdminInstanceOperationsController::class, 'retry']);
    Route::get('/admin/instances/{instance}/vnc', [AdminInstanceOperationsController::class, 'vnc'])->middleware('signed:relative')->name('admin.instance.vnc');
    Route::post('/admin/upstream-instances/{instance}/machine-review', [MachineOperationReviewController::class, 'inspect'])->middleware('throttle:machine-review');
    Route::post('/admin/upstream-instances/{instance}/machine-reconcile', [MachineOperationReviewController::class, 'store'])->middleware('throttle:machine-reconcile');
    Route::post('/admin/upstream-instances/{instance}/hot-swap', [ClassicOperationsController::class, 'hotSwap']);
    Route::post('/admin/upstream-instances/{instance}/mapping-review', [InstanceMappingReviewController::class, 'store'])->middleware('throttle:mapping-review');

    // Explicit POST endpoints: authorization is enforced again by the transactional Actions.
    Route::post('/admin/inventory/import', [InventoryOperationsController::class, 'import'])->name('inventory.import');
    Route::post('/admin/access/{target}/role', [AdminOperationsController::class, 'role']);
    Route::post('/admin/access/{target}/roles', [AdminOperationsController::class, 'roles']);
    Route::post('/admin/finance/invoices/{invoice}/refund', [AdminOperationsController::class, 'refund']);
    Route::post('/admin/finance/wallet-topup-settings', [WalletTopupSettingsController::class, 'store']);
    Route::post('/admin/finance/receivables/{clawback}/settlements', [AffiliateReceivableController::class, 'store']);
    Route::post('/admin/finance/receivable-settlements/{settlement}/reverse', [AffiliateReceivableController::class, 'reverse']);
    Route::post('/admin/external-payouts/{withdrawal}', [ExternalAffiliatePayoutController::class, 'process']);
    Route::post('/admin/external-payouts/{withdrawal}/details', [ExternalAffiliatePayoutController::class, 'details']);
    Route::post('/admin/wallet-clearances/closed-account', [WalletClearanceController::class, 'closed'])->middleware('throttle:wallet-clearance');
    Route::post('/admin/wallet-clearances/{clearance}', [WalletClearanceController::class, 'process']);
    Route::post('/admin/wallet-clearances/{clearance}/details', [WalletClearanceController::class, 'details']);
    Route::post('/admin/reconciliations/{case}/payment', [ReconciliationController::class, 'payment'])->middleware('throttle:reconciliation');
    Route::post('/admin/reconciliations/{case}/binding', [ReconciliationController::class, 'binding'])->middleware('throttle:reconciliation');
    Route::post('/admin/reconciliations/{case}/reopen', [ReconciliationController::class, 'reopen'])->middleware('throttle:reconciliation');
    Route::post('/admin/reconciliations/{case}/evidence', [ReconciliationController::class, 'evidence']);
    Route::post('/admin/finance/receivables/{user}/review', [ReceivableCheckController::class, 'review'])->withTrashed()->middleware('throttle:receivable-check');
    Route::post('/admin/notification-outbox/{delivery}/resolve', [NotificationOutboxController::class, 'resolve']);
    Route::post('/admin/tasks/{task}/retry', [AdminOperationsController::class, 'retry']);
    Route::post('/admin/fulfillment/{task}/retry', [InventoryOperationsController::class, 'retry'])->name('inventory.retry');
    Route::post('/admin/content/articles', [CmsOperationsController::class, 'saveArticle']);
    Route::post('/admin/content/categories', [CmsOperationsController::class, 'saveCategory']);
    Route::post('/admin/content/products/{product}', [CmsOperationsController::class, 'saveProduct']);
    Route::get('/admin/content/articles/{article}/preview', [CmsOperationsController::class, 'preview']);
});

// Operations expansion: customer ownership and admin authority are checked in Actions.
Route::get('/r/{user}', [CustomerOperationsController::class, 'referral'])->middleware('signed:relative')->name('affiliate.referral');
Route::get('/downloads', [CustomerOperationsController::class, 'downloads']);
Route::get('/downloads/{resource}', [CustomerOperationsController::class, 'download'])->middleware('throttle:download');
Route::middleware('auth')->controller(CustomerOperationsController::class)->group(function (): void {
    Route::get('/console/coupons', 'coupons');
    Route::post('/console/coupons', 'claim')->middleware('throttle:coupon-claim');
    Route::get('/console/affiliate', 'affiliate');
    Route::post('/console/affiliate/withdraw', 'withdraw')->middleware('throttle:withdrawal');
    Route::get('/console/tickets', 'tickets');
    Route::post('/console/tickets', 'createTicket')->middleware('throttle:ticket-create');
    Route::get('/console/tickets/{ticket}', 'ticket');
    Route::post('/console/tickets/{ticket}', 'updateTicket')->middleware('throttle:ticket-update');
});
Route::middleware('auth')->controller(OperationsAdminController::class)->group(function (): void {
    Route::post('/admin/operations/config/{kind}', 'save');
    Route::post('/admin/operations/coupons/grant', 'grant');
    Route::post('/admin/operations/payouts/{withdrawal}', 'payout');
    Route::post('/admin/operations/tickets/{ticket}', 'ticket');
    Route::post('/admin/operations/downloads', 'download');
});

/*
 * Fallback: group middleware never runs when no route matches, so an
 * unmatched path would 404 before EnsureInstalledMiddleware could redirect.
 * Registering an explicit fallback keeps a pre-install system pointing at the
 * installer no matter what URL a visitor arrives on.
 */
Route::fallback(function () {
    if (! file_exists(storage_path('installed.lock'))) {
        return redirect('/install');
    }

    abort(404);
})->name('fallback');
