<?php
namespace App\Http\Resources;

use App\Services\IbanValidator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChargebackResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $ibanValidator = app(IbanValidator::class);

        return [
            'id' => $this->id,
            'error_code' => $this->chargeback_reason_code,
            'error_message' => $this->chargeback_reason_description,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'bank_name' => $this->debtor?->latestVopLog?->bank_name,
            'bank_country' => $this->debtor?->latestVopLog?->country,
            'processed_at' => $this->processed_at,
            'emp_created_at' => $this->emp_created_at,
            'chargebacked_at' => $this->chargebacked_at,
            'debtor' => $this->debtor ? [
                'id' => $this->debtor->id,
                'first_name' => $this->debtor->first_name,
                'last_name' => $this->debtor->last_name,
                'email' => $this->debtor->email,
                'iban' => $this->debtor->iban ? $ibanValidator->mask($this->debtor->iban) : null,
            ] : null,
            'emp_account' => $this->empAccount ? [
                'id' => $this->empAccount->id,
                'name' => $this->empAccount->name,
                'slug' => $this->empAccount->slug,
            ] : null,
        ];
    }
}
