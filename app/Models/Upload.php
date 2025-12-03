<?php

/**
 * Upload model for CSV file upload records and processing status tracking.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Upload extends Model
{
    use HasFactory, SoftDeletes;

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
        'uploaded_by',
        'column_mapping',
        'meta',
        'error_message',
        'processing_started_at',
        'processing_completed_at',
    ];

    protected $casts = [
        'column_mapping' => 'array',
        'meta' => 'array',
        'file_size' => 'integer',
        'total_records' => 'integer',
        'processed_records' => 'integer',
        'failed_records' => 'integer',
        'processing_started_at' => 'datetime',
        'processing_completed_at' => 'datetime',
    ];

    // Status constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /**
     * @return BelongsTo<User, Upload>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return HasMany<Debtor>
     */
    public function debtors(): HasMany
    {
        return $this->hasMany(Debtor::class);
    }

    /**
     * @return HasMany<VopLog>
     */
    public function vopLogs(): HasMany
    {
        return $this->hasMany(VopLog::class);
    }

    /**
     * @return HasMany<BillingAttempt>
     */
    public function billingAttempts(): HasMany
    {
        return $this->hasMany(BillingAttempt::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function getSuccessRateAttribute(): float
    {
        if ($this->total_records === 0) {
            return 0;
        }

        return round(($this->processed_records - $this->failed_records) / $this->total_records * 100, 2);
    }
}
