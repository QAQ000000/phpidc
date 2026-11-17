<?php

declare(strict_types=1);

namespace App\Actions;

use App\Services\UpstreamOperationBuffer;
use Illuminate\Support\Facades\DB;

class CallUpstreamAction
{
    /** Drivers buffer redacted traces; only this Action persists them, including on failure. */
    public function execute(callable $call): mixed
    {
        try {
            return $call();
        } finally {
            foreach (app(UpstreamOperationBuffer::class)->drain() as $operation) {
                DB::table('upstream_operations')->insert($operation + ['created_at' => now(), 'updated_at' => now()]);
            }
        }
    }
}
