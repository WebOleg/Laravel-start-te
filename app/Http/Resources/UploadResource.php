<?php

/**
 * API resource for Upload model.
 */

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UploadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $meta = $this->meta ?? [];

        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'original_filename' => $this->original_filename,
            'file_size' => $this->file_size,
            'mime_type' => $this->mime_type,
            'status' => $this->status,
            'total_records' => $this->total_records,
            'processed_records' => $this->processed_records,
            'failed_records' => $this->failed_records,
            'success_rate' => $this->success_rate,
            'headers' => $this->headers,
            'processing_started_at' => $this->processing_started_at?->toISOString(),
            'processing_completed_at' => $this->processing_completed_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
            'debtors_count' => $this->whenCounted('debtors'),
            'valid_count' => $this->when(isset($this->valid_count), $this->valid_count),
            'invalid_count' => $this->when(isset($this->invalid_count), $this->invalid_count),
            'skipped' => $this->when(isset($meta['skipped']), $meta['skipped'] ?? null),
            'skipped_rows' => $this->when(isset($meta['skipped_rows']), $meta['skipped_rows'] ?? null),
            'uploader' => new UserResource($this->whenLoaded('uploader')),
            'is_deletable' => $this->isDeletable(),
        ];
    }
}
