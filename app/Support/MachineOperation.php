<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ProvisionCommand;
use App\Models\Instance;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class MachineOperation
{
    public function binding(Instance $instance): array
    {
        return ['user_id' => $instance->user_id, 'driver_type' => $instance->driver_type->value,
            'upstream_api_id' => $instance->upstream_api_id, 'upstream_instance_id' => $instance->upstream_instance_id,
            'downstream_token' => $instance->downstream_token, 'base_url' => $instance->upstreamApi?->base_url];
    }

    public function accountFingerprint(Instance $instance): string
    {
        $credentials = array_intersect_key($instance->upstreamApi?->getAttributes() ?? [],
            array_flip(['username', 'driver', 'auth_header_mode', 'config', 'encrypted_secret']));

        return hash('sha256', json_encode($credentials, JSON_THROW_ON_ERROR));
    }

    public function publicBinding(Instance $instance): array
    {
        return array_intersect_key($this->binding($instance), array_flip(['user_id', 'driver_type', 'upstream_api_id', 'upstream_instance_id']));
    }

    public function blocked(Instance $instance): bool
    {
        $operation = $instance->active_config['cloud_operation'] ?? [];
        if (! in_array($operation['status'] ?? null, ['requested', 'unknown'], true)) {
            return false;
        }
        $resolution = $operation['reconciliation'] ?? [];
        if (! is_int($resolution['audit_id'] ?? null) || ! is_string($operation['encrypted_binding'] ?? null)
            || json_decode(Crypt::decryptString($operation['encrypted_binding']), true, flags: JSON_THROW_ON_ERROR) !== $this->binding($instance)) {
            return true;
        }
        // The JSON pointer cannot authorize release without its atomically committed audit evidence.
        $audit = DB::table('audit_logs')->where('id', $resolution['audit_id'])->where('subject_type', Instance::class)
            ->where('subject_id', $instance->id)->where('action', 'like', 'instance.machine.reconcile.%')->first();
        $after = $audit === null ? [] : json_decode($audit->after, true, flags: JSON_THROW_ON_ERROR);

        return ($after['released'] ?? null) !== true || ($after['operation_request_id'] ?? null) !== ($operation['request_id'] ?? null);
    }

    public function summary(Instance $instance): ?array
    {
        $operation = $instance->active_config['cloud_operation'] ?? null;
        if (! is_array($operation)) {
            return null;
        }

        $binding = isset($operation['encrypted_binding'])
            ? json_decode(Crypt::decryptString($operation['encrypted_binding']), true, flags: JSON_THROW_ON_ERROR) : null;

        return array_intersect_key($operation, array_flip(['request_id', 'command', 'status', 'requested_at', 'parameters', 'reconciliation']))
            + ['binding' => $binding === null ? null : array_intersect_key($binding, array_flip(['user_id', 'driver_type', 'upstream_api_id', 'upstream_instance_id'])),
                'binding_recorded' => $binding !== null, 'binding_matches' => $binding === null ? null : $binding === $this->binding($instance),
                'blocked' => $this->blocked($instance)];
    }

    public function parameters(ProvisionCommand $command, array $input): array
    {
        // Keep only non-secret selection evidence. Rescue/VNC/password payloads are never retained.
        return $command === ProvisionCommand::REINSTALL
            ? array_intersect_key($input, array_flip(['os_group', 'os', 'port', 'format_data_disk'])) : [];
    }
}
