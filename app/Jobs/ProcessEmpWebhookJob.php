<?php

namespace App\Jobs;

use App\Models\BillingAttempt;
use App\Models\DebtorProfile;
use App\Services\BlacklistService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessEmpWebhookJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable, Dispatchable, InteractsWithQueue, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    private ?array $blacklistCodesFlipped = null;

    public function __construct(
        private array $webhookData,
        private string $processingType,
        private string $receivedAt
    ) {
        $this->onQueue('webhooks');
    }

    public function uniqueId(): string|null
    {
        $uniqueId = $this->webhookData['unique_id'] ?? null;

        if ($uniqueId === null) {
            Log::warning('EMP webhook missing unique_id - uniqueness cannot be enforced', [
                'processing_type' => $this->processingType,
            ]);
            return null;
        }

        return "webhook_{$this->processingType}_{$uniqueId}";
    }

    public function uniqueFor(): int
    {
        return 3600;
    }

    public function handle(BlacklistService $blacklistService): void
    {
        Log::info('ProcessEmpWebhookJob started', [
            'processing_type' => $this->processingType,
            'unique_id' => $this->webhookData['unique_id'] ?? null,
            'event' => $this->webhookData['event'] ?? null,
            'status' => $this->webhookData['status'] ?? null,
        ]);

        try {
            match ($this->processingType) {
                'chargeback' => $this->handleChargeback($blacklistService),
                'retrieval_request' => $this->handleRetrievalRequest(),
                'sdd_status_update' => $this->handleSddStatusUpdate(),
                default => $this->handleUnknown(),
            };
        } catch (\Exception $e) {
            Log::error('ProcessEmpWebhookJob failed', [
                'processing_type' => $this->processingType,
                'error' => $e->getMessage(),
                'data' => $this->webhookData,
            ]);
            throw $e;
        }
    }

    /**
     * Handle chargeback event.
     *
     * EMP sends: event=chargeback, unique_id=original_tx_unique_id, status=chargebacked
     * The unique_id refers to the ORIGINAL transaction that was chargebacked.
     */
    private function handleChargeback(BlacklistService $blacklistService): void
    {
        // For chargebacks, unique_id is the original transaction's unique_id
        $originalUniqueId = $this->webhookData['unique_id'] ?? null;

        if (!$originalUniqueId) {
            Log::error('Chargeback missing unique_id', $this->webhookData);
            return;
        }

        $billingAttempt = BillingAttempt::with('debtor')
            ->where('unique_id', $originalUniqueId)
            ->first();

        if (!$billingAttempt) {
            Log::warning('Chargeback for unknown transaction', ['unique_id' => $originalUniqueId]);
            return;
        }

        if ($billingAttempt->status === BillingAttempt::STATUS_CHARGEBACKED) {
            Log::info('Chargeback already processed', [
                'billing_attempt_id' => $billingAttempt->id,
                'unique_id' => $originalUniqueId,
            ]);
            return;
        }

        $errorCode = $this->webhookData['reason_code']
            ?? $this->webhookData['rc_code']
            ?? $this->webhookData['error_code']
            ?? null;

        $chargebackMeta = [
            'event' => $this->webhookData['event'] ?? 'chargeback',
            'arn' => $this->webhookData['arn'] ?? null,
            'amount' => $this->webhookData['amount'] ?? null,
            'currency' => $this->webhookData['currency'] ?? null,
            'reason' => $this->webhookData['reason']
                ?? $this->webhookData['rc_description']
                ?? $this->webhookData['reason_description']
                ?? null,
            'reason_code' => $errorCode,
            'post_date' => $this->webhookData['post_date'] ?? null,
            'received_at' => $this->receivedAt,
        ];

        $currentMeta = $billingAttempt->meta ?? [];
        $currentMeta['chargeback'] = $chargebackMeta;

        $billingAttempt->update([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'error_code' => $errorCode,
            'error_message' => $chargebackMeta['reason'],
            'meta' => $currentMeta,
        ]);

        $blacklisted = false;
        if ($errorCode && $this->shouldBlacklistCode($errorCode)) {
            $debtor = $billingAttempt->debtor;
            if ($debtor && $debtor->iban) {
                $blacklistService->addDebtor(
                    $debtor,
                    'chargeback',
                    "Auto-blacklisted: {$errorCode}"
                );
                $blacklisted = true;
                Log::info('Debtor auto-blacklisted due to chargeback', [
                    'debtor_id' => $debtor->id,
                    'iban' => $debtor->iban,
                    'name' => $debtor->first_name . ' ' . $debtor->last_name,
                    'error_code' => $errorCode,
                ]);
            }
        }

        // PROFILE DEACTIVATION
        // If a chargeback occurs, we must stop the Flywheel/Recovery cycle immediately.
        $this->deactivateProfile($billingAttempt, $chargebackMeta['amount'] ?? null);

        Log::info('Chargeback processed', [
            'billing_attempt_id' => $billingAttempt->id,
            'debtor_id' => $billingAttempt->debtor_id,
            'unique_id' => $originalUniqueId,
            'error_code' => $errorCode,
            'arn' => $chargebackMeta['arn'],
            'blacklisted' => $blacklisted,
        ]);
    }

    /**
     * Handle retrieval request event.
     *
     * Retrieval requests are pre-chargeback inquiries from issuers.
     * We log them but don't change transaction status.
     */
    private function handleRetrievalRequest(): void
    {
        $uniqueId = $this->webhookData['unique_id'] ?? null;

        if (!$uniqueId) {
            Log::warning('Retrieval request missing unique_id', $this->webhookData);
            return;
        }

        $billingAttempt = BillingAttempt::where('unique_id', $uniqueId)->first();

        if (!$billingAttempt) {
            Log::info('Retrieval request for unknown transaction', ['unique_id' => $uniqueId]);
            return;
        }

        // Store retrieval request info in meta
        $currentMeta = $billingAttempt->meta ?? [];
        $currentMeta['retrieval_requests'] = $currentMeta['retrieval_requests'] ?? [];
        $currentMeta['retrieval_requests'][] = [
            'arn' => $this->webhookData['arn'] ?? null,
            'reason_code' => $this->webhookData['reason_code'] ?? null,
            'reason_description' => $this->webhookData['reason_description'] ?? null,
            'post_date' => $this->webhookData['post_date'] ?? null,
            'received_at' => $this->receivedAt,
        ];

        $billingAttempt->update(['meta' => $currentMeta]);

        Log::info('Retrieval request logged', [
            'billing_attempt_id' => $billingAttempt->id,
            'unique_id' => $uniqueId,
            'arn' => $this->webhookData['arn'] ?? null,
        ]);
    }

    /**
     * Handle SDD transaction status update.
     */
    private function handleSddStatusUpdate(): void
    {
        $uniqueId = $this->webhookData['unique_id'] ?? null;
        $status = $this->webhookData['status'] ?? null;

        if (!$uniqueId) {
            Log::warning('SDD status update missing unique_id', $this->webhookData);
            return;
        }

        $billingAttempt = BillingAttempt::with('debtorProfile')
                                        ->where('unique_id', $uniqueId)
                                        ->first();

        if (!$billingAttempt) {
            Log::info('SDD status update for unknown transaction', ['unique_id' => $uniqueId]);
            return;
        }

        $mappedStatus = $this->mapEmpStatus($status);

        if ($mappedStatus && $billingAttempt->status !== $mappedStatus) {
            $oldStatus = $billingAttempt->status;
            $billingAttempt->update(['status' => $mappedStatus]);

            if ($mappedStatus === BillingAttempt::STATUS_APPROVED) {
                $this->handleSuccess($billingAttempt);
            }

            Log::info('SDD transaction status updated', [
                'billing_attempt_id' => $billingAttempt->id,
                'old_status' => $oldStatus,
                'new_status' => $mappedStatus,
                'emp_status' => $status,
            ]);
        } else {
            Log::debug('SDD status unchanged or no valid status', [
                'billing_attempt_id' => $billingAttempt->id,
                'current_status' => $billingAttempt->status,
                'emp_status' => $status,
            ]);
        }
    }

    /**
     * Update Profile on Successful Payment
     *
     * @param BillingAttempt $attempt
     * @return void
     */
    private function handleSuccess(BillingAttempt $attempt): void
    {
        // 1. Resolve Profile
        $profile = $attempt->debtorProfile;

        // Fallback: try via debtor relationship if not directly linked
        if (!$profile) {
            $attempt->load('debtor.debtorProfile');
            $profile = $attempt->debtor?->debtorProfile;
        }

        if ($profile) {
            // We use the amount from the attempt. Ensure it's not null.
            if ($attempt->amount > 0) {
                $profile->addLifetimeRevenue((float) $attempt->amount);
            }

            // 2. Determine Model
            // Use the model snapshot from the attempt, or fall back to profile's current model
            $modelUsed = $attempt->billing_model ?? $profile->billing_model;

            // 3. Update Cycle Dates (Flywheel/Recovery only)
            if ($modelUsed !== DebtorProfile::MODEL_LEGACY) {

                $profile->last_success_at = now();
                $profile->last_billed_at = now();

                // Pushes the next bill date 90 days or 6 months into the future
                // This prevents the user from being picked up by 'canBill' until then.
                $profile->next_bill_at = DebtorProfile::calculateNextBillDate($modelUsed);

                $profile->save();

                Log::info("Cycle updated for Profile {$profile->id}", [
                    'model' => $modelUsed,
                    'next_bill_at' => $profile->next_bill_at,
                    'added_amount' => $attempt->amount
                ]);
            }
        }
    }

    /**
     * Deactivate Profile on Chargeback
     *
     * @param BillingAttempt $attempt
     * @param mixed $chargebackAmount
     * @return void
     */
    private function deactivateProfile(BillingAttempt $attempt, mixed $chargebackAmount): void
    {
        $profile = $attempt->debtorProfile ?? $attempt->debtor?->debtorProfile;

        if ($profile) {
            // Deduct revenue
            // Use the amount from the webhook if available, otherwise fallback to the attempt amount
            $amountToDeduct = $chargebackAmount ?? $attempt->amount;
            if ($amountToDeduct > 0) {
                $profile->deductLifetimeRevenue((float) $amountToDeduct);
            }

            $profile->is_active = false;
            $profile->next_bill_at = null; // Remove from billing schedule entirely
            $profile->save();

            Log::warning("Profile {$profile->id} deactivated and revenue adjusted due to chargeback", [
                'deducted_amount' => $amountToDeduct
            ]);
        }
    }

    private function handleUnknown(): void
    {
        Log::info('EMP webhook unknown processing type', [
            'processing_type' => $this->processingType,
            'data' => $this->webhookData,
        ]);
    }

    private function shouldBlacklistCode(string $code): bool
    {
        if ($this->blacklistCodesFlipped === null) {
            $blacklistCodes = config('tether.chargeback.blacklist_codes', []);
            $this->blacklistCodesFlipped = array_flip($blacklistCodes);
        }
        return isset($this->blacklistCodesFlipped[$code]);
    }

    private function mapEmpStatus(?string $empStatus): ?string
    {
        return match ($empStatus) {
            'approved' => BillingAttempt::STATUS_APPROVED,
            'declined' => BillingAttempt::STATUS_DECLINED,
            'error' => BillingAttempt::STATUS_ERROR,
            'voided' => BillingAttempt::STATUS_VOIDED,
            'chargebacked' => BillingAttempt::STATUS_CHARGEBACKED,
            'pending', 'pending_async' => BillingAttempt::STATUS_PENDING,
            default => null,
        };
    }
}
