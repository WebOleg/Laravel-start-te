<?php

/**
 * Chunk job for EMP Refresh - processes a range of pages.
 * Handles rate limiting and progress tracking.
 */

namespace App\Jobs;

use App\Services\Emp\EmpRefreshService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EmpRefreshChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // 10 min per chunk
    public int $tries = 3;
    public int $backoff = 60; // 1 min between retries
    public string $queue = 'emp-refresh';

    public function __construct(
        public string $startDate,
        public string $endDate,
        public int $startPage,
        public int $endPage,
        public string $jobId
    ) {
        $this->onQueue('emp-refresh');
    }

    public function handle(EmpRefreshService $service): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $cacheKey = "emp_refresh_{$this->jobId}";
        $chunkStats = ['inserted' => 0, 'updated' => 0, 'errors' => 0, 'total' => 0];

        Log::info('EmpRefreshChunkJob: starting', [
            'job_id' => $this->jobId,
            'pages' => "{$this->startPage}-{$this->endPage}",
        ]);

        for ($page = $this->startPage; $page <= $this->endPage; $page++) {
            if ($this->batch()?->cancelled()) {
                break;
            }

            $result = $service->fetchPage($this->startDate, $this->endDate, $page);

            if ($result['error']) {
                Log::warning('EmpRefreshChunkJob: page error', ['page' => $page]);
                $chunkStats['errors']++;
                continue;
            }

            if (empty($result['transactions'])) {
                // No more data - stop processing further pages
                Log::info('EmpRefreshChunkJob: no more transactions', ['page' => $page]);
                break;
            }

            // Process transactions
            $pageStats = $service->processTransactions($result['transactions']);
            
            $chunkStats['inserted'] += $pageStats['inserted'];
            $chunkStats['updated'] += $pageStats['updated'];
            $chunkStats['errors'] += $pageStats['errors'];
            $chunkStats['total'] += $pageStats['inserted'] + $pageStats['updated'];

            // Rate limiting between pages
            usleep(200000); // 200ms = max 5 pages/second

            // If no more pages, stop
            if (!$result['has_more']) {
                break;
            }
        }

        // Update global stats
        $this->updateGlobalStats($cacheKey, $chunkStats);

        Log::info('EmpRefreshChunkJob: completed', [
            'job_id' => $this->jobId,
            'pages' => "{$this->startPage}-{$this->endPage}",
            'stats' => $chunkStats,
        ]);
    }

    private function updateGlobalStats(string $cacheKey, array $chunkStats): void
    {
        // Atomic update using cache lock
        Cache::lock("{$cacheKey}_lock", 10)->block(5, function () use ($cacheKey, $chunkStats) {
            $current = Cache::get($cacheKey, []);
            $stats = $current['stats'] ?? ['inserted' => 0, 'updated' => 0, 'errors' => 0, 'total' => 0];

            $stats['inserted'] += $chunkStats['inserted'];
            $stats['updated'] += $chunkStats['updated'];
            $stats['errors'] += $chunkStats['errors'];
            $stats['total'] += $chunkStats['total'];

            $processedChunks = ($current['processed_chunks'] ?? 0) + 1;
            $totalChunks = $current['total_chunks'] ?? 1;
            $progress = round(($processedChunks / $totalChunks) * 100);

            Cache::put($cacheKey, array_merge($current, [
                'stats' => $stats,
                'processed_chunks' => $processedChunks,
                'progress' => $progress,
            ]), 7200);
        });
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('EmpRefreshChunkJob: failed', [
            'job_id' => $this->jobId,
            'pages' => "{$this->startPage}-{$this->endPage}",
            'error' => $exception->getMessage(),
        ]);
    }
}
