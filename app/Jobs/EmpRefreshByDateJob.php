<?php

/**
 * Main job for EMP Refresh - processes pages directly.
 * Simplified approach without batch for reliability.
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

    public int $timeout = 1800; // 30 min max
    public int $tries = 1;

    public const MAX_PAGES = 100; // Safety limit

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

        try {
            Cache::put($cacheKey, [
                'status' => 'processing',
                'started_at' => now()->toIso8601String(),
                'progress' => 0,
                'stats' => ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => 0, 'total' => 0],
            ], 7200);

            Log::info('EmpRefreshByDateJob: starting', [
                'job_id' => $this->jobId,
                'start_date' => $this->startDate,
                'end_date' => $this->endDate,
            ]);

            $totalStats = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => 0, 'total' => 0];
            $page = 1;
            $hasMore = true;

            while ($hasMore && $page <= self::MAX_PAGES) {
                // Fetch page
                $result = $service->fetchPage($this->startDate, $this->endDate, $page);

                if ($result['error']) {
                    Log::error('EmpRefreshByDateJob: fetch error', ['page' => $page]);
                    $totalStats['errors']++;
                    break;
                }

                $transactions = $result['transactions'];
                $hasMore = $result['has_more'];

                if (empty($transactions)) {
                    break;
                }

                // Process transactions
                $pageStats = $service->processTransactions($transactions);
                
                $totalStats['inserted'] += $pageStats['inserted'];
                $totalStats['updated'] += $pageStats['updated'];
                $totalStats['unchanged'] += $pageStats['unchanged'] ?? 0;
                $totalStats['errors'] += $pageStats['errors'];
                $totalStats['total'] += count($transactions);

                // Update progress
                $progress = $hasMore ? min(95, $page * 10) : 100;
                Cache::put($cacheKey, [
                    'status' => 'processing',
                    'started_at' => now()->toIso8601String(),
                    'progress' => $progress,
                    'stats' => $totalStats,
                    'current_page' => $page,
                ], 7200);

                Log::info('EmpRefreshByDateJob: page processed', [
                    'job_id' => $this->jobId,
                    'page' => $page,
                    'transactions' => count($transactions),
                    'stats' => $pageStats,
                ]);

                $page++;

                // Rate limit between pages
                usleep(200000); // 200ms
            }

            // Mark completed
            Cache::put($cacheKey, [
                'status' => 'completed',
                'completed_at' => now()->toIso8601String(),
                'progress' => 100,
                'stats' => $totalStats,
            ], 7200);

            Cache::forget('emp_refresh_active');

            Log::info('EmpRefreshByDateJob: completed', [
                'job_id' => $this->jobId,
                'stats' => $totalStats,
            ]);

        } catch (\Exception $e) {
            Cache::put($cacheKey, [
                'status' => 'failed',
                'failed_at' => now()->toIso8601String(),
                'error' => $e->getMessage(),
            ], 7200);

            Cache::forget('emp_refresh_active');

            Log::error('EmpRefreshByDateJob: failed', [
                'job_id' => $this->jobId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
