<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ArchiveOneTimeEntitlementAction;
use App\Models\Instance;
use App\Models\InstanceEntitlementArchive;
use App\Models\User;
use App\Services\OneTimeEntitlementEvidence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class CustomerEntitlementArchiveController extends Controller
{
    public function show(Request $request, Instance $instance, OneTimeEntitlementEvidence $evidence): Response
    {
        $user = $this->user($request);
        $instance = $instance->fresh();
        abort_unless($instance !== null && $instance->user_id === $user->id, 403);
        $reasons = [];
        try {
            $evidence->snapshot($instance);
        } catch (ValidationException $error) {
            $reasons = $error->errors();
        }
        $archive = InstanceEntitlementArchive::where('instance_id', $instance->id)->where('user_id', $user->id)->first();

        return response()->view('sales.instance-entitlement-archive', ['instance' => $instance, 'reasons' => $reasons,
            'archive' => $archive, 'validArchive' => $archive !== null && $evidence->validArchive($instance)])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Instance $instance, ArchiveOneTimeEntitlementAction $action): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($instance->user_id === $user->id, 403);
        $data = $request->validate(['current_password' => ['required', 'string', 'max:255'], 'request_id' => ['required', 'ulid'], 'confirm' => ['required', 'accepted']]);
        $action->execute($user, $instance, $data['current_password'], $data['request_id'], $request->boolean('confirm'));

        return redirect('/console/instances/'.$instance->id.'/archive')->with('status', '一次性交付权益已归档。请在注销账户前保存交付资料。');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && ! $user->trashed() && $user->anonymized_at === null, 401);

        return $user;
    }
}
