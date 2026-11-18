<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\OpenInstancePanelAction;
use App\Actions\ReadInstancePowerStatusAction;
use App\Actions\ReadInstanceTelemetryAction;
use App\Models\Instance;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CustomerInstancePanelController extends Controller
{
    public function powerStatus(Request $request, Instance $instance, ReadInstancePowerStatusAction $read): Response|JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $status = $read->execute($user, $instance) + ['checked_at' => now()->toIso8601String()];
        $response = $request->expectsJson()
            ? response()->json($status, $status['available'] ? 200 : 502)
            : response()->view('sales.power-status', ['instance' => $instance, 'powerStatus' => $status]);

        return $response->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function telemetry(Request $request, Instance $instance, ReadInstanceTelemetryAction $read): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->view('sales.telemetry', ['instance' => $instance, 'widgets' => $read->execute($user, $instance)])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function open(Request $request, Instance $instance, OpenInstancePanelAction $open): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $panel = $open->execute($user, $instance);
        $nonce = base64_encode(random_bytes(24));
        $url = parse_url($panel['action']);
        $origin = 'https://'.$url['host'].(isset($url['port']) ? ':'.$url['port'] : '');

        return response()->view('sales.panel-login', ['panel' => $panel, 'nonce' => $nonce])
            ->header('Cache-Control', 'private, no-store, max-age=0')->header('Pragma', 'no-cache')
            ->header('Referrer-Policy', 'no-referrer')->header('X-Frame-Options', 'DENY')
            ->header('Content-Security-Policy', "default-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action {$origin}; script-src 'nonce-{$nonce}'");
    }
}
