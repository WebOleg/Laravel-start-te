<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DescriptorResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'year' => $this->year,
            'month' => $this->month,
            'descriptor_name' => $this->descriptor_name,
            'descriptor_city' => $this->descriptor_city,
            'descriptor_country' => $this->descriptor_country,
            'is_default' => $this->is_default,
            'emp_account' => $this->empAccount ? [
                'id' => $this->empAccount->id,
                'name' => $this->empAccount->name,
                'slug' => $this->empAccount->slug,
            ] : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
