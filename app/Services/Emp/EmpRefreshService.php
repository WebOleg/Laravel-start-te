<?php

/**
 * Service for refreshing/importing transactions from EMP gateway.
 * Pulls transactions by date range and upserts into local database.
 * Optimized for high volume (3-5M transactions/month).
 */

namespace App\Services\Emp;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class EmpRefreshService
{
    private EmpClient $client;
    
    public const PER_PAGE = 100;
    public const BATCH_SIZE = 100;
    public const RATE_LIMIT_DELAY_MS = 50;

    public function __construct(EmpClient $client)
    {
        $this->client = $client;
    }

    /**
     * Fetch a single page of transactions from EMP.
     */
    public function fetchPage(string $startDate, string $endDate, int $page): array
    {
        $response = $this->client->getTransactionsByDate($startDate, $endDate, $page);

        if (isset($response['status']) && $response['status'] === 'error') {
            Log::error('EMP Refresh: API error', ['response' => $response, 'page' => $page]);
            return ['transactions' => [], 'has_more' => false, 'error' => true, 'pagination' => null];
        }

        $pagination = $this->extractPagination($response);
        $transactions = $this->extractTransactions($response);
        
        $hasMore = $pagination 
            ? ($pagination['page'] < $pagination['pages_count'])
            : (count($transactions) >= self::PER_PAGE);

        Log::debug('EMP Refresh: fetchPage', [
            'page' => $page,
            'transactions_count' => count($transactions),
            'has_more' => $hasMore,
            'pagination' => $pagination,
        ]);

        return [
            'transactions' => $transactions,
            'has_more' => $hasMore,
            'error' => false,
            'pagination' => $pagination,
        ];
    }

    /**
     * Process a batch of transactions using bulk upsert.
     */
    public function processTransactions(array $transactions): array
    {
        $stats = ['inserted' => 0, 'updated' => 0, 'errors' => 0];
        
        $batches = array_chunk($transactions, self::BATCH_SIZE);
        
        foreach ($batches as $batch) {
            $result = $this->processBatch($batch);
            $stats['inserted'] += $result['inserted'];
            $stats['updated'] += $result['updated'];
            $stats['errors'] += $result['errors'];
            
            usleep(self::RATE_LIMIT_DELAY_MS * 1000);
        }

        return $stats;
    }

    /**
     * Process a single batch with bulk upsert.
     */
    private function processBatch(array $transactions): array
    {
        $rows = [];
        $now = now();
        
        foreach ($transactions as $tx) {
            $uniqueId = $tx['unique_id'] ?? null;
            if (!$uniqueId) {
                continue;
            }
            
            $transactionId = $tx['transaction_id'] ?? null;
            $status = $this->mapStatus($tx['status'] ?? 'unknown');
            $amount = isset($tx['amount']) ? ((float) $tx['amount']) / 100 : 0;
            
            $rows[] = [
                'unique_id' => $uniqueId,
                'transaction_id' => $transactionId ?: 'emp_import_' . $uniqueId,
                'status' => $status,
                'amount' => $amount,
                'currency' => $tx['currency'] ?? 'EUR',
                'error_code' => $tx['code'] ?? $tx['reason_code'] ?? null,
                'error_message' => $tx['message'] ?? null,
                'technical_message' => $tx['technical_message'] ?? null,
                'emp_created_at' => isset($tx['timestamp']) ? Carbon::parse($tx['timestamp']) : null,
                'processed_at' => $now,
                'response_payload' => json_encode($tx),
                'attempt_number' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        
        if (empty($rows)) {
            return ['inserted' => 0, 'updated' => 0, 'errors' => 0];
        }
        
        try {
            $affected = BillingAttempt::upsert(
                $rows,
                ['unique_id'],
                ['status', 'amount', 'currency', 'error_code', 'error_message', 'technical_message', 'emp_created_at', 'processed_at', 'response_payload', 'updated_at']
            );
            
            Log::debug('EMP Refresh: batch upserted', ['count' => count($rows), 'affected' => $affected]);
            
            return ['inserted' => count($rows), 'updated' => 0, 'errors' => 0];
            
        } catch (\Exception $e) {
            Log::error('EMP Refresh: batch upsert failed, falling back to individual', [
                'error' => $e->getMessage(),
                'batch_size' => count($rows),
            ]);
            
            return $this->processIndividually($transactions);
        }
    }

    /**
     * Fallback: process transactions individually if batch fails.
     */
    private function processIndividually(array $transactions): array
    {
        $stats = ['inserted' => 0, 'updated' => 0, 'errors' => 0];
        
        foreach ($transactions as $tx) {
            try {
                $result = $this->upsertTransaction($tx);
                $stats[$result]++;
            } catch (\Exception $e) {
                Log::warning('EMP Refresh: individual upsert failed', [
                    'error' => $e->getMessage(),
                    'unique_id' => $tx['unique_id'] ?? 'unknown',
                ]);
                $stats['errors']++;
            }
        }
        
        return $stats;
    }

    /**
     * Upsert a single transaction (fallback method).
     */
    private function upsertTransaction(array $tx): string
    {
        $uniqueId = $tx['unique_id'] ?? null;
        $transactionId = $tx['transaction_id'] ?? null;

        if (!$uniqueId) {
            return 'errors';
        }

        $existing = BillingAttempt::where('unique_id', $uniqueId)->first();

        $data = $this->mapTransactionData($tx);

        if ($existing) {
            $existing->update($data);
            return 'updated';
        }

        BillingAttempt::create(array_merge($data, [
            'transaction_id' => $transactionId ?: 'emp_import_' . $uniqueId,
            'attempt_number' => 1,
        ]));

        return 'inserted';
    }

    /**
     * Get total pages estimate for a date range.
     */
    public function estimatePages(string $startDate, string $endDate): array
    {
        $firstPage = $this->fetchPage($startDate, $endDate, 1);
        
        return [
            'first_page_count' => count($firstPage['transactions']),
            'has_more' => $firstPage['has_more'],
            'error' => $firstPage['error'] ?? false,
            'pagination' => $firstPage['pagination'] ?? null,
        ];
    }

    /**
     * Extract pagination metadata from API response attributes.
     */
    private function extractPagination(array $response): ?array
    {
        if (!isset($response['@page'])) {
            return null;
        }
        
        return [
            'page' => (int) ($response['@page'] ?? 1),
            'per_page' => (int) ($response['@per_page'] ?? 100),
            'total_count' => (int) ($response['@total_count'] ?? 0),
            'pages_count' => (int) ($response['@pages_count'] ?? 1),
        ];
    }

    /**
     * Extract transactions array from API response.
     */
    private function extractTransactions(array $response): array
    {
        if (isset($response['payment_response'])) {
            $tx = $response['payment_response'];
            if (isset($tx['unique_id'])) {
                return [$tx];
            }
            if (is_array($tx) && isset($tx[0])) {
                return $tx;
            }
            return array_values($tx);
        }

        if (isset($response['payment_transaction'])) {
            $tx = $response['payment_transaction'];
            if (isset($tx['unique_id'])) {
                return [$tx];
            }
            if (is_array($tx) && isset($tx[0])) {
                return $tx;
            }
            return array_values($tx);
        }

        if (isset($response['payment_transactions'])) {
            return array_values($response['payment_transactions']);
        }

        if (isset($response['payment_responses'])) {
            return array_values($response['payment_responses']);
        }

        Log::warning('EMP Refresh: unknown response format', ['keys' => array_keys($response)]);

        return [];
    }

    /**
     * Map EMP transaction to BillingAttempt fields.
     */
    private function mapTransactionData(array $tx): array
    {
        $status = $this->mapStatus($tx['status'] ?? 'unknown');
        $amount = isset($tx['amount']) ? ((float) $tx['amount']) / 100 : 0;

        return [
            'unique_id' => $tx['unique_id'] ?? null,
            'status' => $status,
            'amount' => $amount,
            'currency' => $tx['currency'] ?? 'EUR',
            'error_code' => $tx['code'] ?? $tx['reason_code'] ?? null,
            'error_message' => $tx['message'] ?? null,
            'technical_message' => $tx['technical_message'] ?? null,
            'emp_created_at' => isset($tx['timestamp']) ? Carbon::parse($tx['timestamp']) : null,
            'processed_at' => now(),
            'response_payload' => $tx,
        ];
    }

    /**
     * Map EMP status to local status.
     */
    private function mapStatus(string $empStatus): string
    {
        return match (strtolower($empStatus)) {
            'approved' => BillingAttempt::STATUS_APPROVED,
            'declined' => BillingAttempt::STATUS_DECLINED,
            'error' => BillingAttempt::STATUS_ERROR,
            'voided', 'void' => BillingAttempt::STATUS_VOIDED,
            'chargebacked', 'chargeback', 'refunded' => BillingAttempt::STATUS_CHARGEBACKED,
            'pending', 'pending_async', 'pending_review' => BillingAttempt::STATUS_PENDING,
            default => BillingAttempt::STATUS_ERROR,
        };
    }
}
