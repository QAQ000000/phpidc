<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ConfirmInstanceMappingPriceAction;
use App\Actions\PreviewInstanceMappingPriceAction;
use App\Models\Instance;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class CustomerInstanceMappingPriceController extends Controller
{
    public function show(Request $request, Instance $instance): Response
    {
        $this->user($request, $instance);

        return $this->page($instance);
    }

    public function preview(Request $request, Instance $instance, PreviewInstanceMappingPriceAction $action): Response
    {
        $user = $this->user($request, $instance);
        $data = $request->validate(['request_id' => ['required', 'ulid']]);
        $preview = $action->execute($user, $instance, $data['request_id']);

        return $this->page($instance, $preview);
    }

    public function store(Request $request, Instance $instance, ConfirmInstanceMappingPriceAction $action): RedirectResponse
    {
        $user = $this->user($request, $instance);
        try {
            $data = $request->validate(['request_id' => ['required', 'ulid'], 'review_token' => ['required', 'string', 'max:131072'],
                'current_password' => ['required', 'string', 'max:255'], 'confirm' => ['required', 'accepted']]);
            $action->execute($user, $instance, $data['request_id'], $data['review_token'], $data['current_password'], $request->boolean('confirm'));
        } catch (ValidationException $error) {
            return redirect('/console/instances/'.$instance->id.'/mapping-price')->withErrors($error->errors());
        }

        return redirect('/console/instances/'.$instance->id.'/mapping-price')->with('status', '未来续费价格已确认。历史账单、到期日和远程配置保持原记录。');
    }

    private function user(Request $request, Instance $instance): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && ! $user->trashed() && $user->anonymized_at === null, 401);
        abort_unless($instance->user_id === $user->id, 403);

        return $user;
    }

    private function page(Instance $instance, ?array $preview = null): Response
    {
        return response()->view('sales.instance-mapping-price', ['instance' => $instance->fresh(), 'preview' => $preview])
            ->header('Cache-Control', 'private, no-store');
    }
}
