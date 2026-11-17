<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AcceptHostSyncAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UpstreamSyncController extends Controller
{
    public function sync(Request $request, AcceptHostSyncAction $accept): JsonResponse
    {
        $accept->execute($request->all());

        return response()->json(['status' => 200, 'msg' => 'accepted']);
    }
}
