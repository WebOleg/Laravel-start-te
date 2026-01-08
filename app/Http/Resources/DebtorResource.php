<?php
/**
 * API resource for Debtor model.
 */
namespace App\Http\Resources;

use App\Services\IbanValidator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DebtorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $ibanValidator = app(IbanValidator::class);

        $bankReference = $this->whenLoaded('latestVopLog', function () {
            return $this->latestVopLog?->bankReference;
        });

        return [
            'id' => $this->id,
            'upload_id' => $this->upload_id,
            'iban_masked' => $this->iban ? $ibanValidator->mask($this->iban) : null,
            'iban_valid' => $this->iban_valid,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'street' => $this->street,
            'street_number' => $this->street_number,
            'postcode' => $this->postcode,
            'city' => $this->city,
            'province' => $this->province,
            'country' => $this->country,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'validation_status' => $this->validation_status,
            'validation_errors' => $this->validation_errors,
            'validated_at' => $this->validated_at?->toISOString(),
            'vop_status' => $this->vop_status,
            'vop_match' => $this->vop_match,
            'vop_verified_at' => $this->vop_verified_at?->toISOString(),
            'bav_selected' => $this->bav_selected,
            'risk_class' => $this->risk_class,
            'external_reference' => $this->external_reference,
            'bank_name' => $this->bank_name,
            'bic' => $this->bic,
            'raw_data' => $this->raw_data,
            'bank_name_reference' => $this->getBankNameReference($bankReference),
            'bank_country_iso_reference' => $this->getBankCountryReference($bankReference),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
            'upload' => new UploadResource($this->whenLoaded('upload')),
            'latest_vop' => new VopLogResource($this->whenLoaded('latestVopLog')),
            'latest_billing' => new BillingAttemptResource($this->whenLoaded('latestBillingAttempt')),
        ];
    }

    private function getBankNameReference($bankReference): ?string
    {
        if ($bankReference instanceof \App\Models\BankReference) {
            return $bankReference->bank_name;
        }

        if ($this->relationLoaded('latestVopLog') && $this->latestVopLog) {
            return $this->latestVopLog->bank_name;
        }

        return null;
    }

    private function getBankCountryReference($bankReference): ?string
    {
        if ($bankReference instanceof \App\Models\BankReference) {
            return $bankReference->country_iso;
        }

        if ($this->relationLoaded('latestVopLog') && $this->latestVopLog) {
            return $this->latestVopLog->country;
        }

        return null;
    }
}
