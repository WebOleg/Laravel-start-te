<?php

/**
 * Service for handling chargeback transactions from emerchantpay.
 * Fetches chargeback details and updates billing attempts with reason codes, decription.
 */

namespace App\Services\Emp;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class EmpChargebackService
{
    private EmpClient $client;

    public const BATCH_SIZE = 100;
    public const RATE_LIMIT_DELAY_MS = 50;

    public function __construct(EmpClient $client)
    {
        $this->client = $client;
    }

    public function processChargebackDetail($unique_id): array
    {
        $xml = $this->client->buildChargebackDetailXml($unique_id);
        $response = $this->client->sendRequest('/chargebacks/' . $this->client->getTerminalToken(), $xml);
        return $response;
    }

    public function processBulkChargebackDetail($isAll) : array
    {
        $query = BillingAttempt::where('status', BillingAttempt::STATUS_CHARGEBACKED);
        if($isAll === false){
            $query = $query->whereNull('chargeback_reason_code');
        }
        $chargebacks = $query->pluck('unique_id')->toArray();

        return $chargebacks;
    } 
    
}