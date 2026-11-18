<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Instance;
use App\Models\Product;
use App\Models\User;
use App\Services\InstanceMappingEvidence;
use Illuminate\Support\Facades\Gate;

class InspectInstanceMappingAction
{
    public function __construct(private readonly InstanceMappingEvidence $evidence) {}

    public function execute(Instance $instance, Product $targetProduct, User $actor, string $requestId): array
    {
        $actor = User::withTrashed()->findOrFail($actor->id);
        Gate::forUser($actor)->authorize('upstream.view');
        Gate::forUser($actor)->authorize('upstream.mapping-review');
        abort_unless(! $actor->trashed() && $actor->anonymized_at === null, 403);
        $requestId = strtoupper(trim($requestId));
        validator(['request_id' => $requestId], ['request_id' => ['required', 'ulid']])->validate();
        $prepared = $this->evidence->inspect($instance, $targetProduct);

        return $this->evidence->token($prepared['snapshot'], $actor, $requestId);
    }
}
