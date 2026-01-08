<?php

namespace App\Jobs;

use App\Models\BillingAttempt;
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
        private string $transactionType,
        private string $receivedAt
    ) {
        $this->onQueue('webhooks');
    }

    public function uniqueId(): string|null
    {
        $uniqueId = $this->webhookData['unique_id'] ?? null;

        if ($uniqueId === null) {
            Log::warning('EMP webhook missing unique_id - uniqueness cannot be enforced', [
                'transaction_type' => $this->transactionType,
            ]);
            return null;
        }

        return "webhook_{$this->transactionType}_{$uniqueId}";
    }

    public function uniqueFor(): int
    {
        return 3600;
    }

    public function handle(BlacklistService $blacklistService): void
    {
        Log::info('ProcessEmpWebhookJob started', [
            'transaction_type' => $this->transactionType,
            'unique_id' => $this->webhookData['unique_id'] ?? null,
        ]);

        try {
            match ($this->transactionType) {
                'chargeback' => $this->handleChargeback($blacklistService),
                'sdd_sale' => $this->handleTransaction(),
                default => $this->handleUnknown(),
            };
        } catch (\Exception $e) {
            Log::error('ProcessEmpWebhookJob failed', [
                'transaction_type' => $this->transactionType,
                'error' => $e->getMessage(),
                'data' => $this->webhookData,
            ]);
            throw $e;
        }
    }

    private function handleChargeback(BlacklistService $blacklistService): void
    {
        $originalUniqueId = $this->webhookData['original_transaction_unique_id'] ?? null;

        if (!$originalUniqueId) {
            Log::error('Chargeback missing original_transaction_unique_id', $this->webhookData);
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
                'unique_id' => $this->webhookData['unique_id'] ?? null,
            ]);
            return;
        }

        $errorCode = $this->webhookData['reason_code'] ?? $this->webhookData['error_code'] ?? null;

        $chargebackMeta = [
            'unique_id' => $this->webhookData['unique_id'] ?? null,
            'amount' => $this->webhookData['amount'] ?? null,
            'currency' => $this->webhookData['currency'] ?? null,
            'reason' => $this->webhookData['reason'] ?? null,
            'reason_code' => $errorCode,
            'received_at' => $this->receivedAt,
        ];

        $currentMeta = $billingAttempt->meta ?? [];
        $currentMeta['chargeback'] = $chargebackMeta;

        $billingAttempt->update([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'error_code' => $errorCode,
            'error_message' => $this->webhookData['reason'] ?? null,
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

        Log::info('Chargeback processed', [
            'billing_attempt_id' => $billingAttempt->id,
            'debtor_id' => $billingAttempt->debtor_id,
            'original_unique_id' => $originalUniqueId,
            'error_code' => $errorCode,
            'blacklisted' => $blacklisted,
        ]);
    }

    private function handleTransaction(): void
    {
        $uniqueId = $this->webhookData['unique_id'] ?? null;
        $status = $this->webhookData['status'] ?? null;

        if (!$uniqueId) {
            Log::warning('Transaction notification missing unique_id', $this->webhookData);
            return;
        }

        $billingAttempt = BillingAttempt::where('unique_id', $uniqueId)->first();

        if (!$billingAttempt) {
            Log::info('Transaction notification for unknown tx', ['unique_id' => $uniqueId]);
            return;
        }

        $mappedStatus = $this->mapEmpStatus($status);

        if ($mappedStatus && $billingAttempt->status !== $mappedStatus) {
            $billingAttemptOldStatus = $billingAttempt->status;
            $billingAttempt->update(['status' => $mappedStatus]);
            Log::info('Transaction status updated', [
                'billing_attempt_id' => $billingAttempt->id,
                'old_status' => $billingAttemptOldStatus,
                'new_status' => $mappedStatus,
            ]);
        } else {
            Log::debug('Transaction status unchanged or no valid status', [
                'billing_attempt_id' => $billingAttempt->id,
                'current_status' => $billingAttempt->status,
                'new_status' => $mappedStatus,
            ]);
        }
    }

    private function handleUnknown(): void
    {
        Log::info('EMP webhook unknown type', ['type' => $this->transactionType]);
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
            'pending', 'pending_async' => BillingAttempt::STATUS_PENDING,
            default => null,
        };
    }
}
