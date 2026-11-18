<?php

declare(strict_types=1);

namespace App\Actions;

use App\Contracts\PowerControllable;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\ProvisionCommand;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\User;
use App\Services\DriverManager;
use App\Services\SettingService;
use App\Support\AdminAuditContext;
use App\Support\MachineOperation;
use Closure;
use DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ReconcileMachineOperationAction
{
    public function inspect(Instance $instance, User $actor, string $requestId, string $reason): array
    {
        $this->authorize($actor);
        $data = validator(['operation_request_id' => $requestId, 'reason' => trim($reason)], [
            'operation_request_id' => ['required', 'ulid'], 'reason' => ['required', 'string', 'max:1000'],
        ])->validate();

        return $this->exclusive($instance, function () use ($instance, $actor, $data): array {
            $instance = $instance->fresh();
            $operation = $this->pending($instance, $data['operation_request_id']);
            $this->quiescent($instance, $operation);
            $binding = app(MachineOperation::class)->binding($instance);
            $observation = $this->observe($instance);

            return DB::transaction(function () use ($instance, $actor, $operation, $binding, $observation, $data): array {
                $locked = $this->lock($instance, $actor);
                $this->unchanged($locked, $operation, $binding);
                $reviewId = (string) Str::ulid();
                $auditId = $this->audit($locked, $actor, 'review.'.$reviewId, $data['reason'], ['operation_request_id' => $operation['request_id'],
                    'binding' => app(MachineOperation::class)->publicBinding($locked), 'observation' => $observation]);
                $proof = ['instance_id' => $locked->id, 'operation' => $operation, 'binding' => $binding,
                    'expires' => now()->addMinutes(max(1, app(SettingService::class)->getInt('machine.review_token_minutes', 5)))->getTimestamp()];

                return ['audit_id' => $auditId, 'observation' => $observation, 'expires' => $proof['expires'],
                    'proof' => Crypt::encryptString(json_encode($proof, JSON_THROW_ON_ERROR))];
            });
        });
    }

    public function execute(Instance $instance, User $actor, array $input): array
    {
        $this->authorize($actor);
        foreach (['reason', 'evidence_ref', 'evidence', 'verified_host_id'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = trim($input[$field]);
            }
        }
        $release = ($input['decision'] ?? null) === 'release';
        $data = validator($input, ['resolution_id' => ['required', 'ulid'], 'operation_request_id' => ['required', 'ulid'],
            'proof' => ['required', 'string'], 'decision' => ['required', Rule::in(['hold', 'release'])],
            'reason' => ['required', 'string', 'max:1000'], 'confirm' => ['accepted'],
            'source' => [Rule::requiredIf($release), 'nullable', Rule::in(['supplier_panel', 'supplier_ticket'])],
            'conclusion' => [Rule::requiredIf($release), 'nullable', Rule::in(['completed', 'failed', 'not_executed'])],
            'evidence_ref' => [Rule::requiredIf($release), 'nullable', 'string', 'max:255'],
            'evidence' => [Rule::requiredIf($release), 'nullable', 'string', 'min:20', 'max:2000'],
            'verified_host_id' => [Rule::requiredIf($release), 'nullable', 'string', 'max:100'],
            'ended_at' => [Rule::requiredIf($release), 'nullable', 'date', 'before_or_equal:now'],
            'idle_confirm' => [$release ? 'accepted' : 'sometimes'], 'legacy_binding_confirm' => ['sometimes', 'boolean'],
        ])->validate();
        $data['resolution_id'] = strtoupper($data['resolution_id']);
        $identity = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        if (($prior = $this->repeat($instance, $data, $identity)) !== null) {
            return $prior;
        }
        try {
            $proof = json_decode(Crypt::decryptString($data['proof']), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException) {
            $this->reject('核对凭证无效，请重新查询。');
        }
        if (($proof['instance_id'] ?? null) !== $instance->id || ($proof['operation']['request_id'] ?? null) !== $data['operation_request_id']
            || ($proof['expires'] ?? 0) <= now()->getTimestamp()) {
            $this->reject('核对凭证已过期或不属于原请求，请重新查询。');
        }

        return $this->exclusive($instance, function () use ($instance, $actor, $data, $identity, $proof, $release): array {
            if (($prior = $this->repeat($instance, $data, $identity)) !== null) {
                return $prior;
            }
            $instance = $instance->fresh();
            $this->unchanged($instance, $proof['operation'], $proof['binding']);
            $operation = $this->pending($instance, $data['operation_request_id']);
            $this->quiescent($instance, $operation);
            // Even a still-valid proof cannot substitute for a fresh check at confirmation.
            $observation = $this->observe($instance);

            return DB::transaction(function () use ($instance, $actor, $data, $identity, $proof, $release, $operation, $observation): array {
                $locked = $this->lock($instance, $actor);
                if (($prior = $this->repeat($locked, $data, $identity, currentRead: true)) !== null) {
                    return $prior;
                }
                $this->unchanged($locked, $operation, $proof['binding']);
                if ($proof['expires'] <= now()->getTimestamp()) {
                    $this->reject('核对凭证已过期，请重新查询。');
                }
                if ($release) {
                    $this->releaseEligible($locked, $operation, $data, $observation);
                }
                $result = ['operation_request_id' => $operation['request_id'], 'released' => $release,
                    'conclusion' => $data['conclusion'] ?? null, 'identity' => $identity,
                    'binding' => app(MachineOperation::class)->publicBinding($locked), 'observation' => $observation,
                    'evidence' => array_intersect_key($data, array_flip(['source', 'evidence_ref', 'evidence', 'verified_host_id', 'ended_at'])),
                    'verification' => $release ? 'operator_attestation' : 'unresolved'];
                $auditId = $this->audit($locked, $actor, 'reconcile.'.$data['resolution_id'], $data['reason'], $result);
                if ($release) {
                    $config = $locked->active_config;
                    // Preserve the original requested/unknown outcome; only the audited release authorizes a new request.
                    $config['cloud_operation']['encrypted_binding'] ??= Crypt::encryptString(json_encode($proof['binding'], JSON_THROW_ON_ERROR));
                    $config['cloud_operation']['reconciliation'] = ['audit_id' => $auditId, 'actor_id' => $actor->id,
                        'resolution_id' => $data['resolution_id'], 'at' => now()->toIso8601String()];
                    $locked->update(['active_config' => $config]);
                }

                // Read the durable representation so MySQL JSON key normalization is identical on replay.
                return $this->repeat($locked, $data, $identity, currentRead: true);
            });
        });
    }

    private function authorize(User $actor): void
    {
        // A stale authenticated model must not replay private evidence after its role was revoked.
        $actor = User::find($actor->id);
        abort_unless($actor !== null, 403);
        Gate::forUser($actor)->authorize('upstream.view');
        Gate::forUser($actor)->authorize('upstream.lifecycle');
    }

    private function lock(Instance $instance, User $actor): Instance
    {
        $users = User::whereKey([$instance->user_id, $actor->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $operator = $users->get($actor->id);
        abort_unless($operator !== null && $users->has($instance->user_id), 403);
        $this->authorize($operator);

        return Instance::whereKey($instance->id)->lockForUpdate()->firstOrFail();
    }

    private function pending(Instance $instance, string $requestId): array
    {
        $operation = $instance->active_config['cloud_operation'] ?? [];
        if (($operation['request_id'] ?? null) !== $requestId || ! app(MachineOperation::class)->blocked($instance)
            || ProvisionCommand::tryFrom($operation['command'] ?? '') === null) {
            $this->reject('原请求不存在、已经核对或并非受支持的机器命令。');
        }
        if (isset($operation['encrypted_binding'])
            && json_decode(Crypt::decryptString($operation['encrypted_binding']), true, flags: JSON_THROW_ON_ERROR) !== app(MachineOperation::class)->binding($instance)) {
            $this->reject('原操作属于其他绑定，不能用当前机器状态核销。');
        }
        $request = DB::table('audit_logs')->where('subject_type', Instance::class)->where('subject_id', $instance->id)
            ->where('action', 'instance.machine.request.'.$requestId)->first();
        if ($request === null || DB::table('audit_logs')->where('subject_type', Instance::class)->where('subject_id', $instance->id)
            ->where('action', 'instance.hot_swap')->where('id', '>', $request->id)->exists()) {
            $this->reject('缺少原请求审计，或请求之后发生过热切换；须先核对旧绑定，不能释放当前标记。');
        }

        return $operation;
    }

    private function quiescent(Instance $instance, array $operation): void
    {
        $request = DB::table('audit_logs')->where('subject_type', Instance::class)->where('subject_id', $instance->id)
            ->where('action', 'instance.machine.request.'.$operation['request_id'])->first();
        // The command can authenticate/re-authenticate and issue header + command requests (30s each).
        // Wait past that HTTP budget as well as acquiring the shared command mutex.
        if ($request === null || Carbon::parse($request->created_at)->addSeconds(300)->isFuture()) {
            $this->reject('请求仍在保护期内，请在提交五分钟后核对，避免处理尚在返回的请求。');
        }
    }

    private function unchanged(Instance $instance, array $operation, array $binding): void
    {
        if (($instance->active_config['cloud_operation'] ?? null) !== $operation || app(MachineOperation::class)->binding($instance) !== $binding) {
            $this->reject('原请求或服务绑定已变化，请刷新核对。');
        }
    }

    private function observe(Instance $instance): array
    {
        if (! $instance->upstreamApi?->is_active || $instance->upstream_instance_id === null) {
            return ['available' => false, 'service_state' => 'unavailable', 'power_state' => 'unavailable', 'at' => now()->toIso8601String()];
        }
        $driver = app(DriverManager::class)->fulfillment($instance->driver_type, $instance->upstream_api_id);
        if (! $driver instanceof PowerControllable) {
            $this->reject('此驱动不提供机器状态核对能力。');
        }
        try {
            $snapshot = app(CallUpstreamAction::class)->execute(fn () => $driver->syncInfo($instance));
        } catch (DomainException) {
            // Explicit unavailable observation keeps the original command blocked; it is never success.
            return ['available' => false, 'service_state' => 'unavailable', 'power_state' => 'unavailable', 'at' => now()->toIso8601String()];
        }
        $probe = clone $instance;
        $config = $probe->active_config;
        $capability = collect($snapshot->panelWidgets)->firstWhere('type', 'cloud_capabilities');
        $config['upstream_panel'] = $capability['data'] ?? [];
        $probe->active_config = $config;
        $power = 'unavailable';
        if ($instance->status === InstanceStatus::ACTIVE && $snapshot->upstreamStatus === 'Active' && ($config['upstream_panel']['machine_status'] ?? false) === true) {
            $result = app(CallUpstreamAction::class)->execute(fn () => $driver->executePowerCommand($probe, ProvisionCommand::STATUS));
            $state = $result->rawResponse['machine_status_widget']['data']['state'] ?? null;
            if ($result->success && in_array($state, ['on', 'off', 'unknown'], true)) {
                $power = $state;
            }
        }

        return ['available' => true, 'service_state' => $snapshot->upstreamStatus, 'power_state' => $power, 'at' => now()->toIso8601String()];
    }

    private function releaseEligible(Instance $instance, array $operation, array $data, array $observation): void
    {
        if (! $observation['available'] || $observation['service_state'] !== 'Active' || $instance->status !== InstanceStatus::ACTIVE
            || isset($instance->active_config['cancellation']) || $data['verified_host_id'] !== $instance->upstream_instance_id
            || (empty($operation['encrypted_binding']) && empty($data['legacy_binding_confirm']))) {
            $this->reject('不能确认当前资源与原请求归属、可用状态或旧记录绑定；保持未决。');
        }
        $request = DB::table('audit_logs')->where('subject_type', Instance::class)->where('subject_id', $instance->id)
            ->where('action', 'instance.machine.request.'.$operation['request_id'])->first();
        if (Carbon::parse($data['ended_at'])->lt(Carbon::parse($request->created_at))) {
            $this->reject('上游任务终结时间早于原请求，请核对证据。');
        }
        $tasks = FulfillmentTask::where('instance_id', $instance->id)->whereIn('status', [FulfillmentTaskStatus::PENDING, FulfillmentTaskStatus::RUNNING, FulfillmentTaskStatus::FAILED])->orderBy('id')->lockForUpdate()->first(['id']);
        if ($tasks !== null) {
            $this->reject('仍有履约任务待核对，不能释放机器操作。');
        }
        $target = match (ProvisionCommand::from($operation['command'])) {
            ProvisionCommand::POWER_ON => 'on',
            ProvisionCommand::POWER_OFF, ProvisionCommand::HARD_OFF => 'off',
            default => null,
        };
        if ($data['conclusion'] === 'completed' && $target !== null && $observation['power_state'] !== $target) {
            $this->reject('当前电源状态与所填完成结论不符，保持未决。');
        }
    }

    private function repeat(Instance $instance, array $data, string $identity, bool $currentRead = false): ?array
    {
        $row = DB::table('audit_logs')->where('subject_type', Instance::class)->where('subject_id', $instance->id)
            ->where('action', 'instance.machine.reconcile.'.$data['resolution_id'])
            ->when($currentRead, fn ($query) => $query->lockForUpdate())->first();
        if ($row === null) {
            return null;
        }
        $result = json_decode($row->after, true, flags: JSON_THROW_ON_ERROR);
        if (! hash_equals($result['identity'], $identity)) {
            $this->reject('同一核对请求不能更改参数，请刷新。');
        }

        return $result + ['audit_id' => $row->id];
    }

    private function audit(Instance $instance, User $actor, string $suffix, string $reason, array $result): int
    {
        return DB::table('audit_logs')->insertGetId(['actor_type' => 'user', 'actor_id' => $actor->id,
            'action' => 'instance.machine.'.$suffix, 'subject_type' => Instance::class, 'subject_id' => $instance->id,
            'reason' => $reason, 'before' => json_encode(app(MachineOperation::class)->summary($instance), JSON_THROW_ON_ERROR),
            'after' => json_encode($result, JSON_THROW_ON_ERROR), ...AdminAuditContext::fields(true), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function exclusive(Instance $instance, Closure $callback): array
    {
        $lock = Cache::lock('customer-machine-control:'.$instance->id, 120);
        if (! $lock->get()) {
            $this->reject('机器请求仍在执行，请稍后核对。');
        }
        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['operation' => $message]);
    }
}
