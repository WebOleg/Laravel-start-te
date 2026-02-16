<?php
/**
 * Model for standalone BAV verification batches.
 * Tracks upload, processing status, and results for CSV-based BAV checks.
 */
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Bus;

class BavBatch extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'original_filename',
        'file_path',
        'results_path',
        'status',
        'total_records',
        'record_limit',
        'processed_records',
        'success_count',
        'failed_count',
        'credits_used',
        'batch_id',
        'column_mapping',
        'meta',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'total_records' => 'integer',
        'record_limit' => 'integer',
        'processed_records' => 'integer',
        'success_count' => 'integer',
        'failed_count' => 'integer',
        'credits_used' => 'integer',
        'column_mapping' => 'array',
        'meta' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getEffectiveLimit(): int
    {
        return $this->record_limit ?? $this->total_records;
    }

    public function getProgress(): array
    {
        $effectiveLimit = $this->getEffectiveLimit();

        return [
            'status' => $this->status,
            'total' => $this->total_records,
            'record_limit' => $this->record_limit,
            'effective_limit' => $effectiveLimit,
            'processed' => $this->processed_records,
            'success' => $this->success_count,
            'failed' => $this->failed_count,
            'credits_used' => $this->credits_used,
            'percentage' => $effectiveLimit > 0
                ? round(($this->processed_records / $effectiveLimit) * 100, 1)
                : 0,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }

    public function markProcessing(string $batchId): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
            'batch_id' => $batchId,
            'started_at' => now(),
        ]);
    }

    public function markCompleted(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $error = null): void
    {
        $meta = $this->meta ?? [];
        if ($error) {
            $meta['error'] = $error;
        }

        $this->update([
            'status' => self::STATUS_FAILED,
            'completed_at' => now(),
            'meta' => $meta,
        ]);
    }

    public function incrementProcessed(bool $success = true): void
    {
        $this->increment('processed_records');
        $this->increment('credits_used');

        if ($success) {
            $this->increment('success_count');
        } else {
            $this->increment('failed_count');
        }
    }

    public function isProcessing(): bool
    {
        if ($this->status !== self::STATUS_PROCESSING) {
            return false;
        }

        if ($this->batch_id) {
            $batch = Bus::findBatch($this->batch_id);
            if ($batch && $batch->finished()) {
                $this->markCompleted();
                return false;
            }
        }

        return true;
    }
}
