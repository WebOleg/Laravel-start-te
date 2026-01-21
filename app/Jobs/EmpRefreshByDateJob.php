<?php

/**
 * Main job for EMP Refresh - processes pages directly.
 */

namespace App\Jobs;

use App\Services\Emp\EmpRefreshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EmpRefreshByDateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 1;

    public const MAX_PAGES = 500;
    public const CACHE_TTL = 7200;

    public function __construct(
        public string $startDate,
        public string $endDate,
        public string $jobId
    ) {
        $this->onQueue('emp-refresh');
    }

    public function handle(EmpRefreshService $service): void
    {
        $cacheKey = "emp_refresh_{$this->jobId}";
        $totalStats = [
            'inserted' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'errors' => 0,
            'total' => 0,
        ];

        try {
            $this->updateCache($cacheKey, 'processing', 0, array_merge($totalStats, [
                'current_page' => 0,
                'total_pages' => 0,
            ]));

            Log::info('EmpRefreshByDateJob: starting', [
                'job_id' => $this->jobId,
                'start_date' => $this->startDate,
                'end_date' => $this->endDate,
            ]);

            $page = 1;
            $hasMore = true;
            $totalPages = 0;

            while ($hasMore && $page <= self::MAX_PAGES) {
                $result = $service->fetchPage($this->startDate, $this->endDate, $page);

                if ($result['error']) {
                    Log::error('EmpRefreshByDateJob: fetch error', [
                        'page' => $page,
                    ]);
                    $totalStats['errors']++;
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

                $pageStats = $service->processTransactions($transactions);

                $totalStats['inserted'] += $pageStats['inserted'];
                $totalStats['updated'] += $pageStats['updated'];
                $totalStats['unchanged'] += $pageStats['unchanged'] ?? 0;
                $totalStats['errors'] += $pageStats['errors'];
                $totalStats['total'] += count($transactions);

                $progress = $totalPages > 0
                    ? min(99, (int) round(($page / $totalPages) * 100))
                    : min(95, $page);

                $this->updateCache($cacheKey, 'processing', $progress, array_merge($totalStats, [
                    'current_page' => $page,
                    'total_pages' => $totalPages,
                ]));

                Log::info('EmpRefreshByDateJob: page processed', [
                    'job_id' => $this->jobId,
                    'page' => $page,
                    'total_pages' => $totalPages,
                    'progress' => $progress,
                    'transactions' => count($transactions),
                    'stats' => $pageStats,
                ]);

                $page++;
                usleep(200000);
            }

            $finalPage = $page - 1;
            
            $this->updateCache($cacheKey, 'completed', 100, array_merge($totalStats, [
                'current_page' => $finalPage,
                'total_pages' => $totalPages,
            ]), true);

            Cache::forget('emp_refresh_active');

            Log::info('EmpRefreshByDateJob: completed', [
                'job_id' => $this->jobId,
                'total_pages_processed' => $finalPage,
                'total_pages_available' => $totalPages,
                'stats' => $totalStats,
            ]);

        } catch (\Exception $e) {
            Cache::put($cacheKey, [
                'status' => 'failed',
                'failed_at' => now()->toIso8601String(),
                'error' => $e->getMessage(),
                'progress' => 0,
                'stats' => $totalStats,
            ], self::CACHE_TTL);

            Cache::forget('emp_refresh_active');

            Log::error('EmpRefreshByDateJob: failed', [
                'job_id' => $this->jobId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function updateCache(
        string $cacheKey,
        string $status,
        int $progress,
        array $stats,
        bool $completed = false
    ): void {
        $data = [
            'status' => $status,
            'progress' => $progress,
            'stats' => $stats,
            'started_at' => now()->toIso8601String(),
        ];

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
        ], self::CACHE_TTL);
    }
}
