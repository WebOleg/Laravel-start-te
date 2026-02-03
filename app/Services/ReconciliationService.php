<?php

/**
 * Service for reconciling billing attempts with EMP gateway.
 * Handles status synchronization and chargeback auto-blacklisting.
 */

namespace App\Services;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\Upload;
use App\Services\Emp\EmpBillingService;
use App\Services\BlacklistService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReconciliationService
{
    public const CHUNK_SIZE = 50;
    public const RATE_LIMIT_PER_SECOND = 20;

    public function __construct(
        private EmpBillingService $billingService,
        private BlacklistService $blacklistService
    ) {}

    /**
     * Reconcile single billing attempt
     */
    public function reconcileAttempt(BillingAttempt $attempt): array
    {
        if (!$attempt->canReconcile()) {
            return [
                'success' => false,
                'changed' => false,
                'reason' => $this->getCannotReconcileReason($attempt),
            ];
        }

        try {
            $previousStatus = $attempt->status;

            // billingService->reconcile() updates the attempt with mapped status
            $result = $this->billingService->reconcile($attempt);

            // Refresh to get the updated status from database (already mapped by EmpBillingService)
            $attempt->refresh();
            $newStatus = $attempt->status;
            $changed = $previousStatus !== $newStatus;

            // Handle chargeback auto-blacklist (status already updated by billingService)
            if ($newStatus === BillingAttempt::STATUS_CHARGEBACKED) {
                $this->handleChargeback($attempt, $result);
            }

            // Update debtor status if approved
            if ($newStatus === BillingAttempt::STATUS_APPROVED && $attempt->debtor) {
                $attempt->debtor->update(['status' => Debtor::STATUS_APPROVED]);
            }

            $attempt->markReconciled();

            Log::info('Reconciliation completed', [
                'billing_attempt_id' => $attempt->id,
                'previous_status' => $previousStatus,
                'new_status' => $newStatus,
                'changed' => $changed,
            ]);

            return [
                'success' => true,
                'changed' => $changed,
                'previous_status' => $previousStatus,
                'new_status' => $newStatus,
                'emp_response' => $result,
            ];
        } catch (\Exception $e) {
            Log::error('Reconciliation failed', [
                'billing_attempt_id' => $attempt->id,
                'error' => $e->getMessage(),
            ]);

            $attempt->markReconciled();

            return [
                'success' => false,
                'changed' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Reconcile all eligible attempts for an upload
     */
    public function reconcileUpload(Upload $upload): array
    {
        $attempts = BillingAttempt::where('upload_id', $upload->id)
            ->needsReconciliation()
            ->get();

        return $this->reconcileMany($attempts);
    }

    /**
     * Reconcile multiple attempts
     */
    public function reconcileMany(Collection $attempts): array
    {
        $results = [
            'total' => $attempts->count(),
            'processed' => 0,
            'changed' => 0,
            'failed' => 0,
            'details' => [],
        ];

        foreach ($attempts as $attempt) {
            $result = $this->reconcileAttempt($attempt);
            $results['processed']++;

            if ($result['success'] && $result['changed']) {
                $results['changed']++;
            } elseif (!$result['success']) {
                $results['failed']++;
            }

            $results['details'][] = [
                'id' => $attempt->id,
                'result' => $result,
            ];

            // Rate limiting
            usleep((int) (1000000 / self::RATE_LIMIT_PER_SECOND));
        }

        return $results;
    }

    /**
     * Get eligible attempts for reconciliation
     */
    public function getEligibleAttempts(int $limit = 1000): Collection
    {
        return BillingAttempt::query()->needsReconciliation()
            ->orderBy('created_at', 'asc')
            ->orderByRaw('CASE WHEN last_reconciled_at IS NULL THEN 0 ELSE 1 END')
            ->limit($limit)
            ->get();
    }

    /**
     * Get eligible attempts for upload
     */
    public function getEligibleForUpload(Upload $upload): Collection
    {
        return BillingAttempt::where('upload_id', $upload->id)
            ->needsReconciliation()
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Get reconciliation statistics
     */
    public function getStats(): array
    {
        $pendingTotal = BillingAttempt::query()->pending()->count();
        $pendingStale = BillingAttempt::query()->stale(48)->count();
        $neverReconciled = BillingAttempt::query()->pending()
            ->whereNull('last_reconciled_at')
            ->where('created_at', '<', now()->subHours(BillingAttempt::RECONCILIATION_MIN_AGE_HOURS))
            ->count();
        $maxedOut = BillingAttempt::query()->pending()
            ->where('reconciliation_attempts', '>=', BillingAttempt::RECONCILIATION_MAX_ATTEMPTS)
            ->count();

        return [
            'pending_total' => $pendingTotal,
            'pending_stale' => $pendingStale,
            'never_reconciled' => $neverReconciled,
            'maxed_out_attempts' => $maxedOut,
            'eligible' => BillingAttempt::query()->needsReconciliation()->count(),
        ];
    }

    /**
     * Get stats for specific upload
     */
    public function getUploadStats(Upload $upload): array
    {
        $query = BillingAttempt::where('upload_id', $upload->id);

        return [
            'total' => (clone $query)->count(),
            'pending' => (clone $query)->pending()->count(),
            'eligible' => (clone $query)->needsReconciliation()->count(),
            'reconciled_today' => (clone $query)
                ->whereDate('last_reconciled_at', today())
                ->count(),
        ];
    }

    /**
     * Handle chargeback - auto-blacklist
     */
    private function handleChargeback(BillingAttempt $attempt, array $result): void
    {
        if (!$attempt->debtor) {
            return;
        }

        $errorCode = $result['error_code'] ?? $result['code'] ?? $result['reason_code'] ?? 'unknown';
        $blacklistCodes = config('tether.chargeback.blacklist_codes', ['AC04', 'AC06', 'AG01', 'MD01']);

        if (in_array($errorCode, $blacklistCodes)) {
            $this->blacklistService->addDebtor(
                $attempt->debtor,
                'chargeback',
                "Auto-blacklisted via reconciliation: {$errorCode}"
            );

            Log::info('Debtor auto-blacklisted via reconciliation', [
                'debtor_id' => $attempt->debtor->id,
                'error_code' => $errorCode,
            ]);
        }
    }

    /**
     * Get reason why attempt cannot be reconciled
     */
    private function getCannotReconcileReason(BillingAttempt $attempt): string
    {
        if (!$attempt->isPending()) {
            return "Status is {$attempt->status}, not pending";
        }

        if (empty($attempt->unique_id)) {
            return 'No unique_id from gateway';
        }

        if ($attempt->reconciliation_attempts >= BillingAttempt::RECONCILIATION_MAX_ATTEMPTS) {
            return "Max reconciliation attempts reached ({$attempt->reconciliation_attempts})";
        }

        return 'Unknown reason';
    }
}
