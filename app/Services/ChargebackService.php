<?php

/**
 * Service for Chargebacks.
 */

namespace App\Services;

use App\Models\BillingAttempt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ChargebackService
{
    public function getChargebacks(Request $request)
    {
        $chargebacks = BillingAttempt::with([
                'debtor:id,first_name,last_name,email,iban', 
                'debtor.latestVopLog:vop_logs.id,vop_logs.debtor_id,vop_logs.bank_name,vop_logs.country'
            ])
            ->where('status', BillingAttempt::STATUS_CHARGEBACKED);

        if ($request->has('code'))
        {
            $chargebacks->where('chargeback_reason_code', $request->input('code'));
        }
        $perPage = min((int) $request->input('per_page', 50), 100);
        $chargebacks = $chargebacks->latest()->paginate($perPage);            
        return $chargebacks;
    }

    public function getUniqueChargebacksErrorCodes()
    {
        $cacheKey = 'unique_chargeback_error_codes';
        $ttl = config('tether.cache.ttl_long', 300);
        return Cache::remember($cacheKey, $ttl, function () {
            return BillingAttempt::where('status', BillingAttempt::STATUS_CHARGEBACKED)
                ->distinct()
                ->orderBy('chargeback_reason_code', 'asc')
                ->pluck('chargeback_reason_code')
                ->filter()
                ->values()
                ->all();
        });
    }}