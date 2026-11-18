<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Instance;
use App\Models\InstanceMappingReview;
use App\Models\Product;
use App\Models\User;
use App\Services\InstanceMappingEvidence;
use App\Support\OperationsAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ApplyInstanceMappingReviewAction
{
    public function __construct(private readonly InstanceMappingEvidence $evidence) {}

    public function execute(Instance $instance, User $actor, string $requestId, string $reviewToken, string $reason, bool $confirmed): InstanceMappingReview
    {
        $actor = User::withTrashed()->findOrFail($actor->id);
        $instance = $instance->fresh();
        Gate::forUser($actor)->authorize('upstream.view');
        Gate::forUser($actor)->authorize('upstream.mapping-review');
        abort_unless(! $actor->trashed() && $actor->anonymized_at === null, 403);
        $requestId = strtoupper(trim($requestId));
        $reason = trim($reason);
        validator(['request_id' => $requestId, 'reason' => $reason, 'confirm' => $confirmed], [
            'request_id' => ['required', 'ulid'], 'reason' => ['required', 'string', 'max:255'], 'confirm' => ['accepted']])->validate();
        $token = $this->evidence->decode($reviewToken, $actor, $instance, $requestId, 'mapping');
        if (($existing = $this->evidence->replay($actor, $instance, $requestId, $token, $reason)) !== null) {
            return $existing;
        }
        $this->evidence->freshToken($token);
        $product = Product::findOrFail($token['snapshot']['target_product_id']);
        $prepared = $this->evidence->inspect($instance, $product);
        if ($this->evidence->fingerprint($prepared['snapshot']) !== $this->evidence->fingerprint($token['snapshot'])) {
            $this->evidence->invalid('认证配置、目录或计费信息已经变化，请重新检查并确认。');
        }

        return DB::transaction(function () use ($instance, $actor, $product, $prepared, $token, $requestId, $reason): InstanceMappingReview {
            [$locked, $target, $lockedActor] = $this->evidence->lock($instance, $product, $actor);
            Gate::forUser($lockedActor)->authorize('upstream.view');
            Gate::forUser($lockedActor)->authorize('upstream.mapping-review');
            if (($existing = $this->evidence->replay($lockedActor, $locked, $requestId, $token, $reason)) !== null) {
                return $existing;
            }
            $this->evidence->freshToken($token);
            $snapshot = $this->evidence->snapshot($locked, $target, $prepared['facts']);
            $fingerprint = $this->evidence->fingerprint($snapshot);
            if (! hash_equals($fingerprint, $this->evidence->fingerprint($token['snapshot']))) {
                $this->evidence->invalid('锁定复核发现绑定、目录、账户或计费基线发生变化。');
            }
            $review = InstanceMappingReview::create(['instance_id' => $locked->id, 'user_id' => $locked->user_id, 'actor_id' => $lockedActor->id,
                'target_product_id' => $target->id, 'kind' => 'mapping', 'request_id' => $requestId, 'review_fingerprint' => $fingerprint,
                'snapshot' => $snapshot, 'reason' => $reason, 'checked_at' => Carbon::createFromTimestampUTC($token['checked_at']),
                'confirmed_at' => now(), 'applied_at' => now()]);
            $config = $locked->active_config;
            $config['options'] = $snapshot['resolved_pairs'];
            $config['upstream_configoption'] = $snapshot['upstream_configoption'];
            $config['binding_pay_type'] = $snapshot['binding_pay_type'];
            $config['mapping_review_id'] = $review->id;
            $config['mapping_requires_review'] = false;
            $config['price_requires_review'] = ! $snapshot['price_baseline_matches'];
            unset($config['billing_adjustment'], $config['price_confirmation_id']);
            $locked->update(['product_id' => $target->id, 'active_config' => $config, 'is_price_locked' => true]);
            OperationsAudit::record($lockedActor, 'instance.mapping_review.applied', Instance::class, $locked->id, $reason,
                ['product_id' => $snapshot['original_product_id'], 'recurring_amount' => $snapshot['original_recurring_amount']],
                ['review_id' => $review->id, 'target_product_id' => $target->id, 'review_fingerprint' => $fingerprint,
                    'price_requires_review' => $config['price_requires_review'], 'recurring_amount' => $locked->recurring_amount,
                    'remote_config_unchanged' => true, 'due_unchanged' => true]);

            return $review;
        });
    }
}
