<?php

namespace App\Jobs;

use App\Models\BillingAttempt;
use App\Services\ReconciliationService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessReconciliationChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public array $backoff = [10, 30, 60];

    private const RATE_LIMIT_PER_SECOND = 20;
    private const CIRCUIT_BREAKER_THRESHOLD = 5;
    private const CIRCUIT_BREAKER_COOLDOWN = 600; // 10 minutes

    public function __construct(
        public array $attemptIds
    ) {
        $this->onQueue('reconciliation');
    }

    public function handle(ReconciliationService $reconciliationService): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        if ($this->isCircuitOpen()) {
            Log::warning('Reconciliation circuit breaker open, releasing job');
            $this->release(self::CIRCUIT_BREAKER_COOLDOWN);
            return;
        }

        $attempts = BillingAttempt::whereIn('id', $this->attemptIds)
            ->where('status', BillingAttempt::STATUS_PENDING)
            ->get();

        $failureCount = 0;
        $processed = 0;
        $changed = 0;

        foreach ($attempts as $attempt) {
            if ($this->batch()?->cancelled()) {
                break;
            }

            try {
                $result = $reconciliationService->reconcileAttempt($attempt);

                if ($result['success']) {
                    $failureCount = 0; // Reset on success
                    $processed++;
                    if ($result['changed']) {
                        $changed++;
                    }
                } else {
                    $failureCount++;
                }
            } catch (\Exception $e) {
                $failureCount++;
                Log::error('Reconciliation chunk error', [
                    'billing_attempt_id' => $attempt->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Circuit breaker check
            if ($failureCount >= self::CIRCUIT_BREAKER_THRESHOLD) {
                $this->openCircuit();
                Log::warning('Reconciliation circuit breaker triggered', [
                    'failures' => $failureCount,
                ]);
                $this->release(self::CIRCUIT_BREAKER_COOLDOWN);
                return;
            }

            // Rate limiting
            usleep((int) (1000000 / self::RATE_LIMIT_PER_SECOND));
        }

        Log::info('Reconciliation chunk completed', [
            'total' => count($this->attemptIds),
            'processed' => $processed,
            'changed' => $changed,
        ]);
    }

    private function isCircuitOpen(): bool
    {
        return Cache::has('reconciliation_circuit_open');
    }

    private function openCircuit(): void
    {
        Cache::put('reconciliation_circuit_open', true, self::CIRCUIT_BREAKER_COOLDOWN);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessReconciliationChunkJob failed', [
            'attempt_ids' => $this->attemptIds,
            'error' => $exception->getMessage(),
        ]);
    }
}
