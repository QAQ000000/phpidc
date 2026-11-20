<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AdminGlobalSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSearchController extends Controller
{
    public function __invoke(Request $request, AdminGlobalSearch $search): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $search->authorize($actor);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:255']]);
        $term = trim($data['q'] ?? '');

        return response()->json(['query' => $term, 'groups' => $search->search($actor, $term)])
            ->header('Cache-Control', 'private, no-store');
    }
}
