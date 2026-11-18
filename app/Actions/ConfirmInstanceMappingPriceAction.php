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
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ConfirmInstanceMappingPriceAction
{
    public const REASON = 'Customer confirmed the exact future renewal price after authenticated binding and complete configuration review';

    public function __construct(private readonly InstanceMappingEvidence $evidence) {}

    public function execute(User $user, Instance $instance, string $requestId, string $reviewToken, string $currentPassword, bool $confirmed): InstanceMappingReview
    {
        $instance = $instance->fresh();
        abort_unless($instance->user_id === $user->id, 403);
        $requestId = strtoupper(trim($requestId));
        validator(['request_id' => $requestId, 'confirm' => $confirmed], ['request_id' => ['required', 'ulid'], 'confirm' => ['accepted']])->validate();
        $currentUser = User::withTrashed()->findOrFail($user->id);
        $this->password($currentUser, $currentPassword);
        $token = $this->evidence->decode($reviewToken, $currentUser, $instance, $requestId, 'price_confirmation');
        if (($existing = $this->evidence->replay($currentUser, $instance, $requestId, $token, self::REASON)) !== null) {
            return $existing;
        }
        $this->evidence->freshToken($token);
        $product = Product::findOrFail($token['snapshot']['target_product_id']);
        $prepared = $this->evidence->inspect($instance, $product, 'price_confirmation');
        if ($this->evidence->fingerprint($prepared['snapshot']) !== $this->evidence->fingerprint($token['snapshot'])) {
            $this->evidence->invalid('配置、目录、成本或续费价已变化，请重新预览。');
        }

        return DB::transaction(function () use ($instance, $user, $product, $prepared, $token, $requestId, $currentPassword): InstanceMappingReview {
            [$locked, $target, $lockedUser] = $this->evidence->lock($instance, $product, $user);
            abort_unless($locked->user_id === $lockedUser->id, 403);
            $this->password($lockedUser, $currentPassword);
            if (($existing = $this->evidence->replay($lockedUser, $locked, $requestId, $token, self::REASON)) !== null) {
                return $existing;
            }
            $this->evidence->freshToken($token);
            $snapshot = $this->evidence->snapshot($locked, $target, $prepared['facts'], 'price_confirmation');
            $fingerprint = $this->evidence->fingerprint($snapshot);
            if (! hash_equals($fingerprint, $this->evidence->fingerprint($token['snapshot']))) {
                $this->evidence->invalid('锁定复核发现价格或业务状态变化，请重新预览。');
            }
            $review = InstanceMappingReview::create(['instance_id' => $locked->id, 'user_id' => $lockedUser->id, 'actor_id' => $lockedUser->id,
                'target_product_id' => $target->id, 'kind' => 'price_confirmation', 'request_id' => $requestId, 'review_fingerprint' => $fingerprint,
                'snapshot' => $snapshot, 'reason' => self::REASON, 'checked_at' => Carbon::createFromTimestampUTC($token['checked_at']),
                'confirmed_at' => now(), 'applied_at' => now()]);
            $config = $locked->active_config;
            $config['options'] = $snapshot['resolved_pairs'];
            $config['upstream_configoption'] = $snapshot['upstream_configoption'];
            $config['binding_pay_type'] = $snapshot['binding_pay_type'];
            $config['price_confirmation_id'] = $review->id;
            $config['price_requires_review'] = false;
            unset($config['billing_adjustment']);
            $locked->update(['recurring_amount' => $snapshot['new_recurring_amount'], 'is_price_locked' => true, 'active_config' => $config]);
            OperationsAudit::record($lockedUser, 'instance.mapping_price.confirmed', Instance::class, $locked->id, self::REASON,
                ['recurring_amount' => $snapshot['original_recurring_amount']], ['review_id' => $review->id,
                    'mapping_review_id' => $snapshot['parent_mapping_review_id'], 'review_fingerprint' => $fingerprint,
                    'recurring_amount' => $snapshot['new_recurring_amount'], 'remote_config_unchanged' => true, 'due_unchanged' => true]);

            return $review;
        });
    }

    private function password(User $user, string $password): void
    {
        if ($user->trashed() || $user->anonymized_at !== null || ! Hash::check($password, $user->getAuthPassword())) {
            throw ValidationException::withMessages(['current_password' => '当前密码错误或账户已停用。']);
        }
    }
}
