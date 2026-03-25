<?php

/**
 * Main job for EMP Refresh - processes pages directly.
 * Supports multiple EMP accounts.
 */

namespace App\Jobs;

use App\Models\EmpAccount;
use App\Services\Emp\EmpClient;
use App\Services\Emp\EmpRefreshService;
use App\Traits\WithLogContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EmpRefreshByDateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WithLogContext;

    public int $timeout = 3600;
    public int $tries = 1;

    public const MAX_PAGES = 500;
    public const CACHE_TTL = 7200;

    public function __construct(
        public string $startDate,
        public string $endDate,
        public string $jobId,
        public array $accountIds = []
    ) {
        $this->onQueue('emp-refresh');
    }

    public function handle(): void
    {
        $this->initLogContext();

        $cacheKey = "emp_refresh_{$this->jobId}";
        $startedAt = now();

        $totalStats = [
            'inserted' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'errors' => 0,
            'total' => 0,
        ];

        $perAccountStats = [];

        try {
            $this->updateCache($cacheKey, 'processing', 0, $totalStats, [
                'accounts_total' => count($this->accountIds),
                'accounts_processed' => 0,
                'current_account' => null,
                'current_page' => 0,
                'total_pages' => 0,
            ]);

            Log::info('EmpRefreshByDateJob: starting', [
                'job_id' => $this->jobId,
                'start_date' => $this->startDate,
                'end_date' => $this->endDate,
                'accounts_count' => count($this->accountIds),
            ]);

            $accountsProcessed = 0;

            foreach ($this->accountIds as $accountId) {
                $account = EmpAccount::find($accountId);

                if (!$account) {
                    Log::warning('EmpRefreshByDateJob: account not found', ['account_id' => $accountId]);
                    $totalStats['errors']++;
                    continue;
                }

                Log::info('EmpRefreshByDateJob: processing account', [
                    'job_id' => $this->jobId,
                    'account_id' => $accountId,
                    'account_name' => $account->name,
                ]);

                $client = new EmpClient($account);
                $service = new EmpRefreshService($client);

                $page = 1;
                $hasMore = true;
                $totalPages = 0;
                $accountStartedAt = now();

                $perAccountStats[$account->name] = [
                    'inserted' => 0,
                    'updated' => 0,
                    'unchanged' => 0,
                    'errors' => 0,
                    'total' => 0,
                    'pages' => 0,
                    'duration_seconds' => 0,
                ];

                while ($hasMore && $page <= self::MAX_PAGES) {
                    $result = $service->fetchPage($this->startDate, $this->endDate, $page);

                    if ($result['error']) {
                        Log::error('EmpRefreshByDateJob: fetch error', [
                            'account_id' => $accountId,
                            'page' => $page,
                        ]);
                        $totalStats['errors']++;
                        $perAccountStats[$account->name]['errors']++;
                        break;
                    }

                    $transactions = $result['transactions'];
                    $hasMore = $result['has_more'];

                    if (isset($result['pagination']['pages_count'])) {
                        $totalPages = (int) $result['pagination']['pages_count'];
                    }

                    if (empty($transactions)) {
                        break;
                    }

                    $pageStats = $service->processTransactions($transactions, $accountId);

                    $totalStats['inserted'] += $pageStats['inserted'];
                    $totalStats['updated'] += $pageStats['updated'];
                    $totalStats['unchanged'] += $pageStats['unchanged'] ?? 0;
                    $totalStats['errors'] += $pageStats['errors'];
                    $totalStats['total'] += count($transactions);

                    $perAccountStats[$account->name]['inserted'] += $pageStats['inserted'];
                    $perAccountStats[$account->name]['updated'] += $pageStats['updated'];
                    $perAccountStats[$account->name]['unchanged'] += $pageStats['unchanged'] ?? 0;
                    $perAccountStats[$account->name]['errors'] += $pageStats['errors'];
                    $perAccountStats[$account->name]['total'] += count($transactions);
                    $perAccountStats[$account->name]['pages'] = $page;

                    $accountProgress = $totalPages > 0
                        ? min(99, (int) round(($page / $totalPages) * 100))
                        : min(95, $page);

                    $overallProgress = (int) round(
                        ($accountsProcessed / count($this->accountIds) * 100) +
                        ($accountProgress / count($this->accountIds))
                    );

                    // per_account is NOT written on every page — only progress and current state.
                    // per_account is written only after account completion and at job completion.
                    $this->updateCache($cacheKey, 'processing', $overallProgress, $totalStats, [
                        'accounts_total' => count($this->accountIds),
                        'accounts_processed' => $accountsProcessed,
                        'current_account' => $account->name,
                        'current_page' => $page,
                      'total_pages' => $totalPages,
                    ]);

                    Log::info('EmpRefreshByDateJob: page processed', [
                        'job_id' => $this->jobId,
                        'account_id' => $accountId,
                        'page' => $page,
                        'total_pages' => $totalPages,
                        'progress' => $overallProgress,
                        'transactions' => count($transactions),
                        'stats' => $pageStats,
                    ]);

                    $page++;
                    usleep(200000);
                }

                $perAccountStats[$account->name]['duration_seconds'] = $accountStartedAt->diffInSeconds(now());
                $accountsProcessed++;

                Log::info('EmpRefreshByDateJob: account completed', [
                    'job_id' => $this->jobId,
                    'account_id' => $accountId,
                    'account_name' => $account->name,
                    'pages_processed' => $page - 1,
                    'account_stats' => $perAccountStats[$account->name],
                ]);

                // Write per_account after each account completes — not on every page.
                $this->updateCache($cacheKey, 'processing', $overallProgress ?? 0, $totalStats, [
                    'accounts_total' => count($this->accountIds),
                    'accounts_processed' => $accountsProcessed,
                    'current_account' => null,
                    'per_account' => $perAccountStats,
                    'duration_seconds' => $startedAt->diffInSeconds(now()),
                ]);
            }

            $this->updateCache($cacheKey, 'completed', 100, $totalStats, [
                'accounts_total' => count($this->accountIds),
                'accounts_processed' => $accountsProcessed,
                'current_account' => null,
                'per_account' => $perAccountStats,
                'duration_seconds' => $startedAt->diffInSeconds(now()),
            ], true);

          Cache::forget('emp_refresh_active');

            Log::info('EmpRefreshByDateJob: completed', [
                'job_id' => $this->jobId,
                'accounts_processed' => $accountsProcessed,
                'stats' => $totalStats,
                'per_account' => $perAccountStats,
                'duration_seconds' => $startedAt->diffInSeconds(now()),
            ]);

        } catch (\Exception $e) {
            Cache::put($cacheKey, [
                'status' => 'failed',
                'failed_at' => now()->toIso8601String(),
                'error' => $e->getMessage(),
                'progress' => 0,
                'stats' => $totalStats,
                'per_account' => $perAccountStats,
                'duration_seconds' => $startedAt->diffInSeconds(now()),
            ], self::CACHE_TTL);

            Cache::forget('emp_refresh_active');

            Log::error('EmpRefreshByDateJob: failed', [
                'job_id' => $this->jobId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    private function updateCache(
        string $cacheKey,
        string $status,
        int $progress,
        array $stats,
        array $additionalData = [],
        bool $completed = false
    ): void {
        $data = [
            'status' => $status,
            'progress' => $progress,
            'stats' => $stats,
            'started_at' => now()->toIso8601String(),
        ];

        $data = array_merge($data, $additionalData);

        if ($completed) {
            $data['completed_at'] = now()->toIso8601String();
        }

        Cache::put($cacheKey, $data, self::CACHE_TTL);

        Cache::put('emp_refresh_active', [
            'job_id' => $this->jobId,
            'status' => $status,
            'started_at' => now()->toIso8601String(),
            'from' => $this->startDate,
            'to' => $this->endDate,
            'progress' => $progress,
            'stats' => $stats,
        ] + $additionalData, self::CACHE_TTL);
    }
}
