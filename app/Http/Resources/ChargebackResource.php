<?php

/**
 * Resource for Chargeback model.
 * Reads from chargebacks table with billing_attempt data via relation.
 */

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChargebackResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $billingAttempt = $this->billingAttempt;

        return [
            'id'             => $this->id,
            'error_code'     => $this->reason_code,
            'error_message'  => $this->reason_description,
            'amount'         => (float) $this->chargeback_amount,
            'currency'       => $this->chargeback_currency,
            'bank_name'      => $this->debtor?->latestVopLog?->bank_name,
            'bank_country'   => $this->debtor?->latestVopLog?->country,
            'processed_at'   => $billingAttempt?->processed_at,
            'emp_created_at' => $billingAttempt?->emp_created_at,
            'chargebacked_at' => $billingAttempt?->chargebacked_at,
            'transaction_id' => $billingAttempt?->transaction_id,
            'debtor'         => $this->debtor ? [
                'id'         => $this->debtor->id,
                'first_name' => $this->debtor->first_name,
                'last_name'  => $this->debtor->last_name,
                'email'      => $this->debtor->email,
                'iban'       => $this->debtor->iban ?? null,
            ] : null,
            'emp_account'    => $billingAttempt?->empAccount ? [
                'id'   => $billingAttempt->empAccount->id,
                'name' => $billingAttempt->empAccount->name,
                'slug' => $billingAttempt->empAccount->slug,
            ] : null,
        ];
    }
}
