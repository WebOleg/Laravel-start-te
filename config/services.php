<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | emerchantpay Genesis API Configuration
    |--------------------------------------------------------------------------
    */
    'emp' => [
        'endpoint' => env('EMP_GENESIS_ENDPOINT', 'gate.emerchantpay.net'),
        'username' => env('EMP_GENESIS_USERNAME'),
        'password' => env('EMP_GENESIS_PASSWORD'),
        'terminal_token' => env('EMP_GENESIS_TERMINAL_TOKEN'),
        
        // Rate limiting
        'rate_limit' => [
            'requests_per_second' => 50,
            'max_retries' => 3,
            'retry_delay_ms' => 1000,
        ],
        
        // Timeouts
        'timeout' => 30,
        'connect_timeout' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | IBAN.com BAV API Configuration
    |--------------------------------------------------------------------------
    */
    'iban' => [
        'api_key' => env('IBAN_API_KEY'),
        'api_url' => env('IBAN_API_URL', 'https://api.iban.com/clients/api/v4/iban/'),
        'mock' => env('IBAN_API_MOCK', true),
    ],
];
