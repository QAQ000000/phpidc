<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ApplyInstanceMappingReviewAction;
use App\Actions\InspectInstanceMappingAction;
use App\Models\Instance;
use App\Models\InstanceMappingReview;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class InstanceMappingReviewController extends Controller
{
    public function show(Request $request, Instance $instance): Response
    {
        $this->actor($request);

        return $this->page($instance);
    }

    public function check(Request $request, Instance $instance, InspectInstanceMappingAction $action): Response
    {
        $actor = $this->actor($request);
        $data = $request->validate(['target_product_id' => ['required', 'integer', 'exists:products,id'], 'request_id' => ['required', 'ulid']]);
        $preview = $action->execute($instance, Product::findOrFail($data['target_product_id']), $actor, $data['request_id']);

        return $this->page($instance, $preview);
    }

    public function store(Request $request, Instance $instance, ApplyInstanceMappingReviewAction $action): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate(['request_id' => ['required', 'ulid'], 'review_token' => ['required', 'string', 'max:131072'],
            'reason' => ['required', 'string', 'max:255'], 'confirm' => ['required', 'accepted']]);
        $action->execute($instance, $actor, $data['request_id'], $data['review_token'], $data['reason'], $request->boolean('confirm'));

        return redirect('/admin/upstream-instances/'.$instance->id.'/mapping-review')->with('status', '配置映射已核准；原续费价和到期日保留。价格不一致时由客户本人确认新价。');
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && ! $actor->trashed() && $actor->anonymized_at === null, 401);
        Gate::forUser($actor)->authorize('upstream.view');
        Gate::forUser($actor)->authorize('upstream.mapping-review');

        return $actor;
    }

    private function page(Instance $instance, ?array $preview = null): Response
    {
        return response()->view('sales.instance-mapping-review', ['instance' => $instance->fresh(), 'preview' => $preview,
            'products' => Product::where('upstream_api_id', $instance->upstream_api_id)
                ->where('upstream_pid', $instance->active_config['upstream_pid'] ?? null)->where('driver_type', $instance->driver_type)->orderBy('id')->get(),
            'reviews' => InstanceMappingReview::where('instance_id', $instance->id)->latest('id')->limit(20)->get()])
            ->header('Cache-Control', 'private, no-store');
    }
}
