<?php

/**
 * Service for fetching SDD chargeback details from EMP.
 * Note: For SDD, post_date is NOT available from API - we use import_date instead.
 */

namespace App\Services\Emp;

use App\Models\BillingAttempt;
use App\Models\EmpAccount;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class EmpChargebackService
{
    public const BATCH_SIZE = 100;
    public const RATE_LIMIT_DELAY_MS = 500;

    public function processChargebackDetail(string $uniqueId): array
    {
        try {
            $billingAttempt = BillingAttempt::where('unique_id', $uniqueId)->first();
            if (!$billingAttempt) {
                return ['success' => false, 'error' => 'Billing attempt not found'];
            }

            // Get the correct EMP account for this billing attempt
            $empAccount = null;
            if ($billingAttempt->emp_account_id) {
                $empAccount = EmpAccount::find($billingAttempt->emp_account_id);
                if (!$empAccount) {
                    return ['success' => false, 'error' => 'EMP account not found for this billing attempt'];
                }
            }

            // Initialize client with the correct account
            $client = new EmpClient($empAccount);
            
            $xml = $client->buildChargebackDetailXml($uniqueId);
            $response = $client->sendRequest('/chargebacks', $xml);

            Log::info('Response obtained from EMP API', [
                'unique_id' => $uniqueId,
                'response' => $response,
            ]);
            
            if (empty($response)) {
                Log::warning('EMP Chargeback: empty response', ['unique_id' => $uniqueId]);
                return ['success' => false, 'error' => 'Empty response from EMP API'];
            }
            
            if (isset($response['status']) && $response['status'] === 'error') {
                $errorCode = $response['code'] ?? 'unknown';
                $errorMessage = $response['message'] ?? 'Unknown error';
                
                Log::error('EMP Chargeback: API error', [
                    'unique_id' => $uniqueId,
                    'error_code' => $errorCode,
                    'message' => $errorMessage,
                    'emp_account_id' => $billingAttempt->emp_account_id,
                ]);
                
                return ['success' => false, 'error' => $errorMessage, 'code' => $errorCode];
            }
            
            $updated = $this->updateChargebackDetails($uniqueId, $response);
            
            return ['success' => $updated, 'data' => $response];
            
        } catch (\Exception $e) {
            Log::error('EMP Chargeback: Exception', [
                'unique_id' => $uniqueId,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function processBulkChargebackDetail(string $mode, ?callable $progressCallback = null, ?int $limit = null, ?int $from = null, ?int $to = null, bool $dryRun = false): array
    {
        $query = BillingAttempt::where('status', BillingAttempt::STATUS_CHARGEBACKED);

        if ($mode === 'empty') {
            $query->where(function ($q) {
                $q->whereNull('chargeback_reason_code')
                  ->orWhere('chargeback_reason_code', '');
            });
        } elseif ($mode === 'empty-reason-messages') {
            $query->where(function ($q) {
                $q->whereNull('chargeback_reason_description')
                  ->orWhere('chargeback_reason_description', '');
            });
        }
        // 'all' mode: no additional filter

        // Apply from/to range (offset-based)
        $offset = $from ?? 0;
        if ($from !== null) {
            $query->skip($offset);
        }

        // Calculate how many records to take
        if ($to !== null) {
            $rangeLimit = $to - $offset;
            // If --chunk is also provided, take the smaller of the two
            $take = $limit !== null ? min($limit, $rangeLimit) : $rangeLimit;
            $query->limit($take);
        } elseif ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        $uniqueIds = $query->latest()->pluck('unique_id')->toArray();
        
        $results = [
            'total' => count($uniqueIds),
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'errors' => []
        ];
        
        $batches = array_chunk($uniqueIds, self::BATCH_SIZE);
        
        foreach ($batches as $batchIndex => $batch) {
            foreach ($batch as $uniqueId) {
                if ($dryRun) {
                    $results['processed']++;
                    $results['successful']++;
                    
                    if ($progressCallback) {
                        $progressCallback([
                            'unique_id' => $uniqueId,
                            'success' => true,
                            'code' => '[DRY RUN]',
                            'message' => 'Would fetch chargeback details'
                        ]);
                    }
                } else {
                    $result = $this->processChargebackDetail($uniqueId);
                    $results['processed']++;
                    
                    $detailEntry = [
                        'unique_id' => $uniqueId,
                        'success' => $result['success']
                    ];
                    
                    if ($result['success']) {
                        $results['successful']++;
                        $detailEntry['code'] = $result['data']['reason_code'] ?? 'N/A';
                        $detailEntry['message'] = $result['data']['reason_description'] ?? 'N/A';
                    } else {
                        $results['failed']++;
                        $results['errors'][$uniqueId] = $result['error'] ?? 'Unknown error';
                        $detailEntry['code'] = $result['code'] ?? 'N/A';
                        $detailEntry['message'] = $result['error'] ?? 'Unknown error';
                    }
                    
                    if ($progressCallback) {
                        $progressCallback($detailEntry);
                    }
                    
                    usleep(self::RATE_LIMIT_DELAY_MS * 1000);
                }
            }
            
            if (!$dryRun) {
                Log::info('EMP Chargeback: Batch processed', [
                    'batch' => $batchIndex + 1,
                    'total_batches' => count($batches),
                    'processed' => $results['processed'],
                ]);
            }
        }
        
        return $results;
    } 
    
    public function updateChargebackDetails(string $uniqueId, array $responseData): bool
    {
        try {
            return DB::transaction(function () use ($uniqueId, $responseData) {
                $billingAttempt = BillingAttempt::where('unique_id', $uniqueId)
                    ->lockForUpdate()
                    ->first();
                    
                if (!$billingAttempt) {
                    return false;
                }

                $reasonCode = $responseData['reason_code'] ?? null;
                $reasonDescription = $responseData['reason_description'] ?? null;
                
                $updateData = [
                    'chargeback_reason_code'        => ($reasonCode === '') ? null : $reasonCode,
                    'chargeback_reason_description' => ($reasonDescription === '') ? null : $reasonDescription,
                ];
                
                if (!$billingAttempt->chargebacked_at) {
                    $updateData['chargebacked_at'] = now();
                }
                
                $billingAttempt->update($updateData);
                return true;
            });
        } catch (\Exception $e) {
            Log::error('EMP Chargeback: Failed to update details', [
                'unique_id' => $uniqueId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
