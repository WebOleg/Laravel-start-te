<?php
return [
    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
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
    'emp' => [
        'endpoint' => env('EMP_GENESIS_ENDPOINT', 'gate.emerchantpay.net'),
        'username' => env('EMP_GENESIS_USERNAME'),
        'password' => env('EMP_GENESIS_PASSWORD'),
        'terminal_token' => env('EMP_GENESIS_TERMINAL_TOKEN'),
        'webhook_token' => env('EMP_WEBHOOK_TOKEN'),
        'rate_limit' => [
            'requests_per_second' => 50,
            'max_retries' => 3,
            'retry_delay_ms' => 1000,
        ],
        'timeout' => 30,
        'connect_timeout' => 10,
    ],
    'iban' => [
        'api_key' => env('IBAN_API_KEY'),
        'api_url' => env('IBAN_API_URL', 'https://api.iban.com/clients/api/v4/iban/'),
        'bav_api_url' => env('BAV_API_URL', 'https://api.iban.com/clients/api/verify/v3/'),
        'balance_api_url' => env('IBAN_BALANCE_API_URL', 'https://api.ibanapi.com/v1/balance'),
        'mock' => env('IBAN_API_MOCK', true),
        'bav_enabled' => env('BAV_ENABLED', false),
        'bav_auto_select' => env('BAV_AUTO_SELECT', false),
        'bav_sampling_percentage' => env('BAV_SAMPLING_PERCENTAGE', 10),
        'bav_daily_limit' => env('BAV_DAILY_LIMIT', 100),
        'bav_chunk_size' => env('BAV_CHUNK_SIZE', 50),
        'bav_supported_countries' => ['AT', 'BE', 'CY', 'DE', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PT', 'SI', 'SK'],
    ],
    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],
];
