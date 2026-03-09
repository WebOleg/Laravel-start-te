<?php

/**
 * Job to fetch chargeback reason codes from EMP Chargeback API.
 *
 * Dispatched after reconciliation detects new chargebacks. The EMP Reconcile API
 * returns status=chargebacked but does NOT include reason_code (confirmed in EMP docs).
 * Reason codes are only available via the separate /chargebacks/by_date endpoint,
 * which has a delay of several hours after reconciliation.
 *
 * Retry strategy: 4h -> 24h -> 48h to accommodate EMP API delays.
 */

namespace App\Jobs;

use App\Models\BillingAttempt;
use App\Services\Emp\EmpChargebackSyncService;
use App\Traits\WithLogContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class FetchChargebackReasonJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WithLogContext;

    public int $tries = 3;
    public int $timeout = 300;
    public array $backoff = [14400, 86400, 172800];

    public function __construct(
        public string $chargebackDate,
        public ?int $uploadId = null
    ) {
        $this->onQueue('reconciliation');
    }

    public function handle(EmpChargebackSyncService $service): void
    {
        $this->initLogContext();

        $pendingCount = $this->getPendingCount();

        if ($pendingCount === 0) {
            Log::info('FetchChargebackReasonJob: No chargebacks missing reason_code, skipping API call', [
                'chargeback_date' => $this->chargebackDate,
                'upload_id' => $this->uploadId,
            ]);
            return;
        }

        Log::info('FetchChargebackReasonJob: Starting', [
            'chargeback_date' => $this->chargebackDate,
            'upload_id' => $this->uploadId,
            'pending_count' => $pendingCount,
            'attempt' => $this->attempts(),
        ]);

        $startDate = Carbon::parse($this->chargebackDate)->subDays(3)->format('Y-m-d');
        $endDate = Carbon::parse($this->chargebackDate)->addDay()->format('Y-m-d');

        $results = $service->syncByDateRange($startDate, $endDate);

        $totalBackfilled = 0;
        foreach ($results as $stats) {
            $totalBackfilled += $stats['reason_backfilled'] ?? 0;
        }

        $remainingCount = $this->getPendingCount();

        Log::info('FetchChargebackReasonJob: Completed', [
            'chargeback_date' => $this->chargebackDate,
            'upload_id' => $this->uploadId,
            'backfilled' => $totalBackfilled,
            'remaining_without_code' => $remainingCount,
            'attempt' => $this->attempts(),
        ]);

        if ($remainingCount > 0 && $this->attempts() >= $this->tries) {
            Log::warning('FetchChargebackReasonJob: Exhausted retries with remaining chargebacks missing reason_code', [
                'chargeback_date' => $this->chargebackDate,
                'upload_id' => $this->uploadId,
                'remaining' => $remainingCount,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('FetchChargebackReasonJob: Failed', [
            'chargeback_date' => $this->chargebackDate,
            'upload_id' => $this->uploadId,
            'error' => $exception->getMessage(),
        ]);
    }

    private function getPendingCount(): int
    {
        $query = BillingAttempt::where('status', BillingAttempt::STATUS_CHARGEBACKED)
            ->whereNull('chargeback_reason_code');

        if ($this->uploadId) {
            $query->where('upload_id', $this->uploadId);
        } else {
            $query->where('chargebacked_at', '>=', Carbon::parse($this->chargebackDate)->startOfDay())
                ->where('chargebacked_at', '<=', Carbon::parse($this->chargebackDate)->endOfDay());
        }

        return $query->count();
    }
}
