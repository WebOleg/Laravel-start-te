<?php

/**
 * Service for handling emerchantpay webhook processing.
 * 
 * Manages webhook validation, deduplication, and job dispatching.
 * 
 * EMP Webhook Types:
 * 1. Transaction status updates: transaction_type=sdd_sale, status=approved|declined|etc
 * 2. Chargeback events: event=chargeback, transaction_type=original_type, status=chargebacked
 * 3. Retrieval requests: event=retrieval_request
 */

namespace App\Services\Emp;

use App\Jobs\ProcessEmpWebhookJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EmpWebhookService
{
    // Events that we process (from 'event' parameter)
    private const PROCESSABLE_EVENTS = ['chargeback', 'retrieval_request'];
    
    // Transaction types that we process status updates for
    private const PROCESSABLE_TRANSACTION_TYPES = ['sdd_sale', 'sdd_init_recurring_sale', 'sdd_recurring_sale'];
    
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
        
        $uniqueId = $data['unique_id'] ?? null;
        $this->validateUniqueId($uniqueId);
        
        // Determine webhook type: event-based (chargeback) or transaction status update
        $event = $data['event'] ?? null;
        $transactionType = $data['transaction_type'] ?? 'unknown';
        $status = $data['status'] ?? null;
        
        // Determine processing type
        $processingType = $this->determineProcessingType($event, $transactionType, $status);
        
        if ($processingType === null) {
            Log::info('EMP webhook received (not processed)', [
                'unique_id' => $uniqueId,
                'transaction_type' => $transactionType,
                'event' => $event,
                'status' => $status,
            ]);
            
            return [
                'queued' => false,
                'message' => 'Webhook acknowledged (type not processed)',
                'unique_id' => $uniqueId,
            ];
        }
        
        // Check for duplicates before queuing
        if ($this->isDuplicate($processingType, $uniqueId)) {
            Log::info('EMP webhook duplicate (already queued)', [
                'unique_id' => $uniqueId,
                'processing_type' => $processingType,
            ]);
            
            return [
                'queued' => false,
                'message' => 'Webhook already queued',
            ];
        }

        // Mark as processed and dispatch job
        $this->markAsProcessed($processingType, $uniqueId);
        ProcessEmpWebhookJob::dispatch($data, $processingType, now()->toIso8601String());

        Log::info('EMP webhook queued for processing', [
            'unique_id' => $uniqueId,
            'processing_type' => $processingType,
            'transaction_type' => $transactionType,
            'event' => $event,
            'status' => $status,
        ]);

        return [
            'queued' => true,
            'message' => $this->getSuccessMessage($processingType),
            'unique_id' => $uniqueId,
            'type' => $processingType,
        ];
    }

    /**
     * Determine the processing type based on event and transaction type.
     * 
     * EMP sends:
     * - Chargebacks: event=chargeback, transaction_type=original_type, status=chargebacked
     * - Retrieval: event=retrieval_request
     * - Status updates: transaction_type=sdd_sale, status=approved|declined|etc
     */
    private function determineProcessingType(?string $event, string $transactionType, ?string $status): ?string
    {
        // Event-based notifications take priority (chargeback, retrieval_request)
        if ($event !== null && in_array($event, self::PROCESSABLE_EVENTS, true)) {
            return $event;
        }
        
        // Transaction status updates for SDD transactions
        if (in_array($transactionType, self::PROCESSABLE_TRANSACTION_TYPES, true)) {
            return 'sdd_status_update';
        }
        
        // Status=chargebacked without event parameter (legacy/fallback)
        if ($status === 'chargebacked') {
            return 'chargeback';
        }
        
        return null;
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
     * Check if webhook was already processed using cache.
     */
    private function isDuplicate(string $processingType, string $uniqueId): bool
    {
        return Cache::has($this->getDedupKey($processingType, $uniqueId));
    }

    /**
     * Mark webhook as processed in cache to prevent duplicates.
     */
    private function markAsProcessed(string $processingType, string $uniqueId): void
    {
        Cache::put(
            $this->getDedupKey($processingType, $uniqueId),
            true,
            self::WEBHOOK_DEDUP_TTL
        );
    }

    /**
     * Generate cache key for webhook deduplication.
     * Format: webhook_dedup_{type}_{unique_id}
     */
    private function getDedupKey(string $processingType, string $uniqueId): string
    {
        return self::DEDUP_KEY_PREFIX . "_{$processingType}_{$uniqueId}";
    }

    /**
     * Get user-friendly success message based on processing type.
     */
    private function getSuccessMessage(string $processingType): string
    {
        return match ($processingType) {
            'chargeback' => 'Chargeback processing queued',
            'retrieval_request' => 'Retrieval request processing queued',
            'sdd_status_update' => 'Transaction status update queued',
            default => 'Processing queued',
        };
    }
}
