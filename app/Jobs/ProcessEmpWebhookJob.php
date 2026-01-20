<?php

namespace App\Jobs;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\DebtorProfile;
use App\Models\WebhookEvent;
use App\Services\BlacklistService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessEmpWebhookJob implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 60, 300];
    public int $timeout = 60;
    public int $maxExceptions = 3;

    private ?array $blacklistCodesFlipped = null;

    public function __construct(
        private array $webhookData,
        private string $processingType,
        private string $receivedAt,
        private ?int $webhookEventId = null
    ) {
        $this->onQueue('webhooks');
    }

    public function uniqueId(): ?string
    {
        $uniqueId = $this->webhookData['unique_id'] ?? null;
        if ($uniqueId === null) {
            return null;
        }
        return "emp_wh_{$this->processingType}_{$uniqueId}";
    }

    public function uniqueFor(): int
    {
        return 3600;
    }

    public function handle(BlacklistService $blacklistService): void
    {
        $uniqueId = $this->webhookData['unique_id'] ?? null;

        $this->updateWebhookStatus(WebhookEvent::PROCESSING);

        Log::info('ProcessEmpWebhookJob started', [
            'processing_type' => $this->processingType,
            'unique_id' => $uniqueId,
            'attempt' => $this->attempts(),
        ]);

        try {
            match ($this->processingType) {
                'chargeback' => $this->handleChargeback($blacklistService),
                'retrieval_request' => $this->handleRetrievalRequest(),
                'sdd_status_update' => $this->handleSddStatusUpdate(),
                default => $this->handleUnknown(),
            };

            $this->updateWebhookStatus(WebhookEvent::COMPLETED);

        } catch (\Exception $e) {
            Log::error('ProcessEmpWebhookJob failed', [
                'processing_type' => $this->processingType,
                'unique_id' => $uniqueId,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            $this->incrementWebhookRetry();

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->updateWebhookStatus(WebhookEvent::FAILED, $exception->getMessage());

        Log::error('ProcessEmpWebhookJob moved to DLQ', [
            'processing_type' => $this->processingType,
            'unique_id' => $this->webhookData['unique_id'] ?? null,
            'webhook_event_id' => $this->webhookEventId,
            'error' => $exception->getMessage(),
        ]);
    }

    private function updateWebhookStatus(int $status, ?string $error = null): void
    {
        if (!$this->webhookEventId) {
            return;
        }

        $event = WebhookEvent::find($this->webhookEventId);
        if (!$event) {
            return;
        }

        match ($status) {
            WebhookEvent::PROCESSING => $event->markProcessing(),
            WebhookEvent::COMPLETED => $event->markCompleted(),
            WebhookEvent::FAILED => $event->markFailed($error ?? 'Unknown error'),
            default => null,
        };
    }

    private function incrementWebhookRetry(): void
    {
        if ($this->webhookEventId) {
            WebhookEvent::where('id', $this->webhookEventId)->increment('retry_count');
        }
    }

    private function handleChargeback(BlacklistService $blacklistService): void
    {
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
            'webhook_event_id' => $this->webhookEventId,
        ];

        $currentMeta = $billingAttempt->meta ?? [];
        $currentMeta['chargeback'] = $chargebackMeta;

        $billingAttempt->update([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargebacked_at' => now(),
            'error_code' => $errorCode,
            'error_message' => $chargebackMeta['reason'],
            'meta' => $currentMeta,
        ]);

        $debtor = $billingAttempt->debtor;
        if ($debtor && $debtor->status !== Debtor::STATUS_FAILED) {
            $debtor->update(['status' => Debtor::STATUS_FAILED]);
        }

        $blacklisted = false;
        if ($errorCode && $this->shouldBlacklistCode($errorCode)) {
            if ($debtor && $debtor->iban) {
                $blacklistService->addDebtor($debtor, 'chargeback', "Auto-blacklisted: {$errorCode}");
                $blacklisted = true;
            }
        }

        $this->deactivateProfile($billingAttempt, $chargebackMeta['amount'] ?? null);

        Log::info('Chargeback processed', [
            'billing_attempt_id' => $billingAttempt->id,
            'unique_id' => $originalUniqueId,
            'error_code' => $errorCode,
            'blacklisted' => $blacklisted,
        ]);
    }

    private function handleRetrievalRequest(): void
    {
        $uniqueId = $this->webhookData['unique_id'] ?? null;

        if (!$uniqueId) {
            Log::warning('Retrieval request missing unique_id');
            return;
        }

        $billingAttempt = BillingAttempt::where('unique_id', $uniqueId)->first();

        if (!$billingAttempt) {
            Log::info('Retrieval request for unknown transaction', ['unique_id' => $uniqueId]);
            return;
        }

        $currentMeta = $billingAttempt->meta ?? [];
        $currentMeta['retrieval_requests'] = $currentMeta['retrieval_requests'] ?? [];
        $currentMeta['retrieval_requests'][] = [
            'arn' => $this->webhookData['arn'] ?? null,
            'reason_code' => $this->webhookData['reason_code'] ?? null,
            'reason_description' => $this->webhookData['reason_description'] ?? null,
            'post_date' => $this->webhookData['post_date'] ?? null,
            'received_at' => $this->receivedAt,
            'webhook_event_id' => $this->webhookEventId,
        ];

        $billingAttempt->update(['meta' => $currentMeta]);

        Log::info('Retrieval request logged', [
            'billing_attempt_id' => $billingAttempt->id,
            'unique_id' => $uniqueId,
        ]);
    }

    private function handleSddStatusUpdate(): void
    {
        $uniqueId = $this->webhookData['unique_id'] ?? null;
        $status = $this->webhookData['status'] ?? null;

        if (!$uniqueId) {
            Log::warning('SDD status update missing unique_id');
            return;
        }

        $billingAttempt = BillingAttempt::with(['debtorProfile', 'debtor'])
            ->where('unique_id', $uniqueId)
            ->first();

        if (!$billingAttempt) {
            Log::info('SDD status update for unknown transaction', ['unique_id' => $uniqueId]);
            return;
        }

        $mappedStatus = $this->mapEmpStatus($status);

        if ($mappedStatus && $billingAttempt->status !== $mappedStatus) {
            $oldStatus = $billingAttempt->status;

            $updateData = ['status' => $mappedStatus];

            if ($mappedStatus === BillingAttempt::STATUS_CHARGEBACKED && !$billingAttempt->chargebacked_at) {
                $updateData['chargebacked_at'] = now();
            }

            $billingAttempt->update($updateData);

            if ($mappedStatus === BillingAttempt::STATUS_APPROVED) {
                $this->handleSuccess($billingAttempt);
            }

            if ($mappedStatus === BillingAttempt::STATUS_CHARGEBACKED) {
                $debtor = $billingAttempt->debtor;
                if ($debtor && $debtor->status !== Debtor::STATUS_FAILED) {
                    $debtor->update(['status' => Debtor::STATUS_FAILED]);
                }
            }

            Log::info('SDD transaction status updated', [
                'billing_attempt_id' => $billingAttempt->id,
                'old_status' => $oldStatus,
                'new_status' => $mappedStatus,
            ]);
        }
    }

    private function handleSuccess(BillingAttempt $attempt): void
    {
        $profile = $attempt->debtorProfile;

        if (!$profile) {
            $attempt->load('debtor.debtorProfile');
            $profile = $attempt->debtor?->debtorProfile;
        }

        if ($profile) {
            if ($attempt->amount > 0) {
                $profile->addLifetimeRevenue((float) $attempt->amount);
            }

            $modelUsed = $attempt->billing_model ?? $profile->billing_model;

            if ($modelUsed !== DebtorProfile::MODEL_LEGACY) {
                $profile->last_success_at = now();
                $profile->last_billed_at = now();
                $profile->next_bill_at = DebtorProfile::calculateNextBillDate($modelUsed);
                $profile->save();
            }
        }

        $debtor = $attempt->debtor;
        if ($debtor && $debtor->status !== Debtor::STATUS_RECOVERED) {
            $debtor->update(['status' => Debtor::STATUS_RECOVERED]);
        }
    }

    private function deactivateProfile(BillingAttempt $attempt, mixed $chargebackAmount): void
    {
        $profile = $attempt->debtorProfile ?? $attempt->debtor?->debtorProfile;

        if ($profile) {
            $amountToDeduct = $chargebackAmount ?? $attempt->amount;
            if ($amountToDeduct > 0) {
                $profile->deductLifetimeRevenue((float) $amountToDeduct);
            }

            $profile->is_active = false;
            $profile->next_bill_at = null;
            $profile->save();
        }
    }

    private function handleUnknown(): void
    {
        Log::info('EMP webhook unknown type', [
            'processing_type' => $this->processingType,
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
