<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BavBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'filename' => $this->original_filename,
            'status' => $this->status,
            'total_records' => $this->total_records,
            'record_limit' => $this->record_limit,
            'processed_records' => $this->processed_records,
            'success_count' => $this->success_count,
            'failed_count' => $this->failed_count,
            'credits_used' => $this->credits_used,
            'progress' => $this->getProgress(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
