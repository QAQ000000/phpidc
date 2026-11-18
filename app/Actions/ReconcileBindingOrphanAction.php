<?php

declare(strict_types=1);

namespace App\Actions;

use App\Contracts\BoundUpstreamEndpoint;
use App\Models\Instance;
use App\Models\InstanceUpstreamBinding;
use App\Models\ReconciliationCase;
use App\Models\ReconciliationEntry;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Services\BindingReconciliationCases;
use App\Services\DriverManager;
use App\Services\NotificationManager;
use App\Support\MachineOperation;
use App\Support\OperationsAudit;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReconcileBindingOrphanAction
{
    public function execute(ReconciliationCase $case, User $actor, array $input): ReconciliationCase
    {
        $data = validator($input, [
            'request_id' => ['required', 'ulid'], 'expected_revision' => ['required', 'integer', 'min:1'],
            'operation' => ['required', 'in:terminate_verified,transfer_verified,reopen'],
            'reason' => ['required', 'string', 'max:255'], 'evidence' => ['required', 'string', 'max:1000'], 'confirm' => ['required', 'accepted'],
            'original_base_url' => ['nullable', 'url:http,https', 'max:2048'],
            'historical_endpoint_evidence' => ['nullable', 'string', 'max:1000'],
            'recipient' => ['required_if:operation,transfer_verified', 'nullable', 'string', 'max:255'],
            'target_resource' => ['required_if:operation,transfer_verified', 'nullable', 'string', 'max:255'],
            'acceptance_reference' => ['required_if:operation,transfer_verified', 'nullable', 'string', 'max:255'],
            'accepted_at' => ['required_if:operation,transfer_verified', 'nullable', 'date', 'before_or_equal:now'],
            'responsibility_accepted' => ['required_if:operation,transfer_verified', 'accepted_if:operation,transfer_verified'],
        ])->validate();
        $data['request_id'] = Str::upper($data['request_id']);
        foreach (['reason', 'evidence', 'original_base_url', 'historical_endpoint_evidence', 'recipient', 'target_resource', 'acceptance_reference'] as $field) {
            $data[$field] = trim($data[$field] ?? '');
        }
        if ($data['reason'] === '' || $data['evidence'] === '' || ($data['operation'] === 'transfer_verified'
            && ($data['recipient'] === '' || $data['target_resource'] === '' || $data['acceptance_reference'] === ''))) {
            $this->reject('必须提供原笔核验原因及完整的实际处置证据。');
        }
        $intent = Arr::except($data, ['confirm']);
        $intent['expected_revision'] = (int) $data['expected_revision'];
        $intent['responsibility_accepted'] = $data['operation'] === 'transfer_verified';
        $operator = User::findOrFail($actor->id);
        Gate::forUser($operator)->authorize('upstream.view');
        Gate::forUser($operator)->authorize('upstream.orphan-reconcile');
        $hint = ReconciliationCase::findOrFail($case->id);
        abort_unless($hint->kind === 'binding_orphan', 404);
        $binding = InstanceUpstreamBinding::findOrFail($hint->instance_upstream_binding_id);
        $instance = Instance::findOrFail($binding->instance_id);
        $proofs = app(BindingReconciliationCases::class);
        $snapshot = $proofs->snapshot($binding, $instance);
        $key = 'manual:'.$data['request_id'];
        $existing = ReconciliationEntry::where('case_id', $hint->id)->where('request_key', $key)->first();
        $accountState = null;
        $verified = [];
        if ($existing === null && $data['operation'] !== 'reopen') {
            $proofs->assertSource($hint);
            if ($data['operation'] === 'terminate_verified') {
                [$verified, $accountState] = $this->verifyDeleted($binding, $instance, $data);
            } else {
                $verified = ['conclusion' => 'transferred', 'recipient' => $data['recipient'], 'target_resource' => $data['target_resource'],
                    'acceptance_reference' => $data['acceptance_reference'], 'accepted_at' => $data['accepted_at'],
                    'responsibility_accepted' => true, 'evidence' => $data['evidence']];
            }
        }

        return DB::transaction(function () use ($hint, $actor, $binding, $instance, $proofs, $snapshot, $data, $intent, $key, $verified, $accountState): ReconciliationCase {
            $users = User::withTrashed()->whereKey([$hint->user_id, $actor->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $operator = $users->get($actor->id);
            abort_unless($operator instanceof User && ! $operator->trashed() && $operator->anonymized_at === null && $users->has($hint->user_id), 403);
            Gate::forUser($operator)->authorize('upstream.view');
            Gate::forUser($operator)->authorize('upstream.orphan-reconcile');
            $currentInstance = Instance::whereKey($instance->id)->lockForUpdate()->firstOrFail();
            $currentBinding = InstanceUpstreamBinding::whereKey($binding->id)->lockForUpdate()->firstOrFail();
            if ($currentInstance->user_id !== $hint->user_id || $proofs->fingerprint($proofs->snapshot($currentBinding, $currentInstance)) !== $proofs->fingerprint($snapshot)) {
                $this->reject('核验期间原绑定或归属发生变化，请重新检查。');
            }
            if ($accountState !== null) {
                $account = UpstreamApi::whereKey($currentBinding->upstream_api_id)->lockForUpdate()->firstOrFail();
                $probe = clone $currentInstance;
                $probe->setRelation('upstreamApi', $account);
                if (rtrim($account->base_url, '/') !== $accountState['base_url'] || ! $account->is_active
                    || app(MachineOperation::class)->accountFingerprint($probe) !== $accountState['auth']) {
                    $this->reject('原上游账号配置在核验期间改变，请重新检查。');
                }
            }
            $locked = ReconciliationCase::whereKey($hint->id)->lockForUpdate()->firstOrFail();
            $entry = ReconciliationEntry::where('case_id', $locked->id)->where('request_key', $key)->first();
            if ($entry !== null) {
                if (($entry->evidence['actor_id'] ?? null) !== $operator->id || Arr::sortRecursive($entry->evidence['intent'] ?? []) !== Arr::sortRecursive($intent)) {
                    $this->reject('同一处置标识不能改变原操作、来源或核验资料。');
                }

                return $locked;
            }
            if ($locked->revision !== (int) $data['expected_revision']) {
                $this->reject('案件版本已变化，请查看最新处置记录。');
            }
            $before = $locked->only(['status', 'revision']);
            if ($data['operation'] === 'reopen') {
                if ($locked->status !== 'resolved' || $proofs->isResolved($locked)) {
                    $this->reject('只有原结案证据已不成立的已结案记录可以重新打开。');
                }
                $status = 'open';
                $action = 'reopened';
            } else {
                if ($locked->status === 'resolved') {
                    $this->reject('案件已结案；新的相反证据须先重新打开。');
                }
                $proofs->assertSource($locked);
                $status = 'resolved';
                $action = 'resolved';
            }
            $locked->update(['status' => $status, 'revision' => $locked->revision + 1, 'resolved_at' => $status === 'resolved' ? now() : null]);
            ReconciliationEntry::create(['case_id' => $locked->id, 'sequence' => $locked->revision, 'request_key' => $key, 'action' => $action,
                'actor_id' => $operator->id, 'reason' => $data['reason'], 'evidence' => [...$verified, 'version' => 1,
                    'binding_id' => $currentBinding->id, 'source_fingerprint' => $locked->source_fingerprint, 'actor_id' => $operator->id, 'intent' => $intent]]);
            OperationsAudit::record($operator, 'reconciliation.binding.'.$action, ReconciliationCase::class, $locked->id, $data['reason'], $before, $locked->only(['status', 'revision']));
            app(NotificationManager::class)->queueOperations('reconciliation.updated', ['invoice_no' => 'binding:'.$currentBinding->id,
                'amount' => '0.00', 'currency' => 'CNY', 'status' => $status], 'reconciliation-case:'.$locked->id.':'.$locked->revision);

            return $locked;
        });
    }

    private function verifyDeleted(InstanceUpstreamBinding $binding, Instance $instance, array $data): array
    {
        $account = UpstreamApi::find($binding->upstream_api_id);
        if ($account === null || $binding->upstream_instance_id === null || ! $account->is_active) {
            $this->reject('原资源账号或主机标识不可核验，不能推定已终止。');
        }
        $endpoint = $binding->source_snapshot['base_url'] ?? null;
        if ($endpoint === null) {
            if ($data['original_base_url'] === '' || $data['historical_endpoint_evidence'] === '') {
                $this->reject('历史绑定没有原端点快照，必须先提供原端点证明。');
            }
            $endpoint = rtrim($data['original_base_url'], '/');
        }
        if ($endpoint !== rtrim($account->base_url, '/')) {
            $this->reject('当前账号端点与原资源端点不符，禁止向手填地址转发凭据。');
        }
        $probe = clone $instance;
        $probe->forceFill(['driver_type' => $binding->driver_type, 'upstream_api_id' => $binding->upstream_api_id, 'upstream_instance_id' => $binding->upstream_instance_id]);
        $probe->setRelation('upstreamApi', $account);
        $state = ['base_url' => $endpoint, 'auth' => app(MachineOperation::class)->accountFingerprint($probe)];
        try {
            $driver = app(DriverManager::class)->fulfillment($binding->driver_type, $binding->upstream_api_id);
            if (! $driver instanceof BoundUpstreamEndpoint || $driver->endpointIdentity()['base_url'] !== $endpoint
                || $driver->endpointIdentity()['upstream_api_id'] !== $account->id) {
                $this->reject('原驱动未提供可核验的认证端点。');
            }
            $result = app(CallUpstreamAction::class)->execute(fn () => $driver->syncInfo($probe));
        } catch (DomainException $error) {
            $this->reject($error->getMessage());
        }
        $host = $result->rawResponse['data']['host_data'] ?? [];
        if ($result->upstreamStatus !== 'Deleted' || (string) ($host['id'] ?? '') !== $binding->upstream_instance_id) {
            $this->reject('必须认证确认原主机已 Deleted，删除已受理或其他主机的结果不足以结案。');
        }

        return [['conclusion' => 'terminated', 'verified_status' => 'Deleted', 'verification' => 'authenticated_query',
            'upstream_api_id' => $binding->upstream_api_id, 'upstream_instance_id' => $binding->upstream_instance_id, 'base_url' => $endpoint,
            'historical_endpoint_evidence' => $binding->source_snapshot === null ? $data['historical_endpoint_evidence'] : null,
            'checked_at' => now()->toIso8601String(), 'evidence' => $data['evidence']], $state];
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['operation' => $message]);
    }
}
