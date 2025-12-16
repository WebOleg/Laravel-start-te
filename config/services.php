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
    | emerchantpay Configuration
    |--------------------------------------------------------------------------
    */
    'emp' => [
        'username' => env('EMP_USERNAME'),
        'password' => env('EMP_PASSWORD'),
        'terminal_token' => env('EMP_TERMINAL_TOKEN'),
        'endpoint' => env('EMP_ENDPOINT', 'https://gate.emerchantpay.net'),
    ],
];
