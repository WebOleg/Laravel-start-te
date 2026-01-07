<?php

/**
 * Main job for EMP Refresh - dispatches chunk jobs.
 * Uses Laravel Bus::batch for parallel processing with progress tracking.
 */

namespace App\Jobs;

use App\Services\Emp\EmpRefreshService;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EmpRefreshByDateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // 5 min for setup
    public int $tries = 1;
    public string $queue = 'emp-refresh';

    public const MAX_PAGES = 1000; // Safety limit
    public const PAGES_PER_CHUNK = 5; // Pages per chunk job

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
                'status' => 'initializing',
                'started_at' => now()->toIso8601String(),
                'progress' => 0,
            ], 7200);

            Log::info('EmpRefreshByDateJob: starting', [
                'job_id' => $this->jobId,
                'start_date' => $this->startDate,
                'end_date' => $this->endDate,
            ]);

            // Estimate total pages by fetching first page
            $estimate = $service->estimatePages($this->startDate, $this->endDate);

            if ($estimate['error']) {
                throw new \RuntimeException('Failed to fetch first page from EMP');
            }

            if ($estimate['first_page_count'] === 0) {
                Cache::put($cacheKey, [
                    'status' => 'completed',
                    'completed_at' => now()->toIso8601String(),
                    'stats' => ['inserted' => 0, 'updated' => 0, 'errors' => 0, 'total' => 0],
                    'message' => 'No transactions found for date range',
                ], 7200);
                Cache::forget('emp_refresh_active');
                return;
            }

            // Create chunk jobs
            $jobs = [];
            $page = 1;

            // We'll create jobs for pages we know exist, plus some buffer
            $estimatedPages = $estimate['has_more'] ? self::MAX_PAGES : 1;

            while ($page <= $estimatedPages) {
                $endPage = min($page + self::PAGES_PER_CHUNK - 1, $estimatedPages);
                
                $jobs[] = new EmpRefreshChunkJob(
                    $this->startDate,
                    $this->endDate,
                    $page,
                    $endPage,
                    $this->jobId
                );

                $page = $endPage + 1;
            }

            Cache::put($cacheKey, [
                'status' => 'processing',
                'started_at' => now()->toIso8601String(),
                'total_chunks' => count($jobs),
                'processed_chunks' => 0,
                'stats' => ['inserted' => 0, 'updated' => 0, 'errors' => 0, 'total' => 0],
            ], 7200);

            // Dispatch batch
            Bus::batch($jobs)
                ->name("EMP Refresh {$this->startDate} to {$this->endDate}")
                ->allowFailures()
                ->finally(function (Batch $batch) use ($cacheKey) {
                    $this->finalizeBatch($batch, $cacheKey);
                })
                ->onQueue('emp-refresh')
                ->dispatch();

            Log::info('EmpRefreshByDateJob: batch dispatched', [
                'job_id' => $this->jobId,
                'chunks' => count($jobs),
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

    private function finalizeBatch(Batch $batch, string $cacheKey): void
    {
        $current = Cache::get($cacheKey, []);
        $stats = $current['stats'] ?? ['inserted' => 0, 'updated' => 0, 'errors' => 0, 'total' => 0];

        Cache::put($cacheKey, [
            'status' => $batch->hasFailures() ? 'completed_with_errors' : 'completed',
            'completed_at' => now()->toIso8601String(),
            'stats' => $stats,
            'batch_id' => $batch->id,
            'failed_jobs' => $batch->failedJobs,
        ], 7200);

        // Clear active flag
        Cache::forget('emp_refresh_active');

        Log::info('EmpRefreshByDateJob: batch completed', [
            'batch_id' => $batch->id,
            'stats' => $stats,
            'failed_jobs' => $batch->failedJobs,
        ]);
    }
}
