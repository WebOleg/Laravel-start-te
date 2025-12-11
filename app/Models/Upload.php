<?php

/**
 * Upload model for tracking file uploads.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Upload extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'filename',
        'original_filename',
        'file_path',
        'file_size',
        'mime_type',
        'status',
        'total_records',
        'processed_records',
        'failed_records',
        'column_mapping',
        'headers',
        'processing_started_at',
        'processing_completed_at',
        'uploaded_by',
        'meta',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'total_records' => 'integer',
        'processed_records' => 'integer',
        'failed_records' => 'integer',
        'column_mapping' => 'array',
        'headers' => 'array',
        'meta' => 'array',
        'processing_started_at' => 'datetime',
        'processing_completed_at' => 'datetime',
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function debtors(): HasMany
    {
        return $this->hasMany(Debtor::class);
    }

    public function getSuccessRateAttribute(): float
    {
        if ($this->processed_records === 0) {
            return 0;
        }

        $successful = $this->processed_records - $this->failed_records;
        return round(($successful / $this->processed_records) * 100, 1);
    }
}
