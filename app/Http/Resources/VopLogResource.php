<?php
/**
 * API Resource for transforming VopLog model to JSON response.
 */
namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VopLogResource extends JsonResource
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

            'iban_masked' => $this->iban_masked,
            'iban_valid' => $this->iban_valid,

            'bank_identified' => $this->bank_identified,
            'bank_name' => $this->bank_name,
            'bic' => $this->bic,
            'country' => $this->country,

            'vop_score' => $this->vop_score,
            'score_label' => $this->score_label,
            'result' => $this->result,

            'name_match' => $this->name_match,
            'name_match_score' => $this->name_match_score,
            'bav_verified' => $this->bav_verified,

            'is_positive' => $this->isPositive(),
            'is_negative' => $this->isNegative(),
            'has_name_match' => $this->hasNameMatch(),

            'created_at' => $this->created_at->toISOString(),

            'debtor' => new DebtorResource($this->whenLoaded('debtor')),
        ];
    }
}
