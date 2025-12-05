<?php
/**
 * API Resource for Debtor model.
 */
namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DebtorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'upload_id' => $this->upload_id,
            
            // IBAN & Bank
            'iban_masked' => $this->iban_masked,
            'bank_name' => $this->bank_name,
            'bank_code' => $this->bank_code,
            'bic' => $this->bic,
            
            // Personal
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'primary_phone' => $this->primary_phone,
            'national_id' => $this->national_id,
            'birth_date' => $this->birth_date?->format('Y-m-d'),
            
            // Address
            'street' => $this->street,
            'street_number' => $this->street_number,
            'floor' => $this->floor,
            'door' => $this->door,
            'apartment' => $this->apartment,
            'postcode' => $this->postcode,
            'city' => $this->city,
            'province' => $this->province,
            'country' => $this->country,
            'full_address' => $this->full_address,
            
            // Financial
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'sepa_type' => $this->sepa_type,
            
            // Status
            'status' => $this->status,
            'risk_class' => $this->risk_class,
            'iban_valid' => $this->iban_valid,
            'name_matched' => $this->name_matched,
            
            // Reference
            'external_reference' => $this->external_reference,
            
            // Timestamps
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
