<?php

/**
 * Service for processing SEPA Direct Debit billing through emerchantpay.
 */

namespace App\Services\Emp;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\DebtorProfile;
use App\Models\Upload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

class EmpBillingService
{
    private EmpClient $client;
    private int $requestsPerSecond;
    private int $maxRetries;
    private int $retryDelayMs;

    public function __construct(EmpClient $client)
    {
        $this->client = $client;
        $this->requestsPerSecond = config('services.emp.rate_limit.requests_per_second', 50);
        $this->maxRetries = config('services.emp.rate_limit.max_retries', 3);
        $this->retryDelayMs = config('services.emp.rate_limit.retry_delay_ms', 1000);
    }

    /**
     * Bill a single debtor.
     *
     * @param Debtor $debtor
     * @param string|null $notificationUrl
     * @param float|null $amount
     * @param string $billingModel
     * @param array $context Extra context (cycle_anchor, source)
     * @return BillingAttempt
     */
    public function billDebtor(
        Debtor $debtor,
        ?string $notificationUrl = null,
        ?float $amount = null,
        string $billingModel = DebtorProfile::MODEL_LEGACY,
        array $context = []
    ): BillingAttempt {
        $billableAmount = $amount ?? $debtor->amount;

        // Check if debtor can be billed (Skip amount check if we provided a specific override)
        if (!$this->canBill($debtor, $billableAmount)) {
            throw new \InvalidArgumentException("Debtor {$debtor->id} cannot be billed");
        }

        $transactionId = $this->generateTransactionId($debtor);

        // Build the request payload with the calculated amount
        $payload = $this->buildRequestPayload($debtor, $transactionId, $notificationUrl, $billableAmount);

        // Extract context with defaults
        $source = $context['source'] ?? 'system';
        $cycleAnchor = $context['cycle_anchor'] ?? now();

        // Create billing attempt record
        $billingAttempt = BillingAttempt::create([
            'debtor_id' => $debtor->id,
            'debtor_profile_id' => $debtor->debtor_profile_id, // Ensure link is preserved
            'upload_id' => $debtor->upload_id,
            'transaction_id' => $transactionId,
            'amount' => $billableAmount,
            'currency' => 'EUR',
            'status' => BillingAttempt::STATUS_PENDING,
            'billing_model' => $billingModel,
            'cycle_anchor' => $cycleAnchor,
            'source' => $source,
            'attempt_number' => $this->getNextAttemptNumber($debtor),
            'bic' => $debtor->bic,
            'request_payload' => $payload,
        ]);

        $debtor->update(['status' => Debtor::STATUS_PENDING]);

        $response = $this->client->sddSale($billingAttempt->request_payload);

        $this->updateBillingAttempt($billingAttempt, $response);

        $this->updateDebtorStatus($debtor, $billingAttempt);

        return $billingAttempt;
    }

    /**
     * Bill multiple debtors in batch.
     *
     * @param iterable $debtors
     * @param string|null $notificationUrl
     * @return array{success: int, failed: int, skipped: int, attempts: array}
     */
    public function billBatch(iterable $debtors, ?string $notificationUrl = null): array
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'attempts' => [],
        ];

        $requestCount = 0;
        $startTime = microtime(true);

        foreach ($debtors as $debtor) {
            $requestCount++;
            if ($requestCount >= $this->requestsPerSecond) {
                $elapsed = microtime(true) - $startTime;
                if ($elapsed < 1.0) {
                    usleep((int) ((1.0 - $elapsed) * 1000000));
                }
                $requestCount = 0;
                $startTime = microtime(true);
            }

            if (!$this->canBill($debtor)) {
                $results['skipped']++;
                continue;
            }

            try {
                $attempt = $this->billDebtor($debtor, $notificationUrl);
                $results['attempts'][] = $attempt;

                if ($attempt->isApproved() || $attempt->isPending()) {
                    $results['success']++;
                } else {
                    $results['failed']++;
                }
            } catch (\Exception $e) {
                Log::error('Batch billing failed for debtor', [
                    'debtor_id' => $debtor->id,
                    'error' => $e->getMessage(),
                ]);
                $results['failed']++;
            }
        }

        return $results;
    }

    public function billUpload(Upload $upload, ?string $notificationUrl = null): array
    {
        $debtors = Debtor::where('upload_id', $upload->id)
            ->where('validation_status', 'valid')
            ->where('status', Debtor::STATUS_UPLOADED)
            ->whereNotIn('id', function ($query) {
                $query->select('debtor_id')
                    ->from('billing_attempts')
                    ->whereIn('status', [
                        BillingAttempt::STATUS_PENDING,
                        BillingAttempt::STATUS_APPROVED,
                    ]);
            })
            ->cursor();

        return $this->billBatch($debtors, $notificationUrl);
    }

    public function retry(BillingAttempt $billingAttempt): BillingAttempt
    {
        if (!$billingAttempt->canRetry()) {
            throw new \InvalidArgumentException('Billing attempt cannot be retried');
        }

        $debtor = $billingAttempt->debtor;

        return $this->billDebtor($debtor, $billingAttempt->request_payload['notification_url'] ?? null);
    }

    public function reconcile(BillingAttempt $billingAttempt): array
    {
        if (!$billingAttempt->unique_id) {
            throw new \InvalidArgumentException('Billing attempt has no unique_id');
        }

        $response = $this->client->reconcile($billingAttempt->unique_id);

        if (isset($response['status'])) {
            $this->updateBillingAttempt($billingAttempt, $response);
        }

        return $response;
    }

    /**
     * Check if debtor can be billed.
     */
    public function canBill(Debtor $debtor, ?float $amount = null): bool
    {
        if ($debtor->validation_status !== 'valid') {
            return false;
        }

        if ($debtor->status !== Debtor::STATUS_UPLOADED) {
            return false;
        }

        if (empty($debtor->iban)) {
            return false;
        }

        // Resolve the profile
        $profile = $debtor->debtorProfile ?? $debtor->debtorProfile()->first();

        $billingModel = $profile?->billing_model ?? DebtorProfile::MODEL_LEGACY;

        if ($amount === null) {
            if ($profile && $billingModel !== DebtorProfile::MODEL_LEGACY) {
                // For Flywheel/Recovery, the Profile is the source of truth
                $amount = $profile->billing_amount;
            } else {
                // For Legacy (or no profile), the File is the source of truth
                $amount = $debtor->amount;
            }
        }

        if ($amount <= 0) {
            return false;
        }

        // If profile exists and is inactive (e.g., Chargebacked), stop IMMEDIATELY.
        // This applies to ALL models (Legacy, Flywheel, Recovery).
        if ($profile && !$profile->is_active) {
            return false;
        }

        if ($profile && $billingModel !== DebtorProfile::MODEL_LEGACY) {
            $lifetime = $profile->lifetime_charged_amount ?? 0;

            if ($lifetime >= DebtorProfile::MAX_AMOUNT_LIMIT) {
                Log::info("Skipped {$debtor->id}: Lifetime cap of " . DebtorProfile::MAX_AMOUNT_LIMIT . " reached.");
                return false;
            }
        }

        // Check for pending billing attempt
        $hasPending = BillingAttempt::where('debtor_id', $debtor->id)
            ->where('status', BillingAttempt::STATUS_PENDING)
            ->exists();

        if ($hasPending) {
            return false;
        }

        // If this is not legacy, we must respect the profile's time window
        if ($billingModel !== DebtorProfile::MODEL_LEGACY) {
            // Eager load profile if not already loaded
            $profile = $debtor->debtorProfile ?? $debtor->debtorProfile()->first();

            if ($profile) {
                // If next_bill_at is in the future, we cannot bill yet.
                if ($profile->next_bill_at && now()->lessThan($profile->next_bill_at)) {
                    Log::info("Skipped {$debtor->id}: Cycle lock until {$profile->next_bill_at}");
                    return false;
                }

                // This prevents two different CSV uploads from billing the same IBAN at the exact same time
                $hasProfilePending = BillingAttempt::where('debtor_profile_id', $profile->id)
                    ->where('status', BillingAttempt::STATUS_PENDING)
                    ->where('id', '!=', $debtor->latestBillingAttempt?->id)
                    ->exists();

                if ($hasProfilePending) {
                    return false;
                }
            }
        }

        // Check for already approved
        if ($billingModel === DebtorProfile::MODEL_LEGACY) {
            $hasApproved = BillingAttempt::where('debtor_id', $debtor->id)
                ->where('status', BillingAttempt::STATUS_APPROVED)
                ->exists();

            if ($hasApproved) {
                return false;
            }
        }

        return true;
    }

    private function generateTransactionId(Debtor $debtor): string
    {
        return sprintf(
            'tether_%d_%s_%s',
            $debtor->id,
            now()->format('Ymd'),
            Str::random(8)
        );
    }

    private function getNextAttemptNumber(Debtor $debtor): int
    {
        $lastAttempt = BillingAttempt::where('debtor_id', $debtor->id)
            ->orderBy('attempt_number', 'desc')
            ->first();

        return $lastAttempt ? $lastAttempt->attempt_number + 1 : 1;
    }

    /**
     * Build request payload for EMP.
     */
    private function buildRequestPayload(Debtor $debtor, string $transactionId, ?string $notificationUrl, float $amount): array
    {
        return [
            'transaction_id' => $transactionId,
            'amount' => $amount,
            'currency' => 'EUR',
            'iban' => $debtor->iban,
            'first_name' => $debtor->first_name,
            'last_name' => $debtor->last_name,
            'email' => $debtor->email ?? null,
            'usage' => "Debt recovery - {$debtor->id}",
            'notification_url' => $notificationUrl ?? config('app.url') . '/api/webhooks/emp',
        ];
    }

    private function updateBillingAttempt(BillingAttempt $billingAttempt, array $response): void
    {
        $status = $this->mapEmpStatus($response['status'] ?? 'error');

        $updateData = [
            'unique_id' => $response['unique_id'] ?? null,
            'status' => $status,
            'bic' => $response['bank_identifier_code'] ?? $billingAttempt->bic,
            'error_code' => $response['code'] ?? $response['error_code'] ?? null,
            'error_message' => $response['message'] ?? null,
            'technical_message' => $response['technical_message'] ?? null,
            'response_payload' => $response,
            'processed_at' => now(),
            'emp_created_at' => $billingAttempt->emp_created_at ?? (isset($response['timestamp']) ? Carbon::parse($response['timestamp']) : null),
            'meta' => array_merge($billingAttempt->meta ?? [], [
                'redirect_url' => $response['redirect_url'] ?? null,
                'descriptor' => $response['descriptor'] ?? null,
            ]),
        ];

        if ($status === BillingAttempt::STATUS_CHARGEBACKED && !$billingAttempt->chargebacked_at) {
            $updateData['chargebacked_at'] = now();
        }

        $billingAttempt->update($updateData);

        Log::info('Billing attempt processed', [
            'billing_attempt_id' => $billingAttempt->id,
            'debtor_id' => $billingAttempt->debtor_id,
            'status' => $status,
            'unique_id' => $billingAttempt->unique_id,
            'bic' => $billingAttempt->bic,
        ]);
    }

    private function updateDebtorStatus(Debtor $debtor, BillingAttempt $billingAttempt): void
    {
        $newStatus = match ($billingAttempt->status) {
            BillingAttempt::STATUS_APPROVED => Debtor::STATUS_RECOVERED,
            BillingAttempt::STATUS_PENDING => Debtor::STATUS_PENDING,
            BillingAttempt::STATUS_DECLINED,
            BillingAttempt::STATUS_ERROR => Debtor::STATUS_PENDING,
            default => $debtor->status,
        };

        if ($newStatus !== $debtor->status) {
            $debtor->update(['status' => $newStatus]);
        }
    }

    private function mapEmpStatus(?string $empStatus): string
    {
        return match ($empStatus) {
            'approved' => BillingAttempt::STATUS_APPROVED,
            'declined' => BillingAttempt::STATUS_DECLINED,
            'error' => BillingAttempt::STATUS_ERROR,
            'voided' => BillingAttempt::STATUS_VOIDED,
            'chargebacked', 'chargeback' => BillingAttempt::STATUS_CHARGEBACKED,
            'pending', 'pending_async' => BillingAttempt::STATUS_PENDING,
            default => BillingAttempt::STATUS_ERROR,
        };
    }
}
