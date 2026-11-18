<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class WebRateLimits
{
    public static function register(): void
    {
        foreach (['login' => 10, 'register' => 10, 'password-email' => 5, 'password-reset' => 10,
            'oauth-begin' => 10, 'oauth-callback' => 10, 'telegram-widget' => 10, 'email-verify' => 3,
            'oauth-unlink' => 5, 'telegram-binding' => 5, 'phone-send' => 5, 'phone-confirm' => 10,
            'kyc-start' => 3, 'kyc-authorize' => 10, 'kyc-refresh' => 10, 'quote' => 30,
            'panel-login' => 10, 'telemetry' => 10, 'power-status' => 10, 'machine-read' => 10, 'machine-command' => 10,
            'machine-review' => 30, 'machine-reconcile' => 30, 'download' => 60, 'coupon-claim' => 10,
            'withdrawal' => 10, 'wallet-topup' => 10, 'account-close' => 5, 'external-payout' => 10,
            'wallet-clearance' => 5, 'entitlement-archive' => 5,
            'mapping-review' => 10, 'mapping-price' => 5, 'reconciliation' => 10,
            'ticket-create' => 10, 'ticket-update' => 20] as $business => $perMinute) {
            RateLimiter::for($business, fn (Request $request) => Limit::perMinute($perMinute)->by($business.':'.($request->user() === null
                ? 'ip:'.$request->ip() : 'user:'.$request->user()->getAuthIdentifier())));
        }
    }
}
