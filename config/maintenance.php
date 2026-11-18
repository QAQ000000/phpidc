<?php

declare(strict_types=1);

return [
    'cache_store' => env('MAINTENANCE_CACHE_STORE', 'redis'),
    'queue' => 'maintenance',
    'limit' => 100,
    'budget_seconds' => 5,
    'lock_wait_seconds' => 2,
    'kinds' => ['invoices', 'fulfillment', 'cards', 'lifecycle', 'catalog', 'affiliate-accrue', 'affiliate-mature', 'notifications'],
    'subkinds' => ['notifications-expand', 'notifications-recover', 'notifications-dispatch', 'notifications-incidents', 'receivables-audit'],
];
