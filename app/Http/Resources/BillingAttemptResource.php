<?php

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

            'transaction_id' => $this->transaction_id,
            'unique_id' => $this->unique_id,

            'amount' => (float) $this->amount,
            'currency' => $this->currency,

            'status' => $this->status,
            'attempt_number' => $this->attempt_number,
            'mid_reference' => $this->mid_reference,

            'error_code' => $this->error_code,
            'error_message' => $this->error_message,

            'is_approved' => $this->isApproved(),
            'is_final' => $this->isFinal(),
            'can_retry' => $this->canRetry(),

            'emp_created_at' => $this->emp_created_at?->toISOString(),
            'processed_at' => $this->processed_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),

            'debtor' => new DebtorResource($this->whenLoaded('debtor')),

            'emp_account' => $this->empAccount ? [
                'id' => $this->empAccount->id,
                'name' => $this->empAccount->name,
                'slug' => $this->empAccount->slug,
            ] : null,
        ];
    }
}
