<?php

declare(strict_types=1);

return [
    'cache_store' => env('RECEIVABLE_CHECK_CACHE_STORE', 'redis'),
    'foreground_seconds' => 2,
    'background_seconds' => 90,
    'result_ttl_seconds' => 600,
    'algorithm_version' => 1,
    'root_chunk_size' => 100,
    'evidence_chunk_size' => 500,
];
