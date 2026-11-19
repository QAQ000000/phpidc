<?php

declare(strict_types=1);

namespace App\Actions;

use App\Contracts\PowerControllable;
use App\Data\ProvisionResult;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\ProvisionCommand;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\User;
use App\Services\DriverManager;
use App\Support\MachineOperation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExecuteCustomerPowerCommandAction
{
    public function execute(User $user, Instance $instance, ProvisionCommand $command, array $input): ProvisionResult
    {
        abort_unless($instance->user_id === $user->id, 403);
        $direct = in_array($command, [ProvisionCommand::POWER_ON, ProvisionCommand::VNC], true);
        $data = validator($input, ['request_id' => ['required', 'ulid'], 'reason' => ['nullable', 'string', 'max:1000'],
            'confirm' => [$direct || $command->isDestructive() ? 'sometimes' : 'accepted'],
            'backup_confirm' => [$command->isDestructive() ? 'accepted' : 'sometimes']])->validate();
        if (! in_array($command, [ProvisionCommand::POWER_ON, ProvisionCommand::POWER_OFF,
            ProvisionCommand::REBOOT, ProvisionCommand::HARD_OFF, ProvisionCommand::HARD_REBOOT,
            ProvisionCommand::REINSTALL, ProvisionCommand::RESCUE_SYSTEM, ProvisionCommand::VNC], true)) {
            throw ValidationException::withMessages(['command' => '请选择允许的机器操作。']);
        }
        $data['reason'] = trim($data['reason'] ?? '');
        if ($data['reason'] === '') {
            $data['reason'] = match ($command) {
                ProvisionCommand::POWER_ON => '客户开机', ProvisionCommand::POWER_OFF => '客户确认关机',
                ProvisionCommand::REBOOT => '客户确认重启', ProvisionCommand::HARD_OFF => '客户确认硬关机',
                ProvisionCommand::HARD_REBOOT => '客户确认硬重启', ProvisionCommand::REINSTALL => '客户确认备份并重装系统',
                ProvisionCommand::RESCUE_SYSTEM => '客户确认备份并进入救援系统', default => '客户打开 VNC 控制台',
            };
        }
        $lock = Cache::lock('customer-machine-control:'.$instance->id, 120);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['command' => '已有机器操作正在处理中，请稍后核对结果。']);
        }
        try {
            $driver = app(DriverManager::class)->fulfillment($instance->driver_type, $instance->upstream_api_id);
            abort_unless($driver instanceof PowerControllable, 422);
            // Durable intent prevents an HTTP/browser retry from replaying a destructive operation.
            $instance = DB::transaction(function () use ($user, $instance, $command, $data, $input): Instance {
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $current = Instance::whereKey($instance->id)->lockForUpdate()->firstOrFail();
                abort_unless($current->user_id === $user->id, 403);
                if ($current->status !== InstanceStatus::ACTIVE || $current->upstream_instance_id === null || ! $current->upstreamApi?->is_active
                    || $current->only(['driver_type', 'upstream_api_id', 'upstream_instance_id', 'downstream_token']) !== $instance->only(['driver_type', 'upstream_api_id', 'upstream_instance_id', 'downstream_token'])
                    || isset($current->active_config['cancellation'])
                    || FulfillmentTask::where('instance_id', $current->id)->whereIn('status', [FulfillmentTaskStatus::PENDING, FulfillmentTaskStatus::RUNNING, FulfillmentTaskStatus::FAILED])->exists()) {
                    throw ValidationException::withMessages(['command' => '请先处理服务状态、终止申请或已有任务。']);
                }
                $config = $current->active_config ?? [];
                if (app(MachineOperation::class)->blocked($current)
                    || DB::table('audit_logs')->where('subject_type', Instance::class)->where('subject_id', $current->id)
                        ->where('action', 'instance.machine.request.'.$data['request_id'])->exists()) {
                    throw ValidationException::withMessages(['command' => '此操作已提交或存在未决操作，请核对审计，勿重复执行。']);
                }
                $operations = app(MachineOperation::class);
                $config['cloud_operation'] = ['request_id' => $data['request_id'], 'command' => $command->value, 'status' => 'requested',
                    'requested_at' => now()->toIso8601String(), 'parameters' => $operations->parameters($command, $input),
                    'encrypted_binding' => Crypt::encryptString(json_encode($operations->binding($current), JSON_THROW_ON_ERROR))];
                $current->update(['active_config' => $config]);
                $this->audit($user, $current, 'request.'.$data['request_id'], $data['reason'], $operations->summary($current));

                return $current;
            });
            $driver = app(DriverManager::class)->fulfillment($instance->driver_type, $instance->upstream_api_id);
            abort_unless($driver instanceof PowerControllable, 422);
            try {
                $result = app(CallUpstreamAction::class)->execute(fn () => $driver->executePowerCommand($instance, $command, $input));
            } catch (ValidationException $e) {
                // Protocol input validation occurs before the command POST, so it is safe to correct the form.
                $this->finish($user, $instance, $data, 'rejected');
                throw $e;
            }
            // Unexpected exceptions leave the committed requested intent for manual reconciliation.
            $this->finish($user, $instance, $data, $result->success ? 'accepted' : ($result->retryable ? 'unknown' : 'rejected'));

            return $result;
        } finally {
            $lock->release();
        }
    }

    private function finish(User $user, Instance $instance, array $data, string $status): void
    {
        $matches = DB::transaction(function () use ($user, $instance, $data, $status): bool {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $current = Instance::whereKey($instance->id)->lockForUpdate()->firstOrFail();
            $config = $current->active_config ?? [];
            $matches = $current->only(['user_id', 'status', 'driver_type', 'upstream_api_id', 'upstream_instance_id', 'downstream_token'])
                === $instance->only(['user_id', 'status', 'driver_type', 'upstream_api_id', 'upstream_instance_id', 'downstream_token'])
                && ($config['cloud_operation']['request_id'] ?? null) === $data['request_id']
                && ($config['cloud_operation']['status'] ?? null) === 'requested'
                && ! isset($config['cloud_operation']['reconciliation'])
                && app(MachineOperation::class)->binding($current) === app(MachineOperation::class)->binding($instance);
            $this->audit($user, $current, 'result.'.$data['request_id'], $data['reason'], ['status' => $status, 'binding_matches' => $matches]);
            if (! $matches) {
                return false;
            }
            $config['cloud_operation']['status'] = $status;
            $current->update(['active_config' => $config]);

            return true;
        });
        if (! $matches) {
            throw ValidationException::withMessages(['command' => '执行期间服务绑定或状态变化，请联系管理员核对上游结果。']);
        }
    }

    private function audit(User $user, Instance $instance, string $suffix, string $reason, array $after): void
    {
        DB::table('audit_logs')->insert(['actor_type' => 'user', 'actor_id' => $user->id,
            'action' => 'instance.machine.'.$suffix, 'subject_type' => Instance::class, 'subject_id' => $instance->id,
            'reason' => $reason, 'after' => json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
    }
}
