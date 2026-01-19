<?php

namespace App\Jobs;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\DebtorProfile;
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
use Illuminate\Support\Facades\DB;

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
        public ?int $uploadId,
        public int $chunkIndex,
        public string $billingModel = DebtorProfile::MODEL_LEGACY,
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
                'upload_id' => $this->uploadId ?? 'recurring',
                'chunk' => $this->chunkIndex,
                'model' => $this->billingModel,
            ]);
            $this->release(60);
            return;
        }

        Log::info('ProcessBillingChunkJob started', [
            'upload_id' => $this->uploadId ?? 'recurring',
            'chunk' => $this->chunkIndex,
            'debtors' => count($this->debtorIds),
            'model' => $this->billingModel,
        ]);

        $debtors = Debtor::with('debtorProfile')
            ->whereIn('id', $this->debtorIds)
            ->whereDoesntHave('debtorProfile', function ($query) {
                $query->where('is_active', false);
            })
            ->get();

        $results = [
            'success' => 0,
            'failed' => 0,
            'skipped' => 0
        ];

        $consecutiveFailures = 0;

        foreach ($debtors as $debtor) {
            // Check if batch cancelled
            if ($this->batch()?->cancelled()) {
                break;
            }

            // Rate limiting
            $this->rateLimit();

            if (!$billingService->canBill($debtor, $debtor->amount)) {
                $results['skipped']++;
                continue;
            }

            try {
                // Use helper to handle Profile logic + Billing Service call
                $attempt = $this->processDebtor($debtor, $billingService);

                if ($attempt && ($attempt->isApproved() || $attempt->isPending())) {
                    $results['success']++;
                    $consecutiveFailures = 0;
                } elseif ($attempt === null) {
                    // processDebtor returns null if skipped due to exclusivity logic
                    $results['skipped']++;
                } else {
                    $results['failed']++;
                    $consecutiveFailures++;
                }

            } catch (Throwable $e) {
                Log::error('ProcessBillingChunkJob: billing failed', [
                    'debtor_id' => $debtor->id,
                    'error' => $e->getMessage(),
                ]);
                $results['failed']++;
                $consecutiveFailures++;

                // Check for circuit breaker
                if ($consecutiveFailures >= self::CIRCUIT_BREAKER_THRESHOLD) {
                    $this->openCircuit();
                    Log::error('ProcessBillingChunkJob: circuit breaker triggered', [
                        'upload_id' => $this->uploadId ?? 'recurring',
                        'consecutive_failures' => $consecutiveFailures,
                    ]);
                    $this->release(self::CIRCUIT_BREAKER_TIMEOUT);
                    return;
                }
            }
        }

        Log::info('ProcessBillingChunkJob completed', [
            'upload_id' => $this->uploadId ?? 'recurring',
            'chunk' => $this->chunkIndex,
            'results' => $results,
        ]);
    }

    /**
     * Process individual debtor:
     * 1. Create/Retrieve Profile
     * 2. Check Exclusivity
     * 3. Apply Split Test (Amount/Date)
     * 4. Call Billing Service
     */
    private function processDebtor(Debtor $debtor, EmpBillingService $billingService): ?BillingAttempt
    {
        return DB::transaction(function () use ($debtor, $billingService) {

            // 1. Find by relationship
            $profile = $debtor->debtorProfile;

            // 2. FALLBACK: If not linked yet, look by hash or create new
            // This handles the case where we have a repeat debtor in a new file that isn't linked yet.
            if (!$profile) {
                $profile = DebtorProfile::firstOrNew([
                    'iban_hash' => $debtor->iban_hash
                ]);
            }

            $targetModel = ($this->billingModel === 'all')
                ? ($profile->billing_model ?? DebtorProfile::MODEL_LEGACY)
                : $this->billingModel;

            // 2. EXCLUSIVITY CHECK
            if ($this->billingModel !== 'all' &&
                $profile->exists &&
                $profile->billing_model !== $targetModel &&
                $profile->billing_model !== DebtorProfile::MODEL_LEGACY &&
                $targetModel !== DebtorProfile::MODEL_LEGACY)
            {
                Log::warning("Skipping debtor {$debtor->id}: Conflict {$profile->billing_model} vs {$targetModel}");
                return null;
            }

            // If the profile exists and the next bill date is in the future, DO NOT BILL.
            if ($profile->exists &&
                $targetModel !== DebtorProfile::MODEL_LEGACY &&
                $profile->next_bill_at &&
                now()->lessThan($profile->next_bill_at))
            {
                Log::info("Skipping debtor {$debtor->id}: Cycle lock until {$profile->next_bill_at}");
                return null;
            }

            // 4. CONFIGURE PROFILE
            if (!$profile->exists || $profile->billing_model === DebtorProfile::MODEL_LEGACY || $profile->billing_model === $targetModel) {

                // If we are establishing a new non-legacy relationship
                if ($targetModel !== DebtorProfile::MODEL_LEGACY && $profile->billing_model !== $targetModel) {
                    $profile->billing_model = $targetModel;
                }

                // Ensure Amount is set if missing (for non-legacy)
                if ($targetModel !== DebtorProfile::MODEL_LEGACY && !$profile->billing_amount) {
                    $profile->billing_amount = $debtor->amount;
                }

                $profile->currency = $debtor->currency ?? 'EUR';
                if (!$profile->iban_masked) $profile->iban_masked = $debtor->iban;

                $profile->save();

                if ($debtor->debtor_profile_id !== $profile->id) {
                    $debtor->debtorProfile()->associate($profile);
                    $debtor->save();
                }
            }

            // 6. DETERMINE AMOUNT
            $amountToBill = ($targetModel === DebtorProfile::MODEL_LEGACY)
                ? $debtor->amount
                : $profile->billing_amount;

            // 7. PREPARE CONTEXT
            // Determine source based on uploadId presence
            $contextSource = $this->uploadId ? 'batch_upload' : 'recurring_billing';

            $context = [
                'source' => $contextSource,
                'upload_id' => $this->uploadId,
                'cycle_anchor' => now()
            ];

            // 8. EXECUTE
            $attempt = $billingService->billDebtor(
                $debtor,
                $this->notificationUrl,
                $amountToBill,
                $targetModel,
                $context
            );

            // UPDATE CYCLE
            // Lock the profile if the attempt was successful/pending
            if ($this->billingModel !== DebtorProfile::MODEL_LEGACY) {
                if ($attempt->isApproved() || $attempt->isPending()) {
                    if ($attempt->isApproved()) {
                        $profile->last_success_at = now();
                        $profile->last_billed_at = now();
                    }

                    // Lock them for the cycle duration (90 days / 6 months)
                    $profile->next_bill_at = DebtorProfile::calculateNextBillDate($this->billingModel);
                    $profile->save();
                }
            }

            return $attempt;
        });
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
            'upload_id' => $this->uploadId ?? 'recurring',
            'chunk' => $this->chunkIndex,
            'error' => $exception->getMessage(),
        ]);
    }
}
