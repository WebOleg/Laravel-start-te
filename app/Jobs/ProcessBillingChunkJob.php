<?php

/**
 * Process billing for a chunk of debtors.
 * Handles profile resolution, exclusivity checks, and billing execution.
 */

namespace App\Jobs;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\DebtorProfile;
use App\Services\Emp\EmpBillingService;
use App\Traits\WithLogContext;
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
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WithLogContext;

    public int $tries = 3;
    public int $timeout = 120;
    public array $backoff = [10, 30, 60];

    private const RATE_LIMIT_KEY = 'emp_billing_rate_limit';
    private const RATE_LIMIT_PER_SECOND = 50;
    private const CIRCUIT_BREAKER_KEY = 'emp_circuit_breaker';
    private const CIRCUIT_BREAKER_THRESHOLD = 10;
    private const CIRCUIT_BREAKER_TIMEOUT = 300;

    public function __construct(
        public array $debtorIds,
        public ?int $uploadId,
        public int $chunkIndex,
        public string $billingModel = DebtorProfile::MODEL_LEGACY,
        public ?string $notificationUrl = null
    ) {
        $this->onQueue('billing');
    }

    private function shouldStop(): bool
    {
        if (!$this->uploadId) {
            return false;
        }
        return Cache::has("billing_sync_stop_{$this->uploadId}");
    }

    private function terminateBatch(): void
    {
        Log::info("Sync Terminated by User", [
            'upload_id' => $this->uploadId,
            'timestamp' => now()->toIso8601String(),
        ]);

        if ($this->batch()) {
            $this->batch()->cancel();
        }
    }

    public function handle(EmpBillingService $billingService): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        if ($this->isCircuitOpen()) {
            Log::warning('ProcessBillingChunkJob: circuit breaker open, releasing job', [
                'upload_id' => $this->uploadId ?? 'recurring',
                'chunk' => $this->chunkIndex,
                'model' => $this->billingModel,
            ]);
            $this->release(60);
            return;
        }

        if ($this->shouldStop()) {
            $this->terminateBatch();
            return;
        }

        Log::info('ProcessBillingChunkJob started', [
            'upload_id' => $this->uploadId ?? 'recurring',
            'chunk' => $this->chunkIndex,
            'debtors' => count($this->debtorIds),
            'model' => $this->billingModel,
        ]);

        $debtors = Debtor::with(['debtorProfile', 'upload'])
            ->whereIn('id', $this->debtorIds)
            ->whereDoesntHave('debtorProfile', function ($query) {
                $query->where('is_active', false);
            })
            ->get();

        $results = [
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        $consecutiveFailures = 0;

        foreach ($debtors as $debtor) {
            if ($this->batch()?->cancelled()) {
                break;
            }

            $this->rateLimit();

            if (!$billingService->canBill($debtor, $debtor->amount)) {
                $results['skipped']++;
                continue;
            }

            try {
                $attempt = $this->processDebtor($debtor, $billingService);

                if ($attempt && ($attempt->isApproved() || $attempt->isPending())) {
                    $results['success']++;
                    $consecutiveFailures = 0;
                } elseif ($attempt === null) {
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
     * 1. Create/Retrieve Profile (scoped by tether_instance_id)
     * 2. Check Exclusivity
     * 3. Apply Split Test (Amount/Date)
     * 4. Call Billing Service
     */
    private function processDebtor(Debtor $debtor, EmpBillingService $billingService): ?BillingAttempt
    {
        return DB::transaction(function () use ($debtor, $billingService) {

            $profile = $debtor->debtorProfile;

            if (!$profile) {
                $lookupCriteria = ['iban_hash' => $debtor->iban_hash];

                if ($debtor->tether_instance_id) {
                    $lookupCriteria['tether_instance_id'] = $debtor->tether_instance_id;
                }

                $profile = DebtorProfile::firstOrNew($lookupCriteria);
            }

            $targetModel = ($this->billingModel === 'all')
                ? ($profile->billing_model ?? DebtorProfile::MODEL_LEGACY)
                : $this->billingModel;

            if ($this->billingModel !== 'all' &&
                $profile->exists &&
                $profile->billing_model !== $targetModel &&
                $profile->billing_model !== DebtorProfile::MODEL_LEGACY &&
                $targetModel !== DebtorProfile::MODEL_LEGACY)
            {
                Log::warning("Skipping debtor {$debtor->id}: Conflict {$profile->billing_model} vs {$targetModel}");
                return null;
            }

            if ($profile->exists &&
                $targetModel !== DebtorProfile::MODEL_LEGACY &&
                $profile->next_bill_at &&
                now()->lessThan($profile->next_bill_at))
            {
                Log::info("Skipping debtor {$debtor->id}: Cycle lock until {$profile->next_bill_at}");
                return null;
            }

            if (!$profile->exists || $profile->billing_model === DebtorProfile::MODEL_LEGACY || $profile->billing_model === $targetModel) {

                if ($targetModel !== DebtorProfile::MODEL_LEGACY && $profile->billing_model !== $targetModel) {
                    $profile->billing_model = $targetModel;
                }

                if ($targetModel !== DebtorProfile::MODEL_LEGACY && !$profile->billing_amount) {
                    $profile->billing_amount = $debtor->amount;
                }

                $profile->currency = $debtor->currency ?? 'EUR';
                if (!$profile->iban_masked) {
                    $profile->iban_masked = $debtor->iban;
                }

                if ($debtor->tether_instance_id && !$profile->tether_instance_id) {
                    $profile->tether_instance_id = $debtor->tether_instance_id;
                }

                $profile->save();

                if ($debtor->debtor_profile_id !== $profile->id) {
                    $debtor->debtorProfile()->associate($profile);
                    $debtor->save();
                }
            }

            $amountToBill = ($targetModel === DebtorProfile::MODEL_LEGACY)
                ? $debtor->amount
                : $profile->billing_amount;

            $contextSource = $this->uploadId ? 'batch_upload' : 'recurring_billing';

            $context = [
                'source' => $contextSource,
                'upload_id' => $this->uploadId,
                'cycle_anchor' => now(),
            ];

            $attempt = $billingService->billDebtor(
                $debtor,
                $this->notificationUrl,
                $amountToBill,
                $targetModel,
                $context
            );

            if ($this->billingModel !== DebtorProfile::MODEL_LEGACY) {
                if ($attempt->isApproved() || $attempt->isPending()) {
                    if ($attempt->isApproved()) {
                        $profile->last_success_at = now();
                        $profile->last_billed_at = now();
                    }

                    $profile->next_bill_at = DebtorProfile::calculateNextBillDate($this->billingModel);
                    $profile->save();
                }
            }

            return $attempt;
        });
    }

    private function rateLimit(): void
    {
        $key = self::RATE_LIMIT_KEY . '_' . now()->format('YmdHis');
        $count = Cache::get($key, 0);

        if ($count >= self::RATE_LIMIT_PER_SECOND) {
            usleep(100000);
        }

        Cache::put($key, $count + 1, 2);
    }

    private function isCircuitOpen(): bool
    {
        return Cache::has(self::CIRCUIT_BREAKER_KEY);
    }

    private function openCircuit(): void
    {
        Cache::put(self::CIRCUIT_BREAKER_KEY, true, self::CIRCUIT_BREAKER_TIMEOUT);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('ProcessBillingChunkJob failed', [
            'upload_id' => $this->uploadId ?? 'recurring',
            'chunk' => $this->chunkIndex,
            'error' => $exception->getMessage(),
        ]);
    }
}
