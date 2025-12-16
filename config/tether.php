<?php

/**
 * Tether application configuration.
 */

return [
    'chargeback' => [
        'alert_threshold' => env('CB_ALERT_THRESHOLD', 25),
        'cache_ttl' => env('CB_CACHE_TTL', 900),
    ],
];
