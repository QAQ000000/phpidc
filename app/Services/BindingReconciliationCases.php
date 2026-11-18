<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Instance;
use App\Models\InstanceUpstreamBinding;
use App\Models\ReconciliationCase;
use App\Models\ReconciliationEntry;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use JsonException;

class BindingReconciliationCases
{
    public function snapshot(InstanceUpstreamBinding $binding, Instance $instance): array
    {
        return ['version' => 1, 'binding_id' => $binding->id, 'instance_id' => $binding->instance_id, 'user_id' => $instance->user_id,
            'driver_type' => $binding->driver_type->value, 'upstream_api_id' => $binding->upstream_api_id,
            'upstream_instance_id' => $binding->upstream_instance_id, 'bound_at' => $binding->bound_at->copy()->utc()->toIso8601String(),
            'unbound_at' => $binding->unbound_at?->copy()->utc()->toIso8601String(), 'unbind_reason' => $binding->unbind_reason,
            'upstream_disposition' => $binding->upstream_disposition, 'source_snapshot' => $binding->source_snapshot];
    }

    public function fingerprint(array $snapshot): string
    {
        return hash('sha256', json_encode(Arr::sortRecursive($snapshot), JSON_THROW_ON_ERROR));
    }

    public function assertSource(ReconciliationCase $case): InstanceUpstreamBinding
    {
        $binding = InstanceUpstreamBinding::find($case->instance_upstream_binding_id);
        $instance = $binding === null ? null : Instance::find($binding->instance_id);
        if ($case->kind !== 'binding_orphan' || $case->source_type !== 'instance_binding' || $binding === null || $instance === null
            || $case->source_id !== (string) $binding->id || $case->user_id !== $instance->user_id
            || $case->payment_callback_id !== null || $case->source_credit_transaction_id !== null
            || $binding->upstream_disposition !== 'kept_orphan' || $binding->unbound_at === null
            || ! hash_equals($case->source_fingerprint, $this->fingerprint($this->snapshot($binding, $instance)))) {
            throw ValidationException::withMessages(['operation' => '旧资源来源已变化或证据不完整，请核对原绑定。']);
        }

        return $binding;
    }

    public function isResolved(ReconciliationCase $case): bool
    {
        if ($case->status !== 'resolved' || $case->resolved_at === null) {
            return false;
        }
        try {
            $binding = $this->assertSource($case);
            $last = ReconciliationEntry::where('case_id', $case->id)->orderByDesc('sequence')->first();
            $proof = $last?->evidence;
            if ($last === null || $last->action !== 'resolved' || $last->sequence !== $case->revision || ! is_array($proof)
                || ($proof['binding_id'] ?? null) !== $binding->id || ($proof['source_fingerprint'] ?? null) !== $case->source_fingerprint) {
                return false;
            }
            if (($proof['conclusion'] ?? null) === 'terminated') {
                return ($proof['verified_status'] ?? null) === 'Deleted' && ($proof['verification'] ?? null) === 'authenticated_query'
                    && ($proof['upstream_api_id'] ?? null) === $binding->upstream_api_id
                    && ($proof['upstream_instance_id'] ?? null) === $binding->upstream_instance_id
                    && is_string($proof['base_url'] ?? null) && $proof['base_url'] !== ''
                    && (($binding->source_snapshot['base_url'] ?? null) === $proof['base_url']
                        || ($binding->source_snapshot === null && is_string($proof['historical_endpoint_evidence'] ?? null) && trim($proof['historical_endpoint_evidence']) !== ''));
            }

            return ($proof['conclusion'] ?? null) === 'transferred' && ($proof['responsibility_accepted'] ?? null) === true
                && is_string($proof['recipient'] ?? null) && trim($proof['recipient']) !== ''
                && is_string($proof['target_resource'] ?? null) && trim($proof['target_resource']) !== ''
                && is_string($proof['acceptance_reference'] ?? null) && trim($proof['acceptance_reference']) !== ''
                && is_string($proof['accepted_at'] ?? null) && is_string($proof['evidence'] ?? null) && trim($proof['evidence']) !== '';
        } catch (ValidationException|DecryptException|JsonException) {
            return false;
        }
    }

    public function hasUnresolvedForUser(int $userId): bool
    {
        foreach (InstanceUpstreamBinding::whereIn('instance_id', Instance::where('user_id', $userId)->select('id'))
            ->where('upstream_disposition', 'kept_orphan')->lazyById(100) as $binding) {
            $case = ReconciliationCase::where('instance_upstream_binding_id', $binding->id)->first();
            if ($case === null || ! $this->isResolved($case)) {
                return true;
            }
        }

        return false;
    }
}
