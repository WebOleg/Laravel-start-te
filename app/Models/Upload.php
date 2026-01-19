<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Bus;

class Upload extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public const JOB_IDLE = 'idle';
    public const JOB_PROCESSING = 'processing';
    public const JOB_COMPLETED = 'completed';
    public const JOB_FAILED = 'failed';

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
        // Merged from HEAD
        'billing_model',
        // Merged from main
        'validation_status',
        'validation_batch_id',
        'validation_started_at',
        'validation_completed_at',
        'billing_status',
        'billing_batch_id',
        'billing_started_at',
        'billing_completed_at',
        'vop_status',
        'vop_batch_id',
        'vop_started_at',
        'vop_completed_at',
        'reconciliation_status',
        'reconciliation_batch_id',
        'reconciliation_started_at',
        'reconciliation_completed_at',
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
        'validation_started_at' => 'datetime',
        'validation_completed_at' => 'datetime',
        'billing_started_at' => 'datetime',
        'billing_completed_at' => 'datetime',
        'vop_started_at' => 'datetime',
        'vop_completed_at' => 'datetime',
        'reconciliation_started_at' => 'datetime',
        'reconciliation_completed_at' => 'datetime',
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function debtors(): HasMany
    {
        return $this->hasMany(Debtor::class);
    }

    public function billingAttempts(): HasMany
    {
        return $this->hasMany(BillingAttempt::class);
    }

    public function getSuccessRateAttribute(): float
    {
        if ($this->processed_records === 0) {
            return 0;
        }
        $successful = $this->processed_records - $this->failed_records;
        return round(($successful / $this->processed_records) * 100, 1);
    }

    public function canBeSoftDeleted(): bool
    {
        return !$this->billingAttempts()->exists();
    }

    public function canBeHardDeleted(): bool
    {
        return !$this->debtors()->exists();
    }

    public function isDeletable(): bool
    {
        return $this->canBeSoftDeleted() || $this->canBeHardDeleted();
    }

    // Validation job tracking
    public function isValidationProcessing(): bool
    {
        if ($this->validation_status !== self::JOB_PROCESSING) {
            return false;
        }

        if ($this->validation_batch_id) {
            $batch = Bus::findBatch($this->validation_batch_id);
            if ($batch && $batch->finished()) {
                $this->markValidationCompleted();
                return false;
            }
        }

        return true;
    }

    public function startValidation(string $batchId): void
    {
        $this->update([
            'validation_status' => self::JOB_PROCESSING,
            'validation_batch_id' => $batchId,
            'validation_started_at' => now(),
            'validation_completed_at' => null,
        ]);
    }

    public function markValidationCompleted(): void
    {
        $this->update([
            'validation_status' => self::JOB_COMPLETED,
            'validation_completed_at' => now(),
        ]);
    }

    public function markValidationFailed(): void
    {
        $this->update([
            'validation_status' => self::JOB_FAILED,
            'validation_completed_at' => now(),
        ]);
    }

    // Billing job tracking
    public function isBillingProcessing(): bool
    {
        if ($this->billing_status !== self::JOB_PROCESSING) {
            return false;
        }

        if ($this->billing_batch_id) {
            $batch = Bus::findBatch($this->billing_batch_id);
            if ($batch && $batch->finished()) {
                $this->markBillingCompleted();
                return false;
            }
        }

        return true;
    }

    public function startBilling(string $batchId): void
    {
        $this->update([
            'billing_status' => self::JOB_PROCESSING,
            'billing_batch_id' => $batchId,
            'billing_started_at' => now(),
            'billing_completed_at' => null,
        ]);
    }

    public function markBillingCompleted(): void
    {
        $this->update([
            'billing_status' => self::JOB_COMPLETED,
            'billing_completed_at' => now(),
        ]);
    }

    public function markBillingFailed(): void
    {
        $this->update([
            'billing_status' => self::JOB_FAILED,
            'billing_completed_at' => now(),
        ]);
    }

    // VOP job tracking
    public function isVopProcessing(): bool
    {
        if ($this->vop_status !== self::JOB_PROCESSING) {
            return false;
        }

        if ($this->vop_batch_id) {
            $batch = Bus::findBatch($this->vop_batch_id);
            if ($batch && $batch->finished()) {
                $this->markVopCompleted();
                return false;
            }
        }

        return true;
    }

    public function startVop(string $batchId): void
    {
        $this->update([
            'vop_status' => self::JOB_PROCESSING,
            'vop_batch_id' => $batchId,
            'vop_started_at' => now(),
            'vop_completed_at' => null,
        ]);
    }

    public function markVopCompleted(): void
    {
        $this->update([
            'vop_status' => self::JOB_COMPLETED,
            'vop_completed_at' => now(),
        ]);
    }

    public function markVopFailed(): void
    {
        $this->update([
            'vop_status' => self::JOB_FAILED,
            'vop_completed_at' => now(),
        ]);
    }

    // Reconciliation job tracking
    public function isReconciliationProcessing(): bool
    {
        if ($this->reconciliation_status !== self::JOB_PROCESSING) {
            return false;
        }

        if ($this->reconciliation_batch_id) {
            $batch = Bus::findBatch($this->reconciliation_batch_id);
            if ($batch && $batch->finished()) {
                $this->markReconciliationCompleted();
                return false;
            }
        }

        return true;
    }

    public function startReconciliation(string $batchId): void
    {
        $this->update([
            'reconciliation_status' => self::JOB_PROCESSING,
            'reconciliation_batch_id' => $batchId,
            'reconciliation_started_at' => now(),
            'reconciliation_completed_at' => null,
        ]);
    }

    public function markReconciliationCompleted(): void
    {
        $this->update([
            'reconciliation_status' => self::JOB_COMPLETED,
            'reconciliation_completed_at' => now(),
        ]);
    }

    public function markReconciliationFailed(): void
    {
        $this->update([
            'reconciliation_status' => self::JOB_FAILED,
            'reconciliation_completed_at' => now(),
        ]);
    }
}
