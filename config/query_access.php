<?php

$hardTimeoutSeconds = min(65_535, max(5, (int) env('QUERY_ACCESS_MAX_TIMEOUT_SECONDS', 900)));

return [
    'minimum_timeout_seconds' => 5,
    'maximum_timeout_seconds' => $hardTimeoutSeconds,
    'default_timeout_seconds' => min(120, $hardTimeoutSeconds),
    'queue_connection' => env(
        'QUERY_ACCESS_QUEUE_CONNECTION',
        env('QUEUE_CONNECTION') === 'sync' ? 'sync' : 'redis-query-sessions',
    ),
    'queue' => env('QUERY_ACCESS_QUEUE', 'query-sessions'),
    'worker_timeout_seconds' => $hardTimeoutSeconds + 15,
    'reconciliation_grace_seconds' => 30,
    'queue_stale_seconds' => $hardTimeoutSeconds + 120,
];
