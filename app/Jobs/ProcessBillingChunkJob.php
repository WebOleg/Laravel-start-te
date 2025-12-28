<?php

namespace App\Jobs;

use App\Models\BillingAttempt;
use App\Models\Debtor;
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

class ProcessBillingChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public array $backoff = [10, 30, 60];

    private const RATE_LIMIT_KEY = 'emp_billing_rate_limit';
    private const RATE_LIMIT_PER_SECOND = 50;
    private const CIRCUIT_BREAKER_KEY = 'emp_circuit_breaker';
    private const CIRCUIT_BREAKER_THRESHOLD = 10;
    private const CIRCUIT_BREAKER_TIMEOUT = 300; // 5 minutes

    public function __construct(
        public array $debtorIds,
        public int $uploadId,
        public int $chunkIndex,
        public ?string $notificationUrl = null
    ) {
        $this->onQueue('billing');
    }

    public function handle(EmpBillingService $billingService): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        // Check circuit breaker
        if ($this->isCircuitOpen()) {
            Log::warning('ProcessBillingChunkJob: circuit breaker open, releasing job', [
                'upload_id' => $this->uploadId,
                'chunk' => $this->chunkIndex,
            ]);
            $this->release(60); // Retry in 60 seconds
            return;
        }

        Log::info('ProcessBillingChunkJob started', [
            'upload_id' => $this->uploadId,
            'chunk' => $this->chunkIndex,
            'debtors' => count($this->debtorIds),
        ]);

        $results = [
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        $consecutiveFailures = 0;

        foreach ($this->debtorIds as $debtorId) {
            // Check if batch cancelled
            if ($this->batch()?->cancelled()) {
                break;
            }

            // Rate limiting
            $this->rateLimit();

            $debtor = Debtor::find($debtorId);
            if (!$debtor || !$billingService->canBill($debtor)) {
                $results['skipped']++;
                continue;
            }

            try {
                $attempt = $billingService->billDebtor($debtor, $this->notificationUrl);

                if ($attempt->isApproved() || $attempt->isPending()) {
                    $results['success']++;
                    $consecutiveFailures = 0;
                } else {
                    $results['failed']++;
                    $consecutiveFailures++;
                }

            } catch (Throwable $e) {
                Log::error('ProcessBillingChunkJob: billing failed', [
                    'debtor_id' => $debtorId,
                    'error' => $e->getMessage(),
                ]);
                $results['failed']++;
                $consecutiveFailures++;

                // Check for circuit breaker
                if ($consecutiveFailures >= self::CIRCUIT_BREAKER_THRESHOLD) {
                    $this->openCircuit();
                    Log::error('ProcessBillingChunkJob: circuit breaker triggered', [
                        'upload_id' => $this->uploadId,
                        'consecutive_failures' => $consecutiveFailures,
                    ]);
                    $this->release(self::CIRCUIT_BREAKER_TIMEOUT);
                    return;
                }
            }
        }

        Log::info('ProcessBillingChunkJob completed', [
            'upload_id' => $this->uploadId,
            'chunk' => $this->chunkIndex,
            'results' => $results,
        ]);
    }

    /**
     * Simple rate limiting using cache.
     */
    private function rateLimit(): void
    {
        $key = self::RATE_LIMIT_KEY . '_' . now()->format('YmdHis');
        $count = Cache::get($key, 0);

        if ($count >= self::RATE_LIMIT_PER_SECOND) {
            usleep(100000); // Wait 100ms
        }

        Cache::put($key, $count + 1, 2); // TTL 2 seconds
    }

    /**
     * Check if circuit breaker is open.
     */
    private function isCircuitOpen(): bool
    {
        return Cache::has(self::CIRCUIT_BREAKER_KEY);
    }

    /**
     * Open circuit breaker.
     */
    private function openCircuit(): void
    {
        Cache::put(self::CIRCUIT_BREAKER_KEY, true, self::CIRCUIT_BREAKER_TIMEOUT);
    }

    /**
     * Handle job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('ProcessBillingChunkJob failed', [
            'upload_id' => $this->uploadId,
            'chunk' => $this->chunkIndex,
            'error' => $exception->getMessage(),
        ]);
    }
}
