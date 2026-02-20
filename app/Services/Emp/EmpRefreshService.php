<?php

/**
 * Service for refreshing billing attempts from emerchantpay API.
 * Handles bulk import and synchronization of transaction data.
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
     * Fetch a page of transactions from EMP API.
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
     * Process array of transactions and upsert to database.
     */
    public function processTransactions(array $transactions, ?int $empAccountId = null): array
    {
        $stats = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => 0, 'debtors_synced' => 0];
        
        $batches = array_chunk($transactions, self::BATCH_SIZE);
        
        foreach ($batches as $batch) {
            $result = $this->processBatch($batch, $empAccountId);
            $stats['inserted'] += $result['inserted'];
            $stats['updated'] += $result['updated'];
            $stats['unchanged'] += $result['unchanged'];
            $stats['errors'] += $result['errors'];
            $stats['debtors_synced'] += $result['debtors_synced'] ?? 0;
            
            usleep(self::RATE_LIMIT_DELAY_MS * 1000);
        }

        return $stats;
    }

    /**
     * Process a batch of transactions using upsert.
     */
    private function processBatch(array $transactions, ?int $empAccountId = null): array
    {
        $rows = [];
        $uniqueIds = [];
        $newDataByUniqueId = [];
        $chargebackedUniqueIds = [];
        $now = now();
        $terminalToken = $this->client->getTerminalToken();
        
        foreach ($transactions as $tx) {
            $uniqueId = $tx['unique_id'] ?? null;
            if (!$uniqueId) {
                continue;
            }
            
            $uniqueIds[] = $uniqueId;
            $transactionId = $tx['transaction_id'] ?? null;
            $status = $this->mapStatus($tx['status'] ?? 'unknown');
            $amount = isset($tx['amount']) ? ((float) $tx['amount']) / 100 : 0;
            $empCreatedAt = isset($tx['timestamp']) ? Carbon::parse($tx['timestamp']) : null;
            
            $newDataByUniqueId[$uniqueId] = [
                'status' => $status,
                'amount' => $amount,
            ];

            if ($status === BillingAttempt::STATUS_CHARGEBACKED) {
                $chargebackedUniqueIds[$uniqueId] = $empCreatedAt ?? $now;
            }
            
            $rows[] = [
                'unique_id' => $uniqueId,
                'transaction_id' => $transactionId ?: 'emp_import_' . $uniqueId,
                'status' => $status,
                'amount' => $amount,
                'currency' => $tx['currency'] ?? 'EUR',
                'bic' => null,
                'mid_reference' => $terminalToken,
                'emp_account_id' => $empAccountId,
                'error_code' => $tx['code'] ?? $tx['reason_code'] ?? null,
                'error_message' => $tx['message'] ?? null,
                'technical_message' => $tx['technical_message'] ?? null,
                'emp_created_at' => $empCreatedAt,
                'processed_at' => $now,
                'response_payload' => json_encode($tx),
                'attempt_number' => 1,
                'last_reconciled_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        
        if (empty($rows)) {
            return ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => 0, 'debtors_synced' => 0];
        }
        
        try {
            $existing = BillingAttempt::whereIn('unique_id', $uniqueIds)
                ->select('unique_id', 'status', 'amount')
                ->get()
                ->keyBy('unique_id');
            
            $inserted = 0;
            $updated = 0;
            $unchanged = 0;
            
            foreach ($newDataByUniqueId as $uniqueId => $newData) {
                if (!$existing->has($uniqueId)) {
                    $inserted++;
                } else {
                    $old = $existing->get($uniqueId);
                    if ($old->status !== $newData['status']) {
                        $updated++;
                    } else {
                        $unchanged++;
                    }
                }
            }
            
            BillingAttempt::upsert(
                $rows,
                ['unique_id'],
                ['status', 'amount', 'currency', 'error_code', 'error_message', 'technical_message', 'emp_created_at', 'processed_at', 'response_payload', 'last_reconciled_at', 'mid_reference', 'emp_account_id']
            );

            // Set chargebacked_at for records where it's NULL (don't overwrite webhook values)
            if (!empty($chargebackedUniqueIds)) {
                $cbUpdated = BillingAttempt::whereIn('unique_id', array_keys($chargebackedUniqueIds))
                    ->where('status', BillingAttempt::STATUS_CHARGEBACKED)
                    ->whereNull('chargebacked_at')
                    ->update(['chargebacked_at' => $now]);

                if ($cbUpdated > 0) {
                    Log::info('EMP Refresh: set chargebacked_at for records missing it', [
                        'updated_count' => $cbUpdated,
                    ]);
                }
            }

            // Sync debtor statuses from updated billing_attempts
            $debtorsSynced = $this->syncDebtorStatuses($uniqueIds);
            
            Log::debug('EMP Refresh: batch upserted', [
                'total' => count($rows),
                'inserted' => $inserted,
                'updated' => $updated,
                'unchanged' => $unchanged,
                'debtors_synced' => $debtorsSynced,
            ]);
            
            return ['inserted' => $inserted, 'updated' => $updated, 'unchanged' => $unchanged, 'errors' => 0, 'debtors_synced' => $debtorsSynced];
            
        } catch (\Exception $e) {
            Log::error('EMP Refresh: batch upsert failed, falling back to individual', [
                'error' => $e->getMessage(),
                'batch_size' => count($rows),
            ]);
            
            return $this->processIndividually($transactions);
        }
    }

    /**
     * Sync debtor statuses based on their billing_attempt status.
     * Fixes the gap where emp:refresh updates billing_attempt but not debtor.
     */
    private function syncDebtorStatuses(array $uniqueIds): int
    {
        if (empty($uniqueIds)) {
            return 0;
        }

        try {
            $placeholders = implode(',', array_fill(0, count($uniqueIds), '?'));

            // Update debtors whose billing_attempt status changed to approved
            $approvedCount = DB::update("
                UPDATE debtors
                SET status = ?, updated_at = NOW()
                FROM billing_attempts
                WHERE billing_attempts.debtor_id = debtors.id
                AND billing_attempts.unique_id IN ({$placeholders})
                AND debtors.status = ?
                AND billing_attempts.status = ?
            ", array_merge(
                [Debtor::STATUS_APPROVED],
                $uniqueIds,
                [Debtor::STATUS_PENDING, BillingAttempt::STATUS_APPROVED]
            ));

            // Update debtors whose billing_attempt status changed to chargebacked
            $chargebackedCount = DB::update("
                UPDATE debtors
                SET status = ?, updated_at = NOW()
                FROM billing_attempts
                WHERE billing_attempts.debtor_id = debtors.id
                AND billing_attempts.unique_id IN ({$placeholders})
                AND debtors.status NOT IN (?, ?)
                AND billing_attempts.status = ?
            ", array_merge(
                [Debtor::STATUS_CHARGEBACKED],
                $uniqueIds,
                [Debtor::STATUS_CHARGEBACKED, 'failed', BillingAttempt::STATUS_CHARGEBACKED]
            ));

            $total = $approvedCount + $chargebackedCount;

            if ($total > 0) {
                Log::info('EMP Refresh: synced debtor statuses', [
                    'approved' => $approvedCount,
                    'chargebacked' => $chargebackedCount,
                ]);
            }

            return $total;
        } catch (\Exception $e) {
            Log::warning('EMP Refresh: debtor status sync failed', [
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Fallback: process transactions one by one.
     */
    private function processIndividually(array $transactions): array
    {
        $stats = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => 0];
        
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
     * Upsert a single transaction.
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
            if ($existing->status === $data['status']) {
                // Use Query Builder to avoid touching updated_at on unchanged records
                BillingAttempt::where('id', $existing->id)
                    ->update(['last_reconciled_at' => now()]);
                return 'unchanged';
            }

            // Set chargebacked_at if transitioning to chargebacked and not already set
            if ($data['status'] === BillingAttempt::STATUS_CHARGEBACKED && !$existing->chargebacked_at) {
                $data['chargebacked_at'] = now();
            }

            $existing->update($data);

            // Sync debtor status for individual upsert
            $this->syncDebtorStatuses([$uniqueId]);

            return 'updated';
        }

        $createData = array_merge($data, [
            'transaction_id' => $transactionId ?: 'emp_import_' . $uniqueId,
            'attempt_number' => 1,
        ]);

        // Set chargebacked_at for new chargebacked records
        if ($data['status'] === BillingAttempt::STATUS_CHARGEBACKED) {
            $createData['chargebacked_at'] = isset($tx['timestamp']) ? Carbon::parse($tx['timestamp']) : now();
        }

        BillingAttempt::create($createData);

        return 'inserted';
    }

    /**
     * Estimate total pages for a date range.
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
     * Extract pagination info from API response.
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
     * Map transaction data to BillingAttempt fields.
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
            'bic' => null,
            'mid_reference' => $this->client->getTerminalToken(),
            'error_code' => $tx['code'] ?? $tx['reason_code'] ?? null,
            'error_message' => $tx['message'] ?? null,
            'technical_message' => $tx['technical_message'] ?? null,
            'emp_created_at' => isset($tx['timestamp']) ? Carbon::parse($tx['timestamp']) : null,
            'processed_at' => now(),
            'last_reconciled_at' => now(),
            'response_payload' => $tx,
        ];
    }

    /**
     * Map EMP status to BillingAttempt status constant.
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
