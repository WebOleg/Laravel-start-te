<?php
/**
 * Horizon configuration for Tether fintech platform.
 * 
 * Queue Priority:
 * - critical: Payments, refunds (highest)
 * - webhooks: Webhook notifications (high priority, isolated processing)
 * - high: VOP verification, alerts
 * - vop: VOP verification jobs (processed with high priority)
 * - billing: EMP billing jobs (processed with high priority)
 * - reconciliation: Reconciliation jobs
 * - emp-refresh: EMP inbound sync (long-running import)
 * - default: File processing, imports
 * - low: Reports, notifications, cleanup
 */
use Illuminate\Support\Str;

return [
    'domain' => env('HORIZON_DOMAIN'),
    'path' => 'horizon',
    'use' => 'default',
    'prefix' => env('HORIZON_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'),
    'middleware' => ['web'],
    'waits' => [
        'redis:critical' => 30,
        'redis:webhooks' => 30,
        'redis:high' => 60,
        'redis:vop' => 60,
        'redis:billing' => 60,
        'redis:reconciliation' => 60,
        'redis:emp-refresh' => 120,
        'redis:default' => 120,
        'redis:low' => 300,
    ],
    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],
    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],
    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],
    'fast_termination' => false,
    'memory_limit' => 128,
    'defaults' => [
        'supervisor-critical' => [
            'connection' => 'redis',
            'queue' => ['critical'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 5,
            'maxTime' => 0,
            'maxJobs' => 500,
            'memory' => 128,
            'tries' => 5,
            'timeout' => 60,
            'nice' => 0,
        ],
        'supervisor-webhooks' => [
            'connection' => 'redis',
            'queue' => ['webhooks'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 4,
            'maxTime' => 0,
            'maxJobs' => 500,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 60,
            'nice' => 0,
            'visibility_timeout' => 120,
        ],
        'supervisor-high' => [
            'connection' => 'redis',
            'queue' => ['high', 'vop', 'billing', 'reconciliation'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 3,
            'maxTime' => 0,
            'maxJobs' => 500,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 180,
            'nice' => 0,
        ],
        'supervisor-emp-refresh' => [
            'connection' => 'redis',
            'queue' => ['emp-refresh'],
            'balance' => 'simple',
            'maxProcesses' => 2,
            'maxTime' => 0,
            'maxJobs' => 100,
            'memory' => 256,
            'tries' => 3,
            'timeout' => 900,
            'nice' => 5,
        ],
        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 3,
            'maxTime' => 0,
            'maxJobs' => 1000,
            'memory' => 256,
            'tries' => 3,
            'timeout' => 600,
            'nice' => 0,
        ],
        'supervisor-low' => [
            'connection' => 'redis',
            'queue' => ['low', 'default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 2,
            'maxTime' => 0,
            'maxJobs' => 1000,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 600,
            'nice' => 5,
        ],
    ],
    'environments' => [
        'production' => [
            'supervisor-critical' => [
                'maxProcesses' => 10,
                'balanceMaxShift' => 3,
                'balanceCooldown' => 3,
            ],
            'supervisor-webhooks' => [
                'maxProcesses' => 8,
                'balanceMaxShift' => 2,
                'balanceCooldown' => 3,
            ],
            'supervisor-high' => [
                'maxProcesses' => 5,
                'balanceMaxShift' => 2,
                'balanceCooldown' => 3,
            ],
            'supervisor-emp-refresh' => [
                'maxProcesses' => 3,
            ],
            'supervisor-default' => [
                'maxProcesses' => 5,
                'balanceMaxShift' => 2,
                'balanceCooldown' => 3,
            ],
            'supervisor-low' => [
                'maxProcesses' => 3,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 5,
            ],
        ],
        'local' => [
            'supervisor-critical' => [
                'maxProcesses' => 2,
            ],
            'supervisor-webhooks' => [
                'maxProcesses' => 2,
            ],
            'supervisor-high' => [
                'maxProcesses' => 2,
            ],
            'supervisor-emp-refresh' => [
                'maxProcesses' => 1,
            ],
            'supervisor-default' => [
                'maxProcesses' => 2,
            ],
            'supervisor-low' => [
                'maxProcesses' => 1,
            ],
        ],
    ],
];
