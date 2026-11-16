<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives `/api/host/sync` (zjmf-api-spec §5).
 *
 * Contract for Phase 2: verify the 3-field MD5 signature, claim the scoped
 * replay key `host_sync:{instance_id}:{rand_str}` with SET NX EX 600, then
 * respond immediately and reconcile in a queued job (INV-Q3). The upstream
 * round trip must never happen inline.
 *
 * Phase 0 keeps the endpoint closed rather than half-implemented: an
 * unauthenticated endpoint that silently accepts payloads is worse than one
 * that refuses them.
 */
class UpstreamSyncController extends Controller
{
    public function sync(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 501,
            'msg' => 'host sync is implemented in Phase 2',
        ], 501);
    }
}
