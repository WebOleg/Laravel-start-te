<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DebtorProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'billing_model' => $this->billing_model,
            'is_active' => $this->is_active,

            'iban_masked' => $this->iban_masked,

            'billing_amount' => $this->billing_amount,
            'lifetime_charged_amount' => $this->lifetime_charged_amount,
            'currency' => $this->currency,

            'last_billed_at' => $this->last_billed_at?->toISOString(),
            'last_success_at' => $this->last_success_at?->toISOString(),
            'next_bill_at' => $this->next_bill_at?->toISOString(),

            // Optional meta
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
