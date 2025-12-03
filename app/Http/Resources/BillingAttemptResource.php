<?php

/**
 * API Resource for transforming BillingAttempt model to JSON response.
 */

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BillingAttemptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'debtor_id' => $this->debtor_id,
            'upload_id' => $this->upload_id,
            
            // Transaction identifiers
            'transaction_id' => $this->transaction_id,
            'unique_id' => $this->unique_id,
            
            // Financial
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            
            // Status
            'status' => $this->status,
            'attempt_number' => $this->attempt_number,
            'mid_reference' => $this->mid_reference,
            
            // Error info
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            
            // Flags
            'is_approved' => $this->isApproved(),
            'is_final' => $this->isFinal(),
            'can_retry' => $this->canRetry(),
            
            // Timestamps
            'processed_at' => $this->processed_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            
            // Relations (loaded conditionally)
            'debtor' => new DebtorResource($this->whenLoaded('debtor')),
        ];
    }
}
