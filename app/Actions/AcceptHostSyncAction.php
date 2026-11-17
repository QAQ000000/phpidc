<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\DriverType;
use App\Jobs\ReconcileHostSyncJob;
use App\Models\Instance;
use Illuminate\Cache\RedisStore;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcceptHostSyncAction
{
    public function execute(array $payload): void
    {
        $data = validator($payload, ['id' => ['required', 'integer', 'min:1'],
            'rand_str' => ['required', 'string', 'regex:/^[A-Za-z0-9]{6}$/D'],
            'signature' => ['required', 'string', 'regex:/^[a-fA-F0-9]{32}$/D']])->validate();
        $instance = Instance::find($data['id']);
        if ($instance === null || $instance->driver_type !== DriverType::ZJMF_API || empty($instance->downstream_token)) {
            throw ValidationException::withMessages(['signature' => 'Invalid host sync signature.']);
        }
        // Classic installations serialize downstream_id as either an integer or a form string.
        $valid = false;
        foreach ([(int) $instance->id, (string) $instance->id] as $id) {
            $params = ['id' => $id, 'token' => $instance->downstream_token, 'rand_str' => $data['rand_str']];
            ksort($params, SORT_STRING);
            $valid = hash_equals(strtoupper(md5(json_encode($params, JSON_THROW_ON_ERROR))), strtoupper($data['signature'])) || $valid;
        }
        if (! $valid) {
            throw ValidationException::withMessages(['signature' => 'Invalid host sync signature.']);
        }
        $key = "host_sync:{$instance->id}:{$data['rand_str']}";
        // Production Redis uses one SET NX EX; local stores use their atomic add.
        $store = Cache::getStore();
        if ($store instanceof RedisStore) {
            $connection = $store->connection();
            $claimed = $connection instanceof PhpRedisConnection
                ? $connection->set($store->getPrefix().$key, '1', 'EX', 600, 'NX')
                : $connection->command('set', [$store->getPrefix().$key, '1', 'EX', 600, 'NX']);
        } else {
            $claimed = Cache::add($key, true, 600);
        }
        abort_unless($claimed, 400, 'Replayed host sync.');
        try {
            // A sync queue would do HTTP inline. Use the durable database queue in that configuration.
            $connection = config('queue.default') === 'sync' ? 'database' : config('queue.default');
            DB::afterCommit(fn () => ReconcileHostSyncJob::dispatch($instance->id, $instance->upstream_api_id,
                $instance->upstream_instance_id, $instance->downstream_token)->onConnection($connection));
        } catch (\Exception $error) {
            Cache::forget($key);
            throw $error;
        }
        // The other callback fields are unsigned. Only an authenticated re-query may change state.
    }
}
