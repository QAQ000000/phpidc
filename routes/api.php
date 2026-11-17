<?php

declare(strict_types=1);

use App\Http\Controllers\PaymentCallbackController;
use App\Http\Controllers\UpstreamSyncController;
use Illuminate\Support\Facades\Route;

// Upstream callback. Lives in the api group so it is not subject to CSRF or
// session middleware: the payload is authenticated by the 3-field MD5
// signature and a scoped replay key (zjmf-api-spec §5, INV-Q4).
Route::post('/host/sync', [UpstreamSyncController::class, 'sync'])
    ->name('api.host.sync');

Route::match(['get', 'post'], '/payments/{gateway}/notify', PaymentCallbackController::class)
    ->where('gateway', 'epay_wechat|epay_alipay')->name('payments.notify');
