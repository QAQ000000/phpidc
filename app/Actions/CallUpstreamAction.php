<?php

declare(strict_types=1);

namespace App\Actions;

use App\Services\UpstreamOperationBuffer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CallUpstreamAction
{
    /** Observe each completed HTTP exchange so later worker failure cannot erase previous traces. */
    public function execute(callable $call): mixed
    {
        $buffer = app(UpstreamOperationBuffer::class);
        foreach ($buffer->drain() as $operation) {
            $this->record($operation);
        }
        $previous = $buffer->observe(fn (array $operation) => $this->record($operation));
        try {
            return $call();
        } finally {
            $buffer->observe($previous);
        }
    }

    private function record(array $operation): void
    {
        DB::table('upstream_operations')->insert($operation + ['created_at' => now(), 'updated_at' => now()]);
        $params = json_decode($operation['request_payload'], true, flags: JSON_THROW_ON_ERROR);
        if ($operation['path'] === '/apply_credit' && ($params['use_credit'] ?? null) === 0 && $operation['outcome'] !== 'rolled_back') {
            Log::critical('Upstream credit rollback failed; operator intervention required.', ['upstream_api_id' => $operation['upstream_api_id'],
                'instance_id' => $operation['instance_id'], 'correlation_key' => $operation['correlation_key']]);
        }
    }
}
