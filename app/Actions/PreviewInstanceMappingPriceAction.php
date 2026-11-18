<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Instance;
use App\Models\Product;
use App\Models\User;
use App\Services\InstanceMappingEvidence;

class PreviewInstanceMappingPriceAction
{
    public function __construct(private readonly InstanceMappingEvidence $evidence) {}

    public function execute(User $user, Instance $instance, string $requestId): array
    {
        $user = User::withTrashed()->findOrFail($user->id);
        $instance = $instance->fresh();
        abort_unless($instance->user_id === $user->id && ! $user->trashed() && $user->anonymized_at === null, 403);
        $requestId = strtoupper(trim($requestId));
        validator(['request_id' => $requestId], ['request_id' => ['required', 'ulid']])->validate();
        $mapping = $this->evidence->mapping($instance);
        $target = Product::findOrFail($mapping->target_product_id);
        $prepared = $this->evidence->inspect($instance, $target, 'price_confirmation');

        return $this->evidence->token($prepared['snapshot'], $user, $requestId);
    }
}
