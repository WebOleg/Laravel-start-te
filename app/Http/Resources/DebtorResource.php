<?php

/**
 * API Resource for transforming Debtor model to JSON response.
 */

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DebtorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'upload_id' => $this->upload_id,
            
            // Personal info (IBAN masked for security)
            'iban_masked' => $this->masked_iban,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            
            // Address
            'address' => $this->address,
            'zip_code' => $this->zip_code,
            'city' => $this->city,
            'country' => $this->country,
            
            // Financial
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            
            // Status
            'status' => $this->status,
            'risk_class' => $this->risk_class,
            'external_reference' => $this->external_reference,
            
            // Timestamps
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
            
            // Relations (loaded conditionally)
            'upload' => new UploadResource($this->whenLoaded('upload')),
            'latest_vop' => new VopLogResource($this->whenLoaded('latestVopLog')),
            'latest_billing' => new BillingAttemptResource($this->whenLoaded('latestBillingAttempt')),
            'vop_logs' => VopLogResource::collection($this->whenLoaded('vopLogs')),
            'billing_attempts' => BillingAttemptResource::collection($this->whenLoaded('billingAttempts')),
        ];
    }
}
