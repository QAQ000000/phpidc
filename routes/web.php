<?php

declare(strict_types=1);

use App\Http\Controllers\AdminOperationsController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\ClassicOperationsController;
use App\Http\Controllers\CmsOperationsController;
use App\Http\Controllers\CustomerAuthController;
use App\Http\Controllers\CustomerIdentityController;
use App\Http\Controllers\CustomerKycController;
use App\Http\Controllers\CustomerOperationsController;
use App\Http\Controllers\CustomerSalesController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\InventoryOperationsController;
use App\Http\Controllers\OperationsAdminController;
use App\Http\Controllers\PublicContentController;
use App\Http\Controllers\ServiceBillingController;
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
Route::post('/login', [CustomerAuthController::class, 'login'])->middleware(['guest', 'throttle:10,1']);
Route::get('/register', [CustomerAuthController::class, 'registrationForm'])->middleware('guest')->name('register');
Route::post('/register', [CustomerAuthController::class, 'register'])->middleware(['guest', 'throttle:10,1']);
Route::post('/logout', [CustomerAuthController::class, 'logout']);
Route::get('/forgot-password', [CustomerIdentityController::class, 'forgot'])->name('password.request');
Route::post('/forgot-password', [CustomerIdentityController::class, 'sendReset'])->middleware('throttle:5,1');
Route::get('/reset-password/{token}', [CustomerIdentityController::class, 'resetForm'])->name('password.reset');
Route::post('/reset-password', [CustomerIdentityController::class, 'reset'])->middleware('throttle:10,1');
Route::post('/auth/{provider}/redirect', [CustomerIdentityController::class, 'socialBegin'])->middleware('throttle:10,1');
Route::get('/auth/telegram/widget', [CustomerIdentityController::class, 'telegramWidget'])->middleware('throttle:10,1');
Route::get('/auth/{provider}/callback', [CustomerIdentityController::class, 'socialCallback'])->middleware('throttle:10,1');
Route::middleware('auth')->group(function (): void {
    Route::get('/console/account', [CustomerIdentityController::class, 'account']);
    Route::post('/email/verification-notification', [CustomerIdentityController::class, 'sendVerification'])->middleware('throttle:3,1');
    Route::get('/email/verify/{id}/{hash}', [CustomerIdentityController::class, 'verify'])->middleware('signed:relative')->name('verification.verify');
    Route::post('/console/account/oauth/{provider}/unlink', [CustomerIdentityController::class, 'unlink'])->middleware('throttle:5,1');
    Route::post('/console/account/telegram/notifications', [CustomerIdentityController::class, 'telegramNotifications'])->middleware('throttle:5,1');
    Route::post('/console/account/phone/send', [CustomerIdentityController::class, 'sendPhone'])->middleware('throttle:5,1');
    Route::post('/console/account/phone/confirm', [CustomerIdentityController::class, 'confirmPhone'])->middleware('throttle:10,1');
    Route::post('/console/account/kyc', [CustomerKycController::class, 'initiate'])->middleware('throttle:3,1');
    Route::get('/console/account/kyc/return', [CustomerKycController::class, 'returned']);
    Route::post('/console/account/kyc/{verification}/authorize', [CustomerKycController::class, 'authorization'])->middleware('throttle:10,1');
    Route::post('/console/account/kyc/{verification}/refresh', [CustomerKycController::class, 'refresh'])->middleware('throttle:10,1');
});
Route::middleware('auth')->group(function (): void {
    Route::get('/console', [CustomerSalesController::class, 'index']);
    Route::get('/console/invoices', [CustomerSalesController::class, 'invoices']);
    Route::get('/console/instances', [CustomerSalesController::class, 'instances']);
    Route::get('/console/invoices/{invoice}', [CustomerSalesController::class, 'invoice']);
    Route::post('/console/invoices/{invoice}/pay', [CustomerSalesController::class, 'pay']);
    Route::get('/console/instances/{instance}', [CustomerSalesController::class, 'instance']);
    Route::get('/console/products/{product}/configure', [ServiceBillingController::class, 'configure']);
    Route::post('/console/products/{product}/quote', [ServiceBillingController::class, 'quote'])->middleware('throttle:30,1');
    Route::post('/console/instances/{instance}/operations', [ServiceBillingController::class, 'bill']);
});
Route::post('/admin/upstreams/configure', [ClassicOperationsController::class, 'configure']);
Route::post('/admin/upstreams/catalog', [ClassicOperationsController::class, 'catalog']);
Route::post('/admin/upstreams/import', [ClassicOperationsController::class, 'import']);
Route::post('/admin/upstream-options/{group}/bounds', [ClassicOperationsController::class, 'bounds']);
Route::post('/admin/upstream-tasks/{task}/retry', [ClassicOperationsController::class, 'retry']);
Route::post('/admin/upstreams/{product}/sync', [ClassicOperationsController::class, 'sync']);
Route::post('/admin/upstream-instances/{instance}/control', [ClassicOperationsController::class, 'control']);
Route::post('/admin/upstream-instances/{instance}/hot-swap', [ClassicOperationsController::class, 'hotSwap']);

// Explicit POST endpoints: authorization is enforced again by the transactional Actions.
Route::post('/admin/inventory/import', [InventoryOperationsController::class, 'import'])->name('inventory.import');
Route::post('/admin/access/{target}/role', [AdminOperationsController::class, 'role']);
Route::post('/admin/finance/invoices/{invoice}/refund', [AdminOperationsController::class, 'refund']);
Route::post('/admin/tasks/{task}/retry', [AdminOperationsController::class, 'retry']);
Route::post('/admin/fulfillment/{task}/retry', [InventoryOperationsController::class, 'retry'])->name('inventory.retry');
Route::post('/admin/content/articles', [CmsOperationsController::class, 'saveArticle']);
Route::post('/admin/content/categories', [CmsOperationsController::class, 'saveCategory']);
Route::post('/admin/content/products/{product}', [CmsOperationsController::class, 'saveProduct']);
Route::get('/admin/content/articles/{article}/preview', [CmsOperationsController::class, 'preview']);

// Operations expansion: customer ownership and admin authority are checked in Actions.
Route::get('/r/{user}', [CustomerOperationsController::class, 'referral'])->middleware('signed:relative')->name('affiliate.referral');
Route::get('/downloads', [CustomerOperationsController::class, 'downloads']);
Route::get('/downloads/{resource}', [CustomerOperationsController::class, 'download'])->middleware('throttle:60,1');
Route::middleware('auth')->controller(CustomerOperationsController::class)->group(function (): void {
    Route::get('/console/coupons', 'coupons');
    Route::post('/console/coupons', 'claim')->middleware('throttle:10,1');
    Route::get('/console/affiliate', 'affiliate');
    Route::post('/console/affiliate/withdraw', 'withdraw')->middleware('throttle:10,1');
    Route::get('/console/tickets', 'tickets');
    Route::post('/console/tickets', 'createTicket')->middleware('throttle:10,1');
    Route::get('/console/tickets/{ticket}', 'ticket');
    Route::post('/console/tickets/{ticket}', 'updateTicket')->middleware('throttle:20,1');
});
Route::controller(OperationsAdminController::class)->group(function (): void {
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
