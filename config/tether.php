<?php
/**
 * Tether application configuration.
 */
return [
    'chargeback' => [
        'alert_threshold' => env('CB_ALERT_THRESHOLD', 25),
        'cache_ttl' => env('CB_CACHE_TTL', 900),
        'blacklist_codes' => ['AC04', 'AC06', 'AG01', 'MD01'],
    ],
];
