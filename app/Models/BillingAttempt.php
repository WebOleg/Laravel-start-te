<?php

/**
 * BillingAttempt model for storing SEPA Direct Debit transaction attempts.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'debtor_id',
        'upload_id',
        'transaction_id',
        'unique_id',
        'amount',
        'currency',
        'status',
        'attempt_number',
        'mid_reference',
        'error_code',
        'error_message',
        'technical_message',
        'request_payload',
        'response_payload',
        'meta',
        'processed_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'amount' => 'decimal:2',
        'attempt_number' => 'integer',
        'processed_at' => 'datetime',
    ];

    protected $hidden = [
        'request_payload',
        'response_payload',
    ];

    // Status constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_ERROR = 'error';
    public const STATUS_VOIDED = 'voided';

    /**
     * @return BelongsTo<Debtor, BillingAttempt>
     */
    public function debtor(): BelongsTo
    {
        return $this->belongsTo(Debtor::class);
    }

    /**
     * @return BelongsTo<Upload, BillingAttempt>
     */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isDeclined(): bool
    {
        return $this->status === self::STATUS_DECLINED;
    }

    public function isError(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }

    public function isVoided(): bool
    {
        return $this->status === self::STATUS_VOIDED;
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [
            self::STATUS_APPROVED,
            self::STATUS_VOIDED,
        ]);
    }

    public function canRetry(): bool
    {
        return in_array($this->status, [
            self::STATUS_DECLINED,
            self::STATUS_ERROR,
        ]);
    }
}
