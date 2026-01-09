<?php

/**
 * Service for handling emerchantpay webhook processing.
 * 
 * Manages webhook validation, deduplication, and job dispatching.
 */

namespace App\Services\Emp;

use App\Jobs\ProcessEmpWebhookJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EmpWebhookService
{
    private const PROCESSABLE_TYPES = ['chargeback', 'sdd_sale'];
    private const WEBHOOK_DEDUP_TTL = 3600; // 1 hour
    private const DEDUP_KEY_PREFIX = 'webhook_dedup';

    /**
     * Process incoming webhook request.
     * 
     * @throws \InvalidArgumentException If webhook is invalid
     */
    public function process(Request $request): array
    {
        $data = $request->all();
        
        $this->verifySignature($request);
        
        $transactionType = $data['transaction_type'] ?? 'unknown';
        $uniqueId = $data['unique_id'] ?? null;
        
        $this->validateUniqueId($uniqueId);
        
        // Log all webhooks, but only process known types
        if (!$this->isProcessableType($transactionType)) {
            Log::info('EMP webhook received (not processed)', [
                'unique_id' => $uniqueId,
                'transaction_type' => $transactionType,
                'status' => $data['status'] ?? null,
            ]);
            
            return [
                'queued' => false,
                'message' => 'Webhook acknowledged (type not processed)',
                'unique_id' => $uniqueId,
                'type' => $transactionType,
            ];
        }
        
        // Check for duplicates before queuing
        if ($this->isDuplicate($transactionType, $uniqueId)) {
            Log::info('EMP webhook duplicate (already queued)', [
                'unique_id' => $uniqueId,
                'transaction_type' => $transactionType,
            ]);
            
            return [
                'queued' => false,
                'message' => 'Webhook already queued',
            ];
        }

        // Mark as processed and dispatch job
        $this->markAsProcessed($transactionType, $uniqueId);
        ProcessEmpWebhookJob::dispatch($data, $transactionType, now()->toIso8601String());

        Log::info('EMP webhook queued for processing', [
            'unique_id' => $uniqueId,
            'transaction_type' => $transactionType,
            'status' => $data['status'] ?? null,
        ]);

        return [
            'queued' => true,
            'message' => $this->getSuccessMessage($transactionType),
            'unique_id' => $uniqueId,
            'type' => $transactionType,
        ];
    }

    /**
     * Verify webhook signature using SHA-1 hash.
     * 
     * @throws \InvalidArgumentException If signature is invalid
     */
    private function verifySignature(Request $request): void
    {
        $signature = $request->input('signature');
        $uniqueId = $request->input('unique_id');
        $apiPassword = config('services.emp.password');

        if (!$signature || !$uniqueId || !$apiPassword) {
            Log::warning('EMP webhook missing signature components', [
                'has_signature' => !empty($signature),
                'has_unique_id' => !empty($uniqueId),
                'ip' => $request->ip(),
            ]);
            throw new \InvalidArgumentException('Invalid signature');
        }

        $expectedSignature = hash('sha1', $uniqueId . $apiPassword);

        if (!hash_equals($expectedSignature, $signature)) {
            Log::warning('EMP webhook signature mismatch', [
                'unique_id' => $uniqueId,
                'ip' => $request->ip(),
            ]);
            throw new \InvalidArgumentException('Invalid signature');
        }
    }

    /**
     * Validate unique_id is present and non-empty.
     * Required for reconciliation and deduplication.
     * 
     * @throws \InvalidArgumentException If unique_id is invalid
     */
    private function validateUniqueId(?string $uniqueId): void
    {
        if (empty($uniqueId) || !is_string($uniqueId)) {
            Log::error('EMP webhook missing required unique_id', [
                'ip' => request()->ip(),
            ]);
            throw new \InvalidArgumentException('Missing unique_id');
        }
    }

    /**
     * Check if transaction type should be processed.
     */
    private function isProcessableType(?string $type): bool
    {
        return in_array($type, self::PROCESSABLE_TYPES, true);
    }

    /**
     * Check if webhook was already processed using cache.
     */
    private function isDuplicate(string $transactionType, string $uniqueId): bool
    {
        return Cache::has($this->getDedupKey($transactionType, $uniqueId));
    }

    /**
     * Mark webhook as processed in cache to prevent duplicates.
     */
    private function markAsProcessed(string $transactionType, string $uniqueId): void
    {
        Cache::put(
            $this->getDedupKey($transactionType, $uniqueId),
            true,
            self::WEBHOOK_DEDUP_TTL
        );
    }

    /**
     * Generate cache key for webhook deduplication.
     * Format: webhook_dedup_{type}_{unique_id}
     */
    private function getDedupKey(string $transactionType, string $uniqueId): string
    {
        return self::DEDUP_KEY_PREFIX . "_{$transactionType}_{$uniqueId}";
    }

    /**
     * Get user-friendly success message based on transaction type.
     */
    private function getSuccessMessage(string $transactionType): string
    {
        return match ($transactionType) {
            'chargeback' => 'Chargeback processing queued',
            'sdd_sale' => 'Transaction processing queued',
            default => 'Processing queued',
        };
    }
}
