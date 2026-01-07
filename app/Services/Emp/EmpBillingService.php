<?php

/**
 * Service for processing SEPA Direct Debit billing through emerchantpay.
 * Handles single and batch billing with rate limiting and retry logic.
 */

namespace App\Services\Emp;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\Upload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
     * @return BillingAttempt
     */
    public function billDebtor(Debtor $debtor, ?string $notificationUrl = null): BillingAttempt
    {
        // Check if debtor can be billed
        if (!$this->canBill($debtor)) {
            throw new \InvalidArgumentException("Debtor {$debtor->id} cannot be billed");
        }

        // Generate unique transaction ID
        $transactionId = $this->generateTransactionId($debtor);

        // Create billing attempt record
        $billingAttempt = BillingAttempt::create([
            'debtor_id' => $debtor->id,
            'upload_id' => $debtor->upload_id,
            'transaction_id' => $transactionId,
            'amount' => $debtor->amount,
            'currency' => 'EUR',
            'status' => BillingAttempt::STATUS_PENDING,
            'attempt_number' => $this->getNextAttemptNumber($debtor),
            'request_payload' => $this->buildRequestPayload($debtor, $transactionId, $notificationUrl),
        ]);

        // Send to EMP
        $response = $this->client->sddSale($billingAttempt->request_payload);

        // Update billing attempt with response
        $this->updateBillingAttempt($billingAttempt, $response);

        // Update debtor status
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
            // Rate limiting
            $requestCount++;
            if ($requestCount >= $this->requestsPerSecond) {
                $elapsed = microtime(true) - $startTime;
                if ($elapsed < 1.0) {
                    usleep((int) ((1.0 - $elapsed) * 1000000));
                }
                $requestCount = 0;
                $startTime = microtime(true);
            }

            // Skip if cannot bill
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

    /**
     * Bill all ready debtors from an upload.
     *
     * @param Upload $upload
     * @param string|null $notificationUrl
     * @return array
     */
    public function billUpload(Upload $upload, ?string $notificationUrl = null): array
    {
        $debtors = Debtor::where('upload_id', $upload->id)
            ->where('validation_status', 'valid')
            ->where('status', Debtor::STATUS_PENDING)
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

    /**
     * Retry failed billing attempt.
     *
     * @param BillingAttempt $billingAttempt
     * @return BillingAttempt
     */
    public function retry(BillingAttempt $billingAttempt): BillingAttempt
    {
        if (!$billingAttempt->canRetry()) {
            throw new \InvalidArgumentException('Billing attempt cannot be retried');
        }

        $debtor = $billingAttempt->debtor;

        return $this->billDebtor($debtor, $billingAttempt->request_payload['notification_url'] ?? null);
    }

    /**
     * Reconcile a billing attempt with EMP.
     *
     * @param BillingAttempt $billingAttempt
     * @return array
     */
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
    public function canBill(Debtor $debtor): bool
    {
        // Must be valid
        if ($debtor->validation_status !== 'valid') {
            return false;
        }

        // Must be pending or ready
        if (!in_array($debtor->status, [Debtor::STATUS_PENDING, 'ready_for_sync'])) {
            return false;
        }

        // Must have IBAN
        if (empty($debtor->iban)) {
            return false;
        }

        // Must have amount
        if ($debtor->amount < 1) {
            return false;
        }

        // Check for pending billing attempt
        $hasPending = BillingAttempt::where('debtor_id', $debtor->id)
            ->where('status', BillingAttempt::STATUS_PENDING)
            ->exists();

        if ($hasPending) {
            return false;
        }

        // Check for already approved
        $hasApproved = BillingAttempt::where('debtor_id', $debtor->id)
            ->where('status', BillingAttempt::STATUS_APPROVED)
            ->exists();

        if ($hasApproved) {
            return false;
        }

        return true;
    }

    /**
     * Generate unique transaction ID.
     */
    private function generateTransactionId(Debtor $debtor): string
    {
        return sprintf(
            'tether_%d_%s_%s',
            $debtor->id,
            now()->format('Ymd'),
            Str::random(8)
        );
    }

    /**
     * Get next attempt number for debtor.
     */
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
    private function buildRequestPayload(Debtor $debtor, string $transactionId, ?string $notificationUrl): array
    {
        return [
            'transaction_id' => $transactionId,
            'amount' => $debtor->amount,
            'currency' => 'EUR',
            'iban' => $debtor->iban,
            'bic' => $debtor->bic ?? null,
            'first_name' => $debtor->first_name,
            'last_name' => $debtor->last_name,
            'email' => $debtor->email ?? null,
            'usage' => "Debt recovery - {$debtor->id}",
            'notification_url' => $notificationUrl ?? config('app.url') . '/api/webhooks/emp',
        ];
    }

    /**
     * Update billing attempt with EMP response.
     */
    private function updateBillingAttempt(BillingAttempt $billingAttempt, array $response): void
    {
        $status = $this->mapEmpStatus($response['status'] ?? 'error');

        $billingAttempt->update([
            'unique_id' => $response['unique_id'] ?? null,
            'status' => $status,
            'error_code' => $response['code'] ?? $response['error_code'] ?? null,
            'error_message' => $response['message'] ?? null,
            'technical_message' => $response['technical_message'] ?? null,
            'response_payload' => $response,
            'processed_at' => now(),
            'emp_created_at' => isset($response['timestamp']) ? \Carbon\Carbon::parse($response['timestamp']) : null,
            'meta' => array_merge($billingAttempt->meta ?? [], [
                'redirect_url' => $response['redirect_url'] ?? null,
                'descriptor' => $response['descriptor'] ?? null,
            ]),
        ]);

        Log::info('Billing attempt processed', [
            'billing_attempt_id' => $billingAttempt->id,
            'debtor_id' => $billingAttempt->debtor_id,
            'status' => $status,
            'unique_id' => $billingAttempt->unique_id,
        ]);
    }

    /**
     * Update debtor status based on billing result.
     */
    private function updateDebtorStatus(Debtor $debtor, BillingAttempt $billingAttempt): void
    {
        $newStatus = match ($billingAttempt->status) {
            BillingAttempt::STATUS_APPROVED => Debtor::STATUS_RECOVERED,
            BillingAttempt::STATUS_PENDING => Debtor::STATUS_PENDING,
            BillingAttempt::STATUS_DECLINED,
            BillingAttempt::STATUS_ERROR => Debtor::STATUS_PENDING, // Can retry
            default => $debtor->status,
        };

        if ($newStatus !== $debtor->status) {
            $debtor->update(['status' => $newStatus]);
        }
    }

    /**
     * Map EMP status to BillingAttempt status.
     */
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
