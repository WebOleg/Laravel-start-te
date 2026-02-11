<?php

namespace App\Jobs;

use App\Models\BillingAttempt;
use App\Services\Emp\EmpBillingService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Throwable;

class VoidUploadChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public array $backoff = [10, 30, 60];

    // Reuse rate limit logic
    private const RATE_LIMIT_KEY = 'emp_void_rate_limit';
    private const RATE_LIMIT_PER_SECOND = 50;

    public function __construct(
        public array $attemptIds,
        public int $uploadId,
        public int $chunkIndex
    ) {
        $this->onQueue('billing');
    }

    public function handle(EmpBillingService $billingService): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        Log::info('VoidUploadChunkJob started', [
            'upload_id' => $this->uploadId,
            'chunk' => $this->chunkIndex,
            'attempts' => count($this->attemptIds),
        ]);

        $attempts = BillingAttempt::with('debtor')
            ->whereIn('id', $this->attemptIds)
            ->get();

        $results = [
            'success' => 0,
            'failed' => 0,
        ];

        foreach ($attempts as $attempt) {
            if ($this->batch()?->cancelled()) {
                break;
            }

            $this->rateLimit();

            try {
                $success = $billingService->voidAttempt($attempt);

                if ($success) {
                    $results['success']++;
                } else {
                    $results['failed']++;
                }

            } catch (Throwable $e) {
                Log::error('VoidUploadChunkJob: void failed', [
                    'attempt_id' => $attempt->id,
                    'error' => $e->getMessage(),
                ]);
                $results['failed']++;
            }
        }

        Log::info('VoidUploadChunkJob completed', [
            'upload_id' => $this->uploadId,
            'chunk' => $this->chunkIndex,
            'results' => $results,
        ]);
    }

    private function rateLimit(): void
    {
        $key = self::RATE_LIMIT_KEY . '_' . now()->format('YmdHis');
        $count = Cache::get($key, 0);

        if ($count >= self::RATE_LIMIT_PER_SECOND) {
            usleep(100000); // Wait 100ms
        }

        Cache::put($key, $count + 1, 2);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('VoidUploadChunkJob failed', [
            'upload_id' => $this->uploadId,
            'chunk' => $this->chunkIndex,
            'error' => $exception->getMessage(),
        ]);
    }
}
