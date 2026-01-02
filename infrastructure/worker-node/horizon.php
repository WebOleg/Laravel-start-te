<?php

/**
 * Horizon Configuration for Worker Nodes
 * THR-172: Provision worker nodes
 *
 * 3 Supervisors:
 * - webhooks: High priority, fast webhook processing
 * - billing: Payment processing jobs
 * - default: General purpose jobs
 */

return [
    'domain' => env('HORIZON_DOMAIN'),
    'path' => 'horizon',

    'use' => 'default',

    'prefix' => env('HORIZON_PREFIX', 'horizon:'),

    'middleware' => ['web', 'auth'],

    'waits' => [
        'redis:webhooks' => 30,
        'redis:billing' => 60,
        'redis:default' => 60,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    'memory_limit' => 256,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | 3 Supervisors for different job priorities:
    |
    | webhooks: Fast processing for EMP webhooks (critical path)
    |   - 5 processes
    |   - Max 100 jobs per process
    |   - 30 second timeout
    |
    | billing: Payment processing jobs
    |   - 3 processes
    |   - Max 50 jobs per process
    |   - 120 second timeout (API calls)
    |
    | default: General purpose (uploads, emails, etc.)
    |   - 3 processes
    |   - Max 100 jobs per process
    |   - 60 second timeout
    |
    */

    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 10,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 3,
            'timeout' => 60,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            // Webhook processing - HIGH PRIORITY
            'webhooks-supervisor' => [
                'connection' => 'redis',
                'queue' => ['webhooks'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'minProcesses' => 3,
                'maxProcesses' => 5,
                'maxTime' => 3600,
                'maxJobs' => 100,
                'memory' => 256,
                'tries' => 3,
                'timeout' => 30,
                'nice' => 0,
            ],

            // Billing/Payment processing
            'billing-supervisor' => [
                'connection' => 'redis',
                'queue' => ['billing'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'minProcesses' => 2,
                'maxProcesses' => 3,
                'maxTime' => 3600,
                'maxJobs' => 50,
                'memory' => 256,
                'tries' => 3,
                'timeout' => 120,
                'nice' => 0,
            ],

            // Default queue
            'default-supervisor' => [
                'connection' => 'redis',
                'queue' => ['default'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'minProcesses' => 2,
                'maxProcesses' => 3,
                'maxTime' => 3600,
                'maxJobs' => 100,
                'memory' => 256,
                'tries' => 3,
                'timeout' => 60,
                'nice' => 0,
            ],
        ],

        'staging' => [
            'webhooks-supervisor' => [
                'connection' => 'redis',
                'queue' => ['webhooks'],
                'balance' => 'auto',
                'minProcesses' => 1,
                'maxProcesses' => 2,
                'maxJobs' => 100,
                'memory' => 128,
                'tries' => 3,
                'timeout' => 30,
            ],
            'billing-supervisor' => [
                'connection' => 'redis',
                'queue' => ['billing'],
                'balance' => 'auto',
                'minProcesses' => 1,
                'maxProcesses' => 2,
                'maxJobs' => 50,
                'memory' => 128,
                'tries' => 3,
                'timeout' => 120,
            ],
            'default-supervisor' => [
                'connection' => 'redis',
                'queue' => ['default'],
                'balance' => 'auto',
                'minProcesses' => 1,
                'maxProcesses' => 2,
                'maxJobs' => 100,
                'memory' => 128,
                'tries' => 3,
                'timeout' => 60,
            ],
        ],
    ],
];
