<?php

/**
 * Service for handling chargeback transactions from emerchantpay.
 * Fetches chargeback details and updates billing attempts with reason codes, description.
 */

namespace App\Services\Emp;

use App\Models\BillingAttempt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class EmpChargebackService
{
    private EmpClient $client;

    public const BATCH_SIZE = 100;
    public const RATE_LIMIT_DELAY_MS = 500;

    public function __construct(EmpClient $client)
    {
        $this->client = $client;
    }

    public function processChargebackDetail(string $uniqueId): array
    {
        try {
            $xml = $this->client->buildChargebackDetailXml($uniqueId);
            $response = $this->client->sendRequest('/chargebacks', $xml);
            
            if (empty($response)) {
                Log::warning('EMP Chargeback: empty response received: ', [
                    'unique_id' => $uniqueId
                ]);
                return ['success' => false, 'error' => 'Empty response from EMP API'];
            }
            
            // Check if the response indicates an error
            if (isset($response['status']) && $response['status'] === 'error') {
                $errorCode = $response['code'] ?? 'unknown';
                $errorMessage = $response['message'] ?? 'Unknown error';
                $technicalMessage = $response['technical_message'] ?? '';
                
                Log::error('EMP Chargeback: Error response from API', [
                    'unique_id' => $uniqueId,
                    'error_code' => $errorCode,
                    'message' => $errorMessage,
                    'technical_message' => $technicalMessage
                ]);
                
                return ['success' => false, 'error' => $errorMessage, 'code' => $errorCode];
            }
            
            // Process successful response
            $updated = $this->updateChargebackDetails($uniqueId, $response);
            
            if (!$updated) {
                Log::error('Failed to update chargeback details', [
                    'unique_id' => $uniqueId,
                    'response' => $response
                ]);
            }
            
            return ['success' => $updated, 'data' => $response];
            
        } catch (\Exception $e) {
            Log::error('Exception while processing chargeback detail', [
                'unique_id' => $uniqueId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function processBulkChargebackDetail(bool $isAll, ?callable $progressCallback = null): array
    {
        $query = BillingAttempt::where('status', BillingAttempt::STATUS_CHARGEBACKED);
        if ($isAll === false) {
            $query = $query->whereNull('chargeback_reason_code');
        }
        $uniqueIds = $query->pluck('unique_id')->toArray();
        
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
                $result = $this->processChargebackDetail($uniqueId);
                $results['processed']++;
                
                // Prepare detail entry for callback
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
                
                // Call progress callback immediately after processing each item
                if ($progressCallback) {
                    $progressCallback($detailEntry);
                }
                
                // Rate limiting: sleep between requests
                usleep(self::RATE_LIMIT_DELAY_MS * 1000);
            }
            
            Log::info('EMP Chargeback: Batch processed', [
                'batch' => $batchIndex + 1,
                'total_batches' => count($batches),
                'processed' => $results['processed'],
                'successful' => $results['successful'],
                'failed' => $results['failed']
            ]);
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
                
                $updateData = [
                    'chargeback_reason_code' => $responseData['reason_code'] ?? null,
                    'chargeback_reason_description' => $responseData['reason_description'] ?? null,
                ];
                
                // Only set chargebacked_at if it's not already set
                if (!$billingAttempt->chargebacked_at) {
                    $updateData['chargebacked_at'] = now();
                }
                
                $billingAttempt->update($updateData);
                return true;
            });
        } catch (\Exception $e) {
            Log::error('Failed to update chargeback details in transaction', [
                'unique_id' => $uniqueId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}