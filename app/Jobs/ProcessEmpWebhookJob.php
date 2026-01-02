<?php

namespace App\Jobs;

use App\Models\BillingAttempt;
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

    private ?array $blacklistCodesFlipped = null;

    public function __construct(
        private array $webhookData,
        private string $transactionType,
        private string $receivedAt
    )
    {
        $this->onQueue('webhooks');
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
        $originalTxId = $this->webhookData['original_transaction_unique_id'] ?? null;

        if (!$originalTxId) {
            Log::error('Chargeback missing original_transaction_unique_id', $this->webhookData);
            return;
        }

        // Find original billing attempt with debtor eager-loaded
        $billingAttempt = BillingAttempt::with('debtor')
            ->where('transaction_id', $originalTxId)
            ->first();

        if (!$billingAttempt) {
            Log::warning('Chargeback for unknown transaction', ['unique_id' => $originalTxId]);
            return;
        }

        $errorCode = $this->webhookData['reason_code'] ?? $this->webhookData['error_code'] ?? null;

        $chargebackMeta = [
            'unique_id' => $this->webhookData['unique_id'] ?? null,
            'amount' => $this->webhookData['amount'] ?? null,
            'currency' => $this->webhookData['currency'] ?? null,
            'reason' => $this->webhookData['reason'] ?? null,
            'reason_code' => $errorCode,
            'received_at' => $this->receivedAt
        ];

        $currentMeta = $billingAttempt->meta ?? [];
        $currentMeta['chargeback'] = $chargebackMeta;

        // Update status to chargebacked
        $billingAttempt->update([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'error_code' => $errorCode,
            'error_message' => $this->webhookData['reason'] ?? null,
            'meta' => $currentMeta,
        ]);

        // Auto-blacklist debtor (IBAN + name + email) if error code matches
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
            'original_tx' => $originalTxId,
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

        $billingAttempt = BillingAttempt::where('transaction_id', $uniqueId)->first();

        if (!$billingAttempt) {
            Log::info('Transaction notification for unknown tx', ['unique_id' => $uniqueId]);
            return;
        }

        // Map EMP status to our status
        $mappedStatus = $this->mapEmpStatus($status);

        // Skip update if status is already the same
        if ($mappedStatus && $billingAttempt->status !== $mappedStatus) {
            $billingAttempt->update(['status' => $mappedStatus]);
            Log::info('Transaction status updated', [
                'billing_attempt_id' => $billingAttempt->id,
                'old_status' => $billingAttempt->status,
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
