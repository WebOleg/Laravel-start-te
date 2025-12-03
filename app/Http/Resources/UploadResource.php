<?php

/**
 * API Resource for transforming Upload model to JSON response.
 */

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UploadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'original_filename' => $this->original_filename,
            'file_size' => $this->file_size,
            'mime_type' => $this->mime_type,
            'status' => $this->status,
            
            // Record counts
            'total_records' => $this->total_records,
            'processed_records' => $this->processed_records,
            'failed_records' => $this->failed_records,
            'success_rate' => $this->success_rate,
            
            // Timestamps
            'processing_started_at' => $this->processing_started_at?->toISOString(),
            'processing_completed_at' => $this->processing_completed_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
            
            // Relations (loaded conditionally)
            'uploader' => $this->whenLoaded('uploader', fn() => [
                'id' => $this->uploader->id,
                'name' => $this->uploader->name,
            ]),
            'debtors_count' => $this->whenCounted('debtors'),
            'debtors' => DebtorResource::collection($this->whenLoaded('debtors')),
        ];
    }
}
