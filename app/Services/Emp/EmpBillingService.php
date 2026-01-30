<?php

/**
 * Service for processing SEPA Direct Debit billing through emerchantpay.
 */

namespace App\Services\Emp;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\DebtorProfile;
use App\Models\EmpAccount;
use App\Models\Upload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

class EmpBillingService
{
    private EmpClient $defaultClient;
    private int $requestsPerSecond;
    private int $maxRetries;
    private int $retryDelayMs;

    public function __construct(EmpClient $client)
    {
        $this->defaultClient = $client;
        $this->requestsPerSecond = config('services.emp.rate_limit.requests_per_second', 50);
        $this->maxRetries = config('services.emp.rate_limit.max_retries', 3);
        $this->retryDelayMs = config('services.emp.rate_limit.retry_delay_ms', 1000);
    }

    /**
     * Get EmpClient for a debtor (uses upload's account if set).
     */
    private function getClientForDebtor(Debtor $debtor): EmpClient
    {
        $upload = $debtor->upload;
        
        if ($upload && $upload->emp_account_id) {
            $account = $upload->empAccount;
            if ($account) {
                return new EmpClient($account);
            }
        }
        
        return $this->defaultClient;
    }

    /**
     * Get EmpClient for an upload.
     */
    private function getClientForUpload(Upload $upload): EmpClient
    {
        if ($upload->emp_account_id) {
            $account = $upload->empAccount;
            if ($account) {
                return new EmpClient($account);
            }
        }
        
        return $this->defaultClient;
    }

    /**
     * Bill a single debtor.
     */
    public function billDebtor(
        Debtor $debtor,
        ?string $notificationUrl = null,
        ?float $amount = null,
        string $billingModel = DebtorProfile::MODEL_LEGACY,
        array $context = []
    ): BillingAttempt {
        $billableAmount = $amount ?? $debtor->amount;

        if (!$this->canBill($debtor, $billableAmount)) {
            throw new \InvalidArgumentException("Debtor {$debtor->id} cannot be billed");
        }

        // Get the appropriate client for this debtor's upload
        $client = $this->getClientForDebtor($debtor);

        $transactionId = $this->generateTransactionId($debtor);

        $payload = $this->buildRequestPayload($debtor, $transactionId, $notificationUrl, $billableAmount);

        $source = $context['source'] ?? 'system';
        $cycleAnchor = $context['cycle_anchor'] ?? now();

        $billingAttempt = BillingAttempt::create([
            'debtor_id' => $debtor->id,
            'debtor_profile_id' => $debtor->debtor_profile_id,
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
            'mid_reference' => $client->getTerminalToken(),
            'emp_account_id' => $client->getEmpAccountId(),
            'request_payload' => $payload,
        ]);

        $debtor->update(['status' => Debtor::STATUS_PENDING]);

        $response = $client->sddSale($billingAttempt->request_payload);

        $this->updateBillingAttempt($billingAttempt, $response);

        $this->updateDebtorStatus($debtor, $billingAttempt);

        return $billingAttempt;
    }

    /**
     * Bill multiple debtors in batch.
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

        // Use the account that was used for this billing attempt
        $client = $this->defaultClient;
        if ($billingAttempt->emp_account_id) {
            $account = EmpAccount::find($billingAttempt->emp_account_id);
            if ($account) {
                $client = new EmpClient($account);
            }
        }

        $response = $client->reconcile($billingAttempt->unique_id);

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

        $profile = $debtor->debtorProfile ?? $debtor->debtorProfile()->first();

        $billingModel = $profile?->billing_model ?? DebtorProfile::MODEL_LEGACY;

        if ($amount === null) {
            if ($profile && $billingModel !== DebtorProfile::MODEL_LEGACY) {
                $amount = $profile->billing_amount;
            } else {
                $amount = $debtor->amount;
            }
        }

        if ($amount < 1) {
            return false;
        }

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

        $hasPending = BillingAttempt::where('debtor_id', $debtor->id)
            ->where('status', BillingAttempt::STATUS_PENDING)
            ->exists();

        if ($hasPending) {
            return false;
        }

        if ($billingModel !== DebtorProfile::MODEL_LEGACY) {
            $profile = $debtor->debtorProfile ?? $debtor->debtorProfile()->first();

            if ($profile) {
                if ($profile->next_bill_at && now()->lessThan($profile->next_bill_at)) {
                    Log::info("Skipped {$debtor->id}: Cycle lock until {$profile->next_bill_at}");
                    return false;
                }

                $hasProfilePending = BillingAttempt::where('debtor_profile_id', $profile->id)
                    ->where('status', BillingAttempt::STATUS_PENDING)
                    ->where('id', '!=', $debtor->latestBillingAttempt?->id)
                    ->exists();

                if ($hasProfilePending) {
                    return false;
                }
            }
        }

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
            BillingAttempt::STATUS_APPROVED => Debtor::STATUS_APPROVED,
            BillingAttempt::STATUS_PENDING => Debtor::STATUS_PENDING,
            BillingAttempt::STATUS_CHARGEBACKED => Debtor::STATUS_CHARGEBACKED,
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
