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
    public const CHUNK_SIZE = 50; // Process in smaller chunks for memory
    public const RATE_LIMIT_DELAY_MS = 100; // 10 requests/second to EMP

    public function __construct(EmpClient $client)
    {
        $this->client = $client;
    }

    /**
     * Fetch a single page of transactions from EMP.
     * Used by chunk jobs.
     *
     * @return array{transactions: array, has_more: bool}
     */
    public function fetchPage(string $startDate, string $endDate, int $page): array
    {
        $response = $this->client->getTransactionsByDate(
            $startDate,
            $endDate,
            $page,
            self::PER_PAGE
        );

        if (isset($response['status']) && $response['status'] === 'error') {
            Log::error('EMP Refresh: API error', ['response' => $response, 'page' => $page]);
            return ['transactions' => [], 'has_more' => false, 'error' => true];
        }

        $transactions = $this->extractTransactions($response);
        $hasMore = count($transactions) >= self::PER_PAGE;

        return [
            'transactions' => $transactions,
            'has_more' => $hasMore,
            'error' => false,
        ];
    }

    /**
     * Process a batch of transactions (upsert).
     * Used by chunk jobs.
     *
     * @param array $transactions
     * @return array{inserted: int, updated: int, errors: int}
     */
    public function processTransactions(array $transactions): array
    {
        $stats = ['inserted' => 0, 'updated' => 0, 'errors' => 0];

        // Process in smaller chunks for memory efficiency
        $chunks = array_chunk($transactions, self::CHUNK_SIZE);

        foreach ($chunks as $chunk) {
            DB::beginTransaction();
            try {
                foreach ($chunk as $tx) {
                    $result = $this->upsertTransaction($tx);
                    $stats[$result]++;
                }
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('EMP Refresh: chunk failed', ['error' => $e->getMessage()]);
                $stats['errors'] += count($chunk);
            }

            // Rate limiting - prevent memory issues
            usleep(self::RATE_LIMIT_DELAY_MS * 1000);
        }

        return $stats;
    }

    /**
     * Get total pages estimate for a date range.
     * Fetches first page to determine if there's more data.
     */
    public function estimatePages(string $startDate, string $endDate): array
    {
        $firstPage = $this->fetchPage($startDate, $endDate, 1);
        
        return [
            'first_page_count' => count($firstPage['transactions']),
            'has_more' => $firstPage['has_more'],
            'error' => $firstPage['error'] ?? false,
        ];
    }

    /**
     * Extract transactions array from API response.
     */
    private function extractTransactions(array $response): array
    {
        // Response structure may vary - handle both single and multiple transactions
        if (isset($response['payment_transaction'])) {
            $tx = $response['payment_transaction'];
            // Check if it's a single transaction or array of transactions
            if (isset($tx['unique_id'])) {
                return [$tx]; // Single transaction
            }
            return array_values($tx); // Array of transactions
        }

        if (isset($response['payment_transactions'])) {
            return array_values($response['payment_transactions']);
        }

        return [];
    }

    /**
     * Upsert a single transaction.
     *
     * @return string 'inserted' | 'updated' | 'errors'
     */
    private function upsertTransaction(array $tx): string
    {
        try {
            $uniqueId = $tx['unique_id'] ?? null;
            $transactionId = $tx['transaction_id'] ?? null;

            if (!$uniqueId) {
                Log::warning('EMP Refresh: transaction without unique_id', ['tx' => $tx]);
                return 'errors';
            }

            // Try to find existing record by unique_id first (primary key for upsert)
            $existing = BillingAttempt::where('unique_id', $uniqueId)->first();
            
            if (!$existing && $transactionId) {
                // Try by our transaction_id (tether_*)
                $existing = BillingAttempt::where('transaction_id', $transactionId)->first();
            }

            $data = $this->mapTransactionData($tx);

            if ($existing) {
                // Update existing record (only if status changed or new data)
                $existing->update($data);
                return 'updated';
            }

            // Try to find debtor by transaction_id pattern (tether_{debtor_id}_...)
            $debtorId = $this->extractDebtorId($transactionId);
            $debtor = $debtorId ? Debtor::find($debtorId) : null;

            if (!$debtor) {
                // Orphan transaction - log but still create
                Log::info('EMP Refresh: orphan transaction', [
                    'unique_id' => $uniqueId,
                    'transaction_id' => $transactionId,
                ]);
            }

            // Insert new record
            BillingAttempt::create(array_merge($data, [
                'debtor_id' => $debtor?->id,
                'upload_id' => $debtor?->upload_id,
                'transaction_id' => $transactionId ?? 'emp_import_' . $uniqueId,
                'attempt_number' => 1,
            ]));

            // Update debtor status if approved
            if ($debtor && $data['status'] === BillingAttempt::STATUS_APPROVED) {
                $debtor->update(['status' => Debtor::STATUS_RECOVERED]);
            }

            return 'inserted';

        } catch (\Exception $e) {
            Log::error('EMP Refresh: upsert failed', [
                'error' => $e->getMessage(),
                'unique_id' => $tx['unique_id'] ?? 'unknown',
            ]);
            return 'errors';
        }
    }

    /**
     * Map EMP transaction to BillingAttempt fields.
     */
    private function mapTransactionData(array $tx): array
    {
        $status = $this->mapStatus($tx['status'] ?? 'unknown');
        $amount = isset($tx['amount']) ? ((float) $tx['amount']) / 100 : 0; // Convert from cents

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

    /**
     * Extract debtor ID from transaction_id pattern (tether_{id}_...).
     */
    private function extractDebtorId(?string $transactionId): ?int
    {
        if (!$transactionId) {
            return null;
        }

        if (preg_match('/^tether_(\d+)_/', $transactionId, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
