<?php

/**
 * BillingAttempt model for SEPA Direct Debit transaction attempts.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BillingAttempt extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_ERROR = 'error';
    public const STATUS_VOIDED = 'voided';
    public const STATUS_CHARGEBACKED = 'chargebacked';

    public const IN_FLIGHT_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_DECLINED,
        self::STATUS_ERROR,
    ];

    public const FINAL_STATUSES = [
        self::STATUS_APPROVED,
        self::STATUS_VOIDED,
        self::STATUS_CHARGEBACKED,
    ];

    public const RETRIABLE_STATUSES = [
        self::STATUS_DECLINED,
        self::STATUS_ERROR,
    ];

    public const RECONCILIATION_MIN_AGE_HOURS = 2;
    public const RECONCILIATION_MAX_ATTEMPTS = 10;

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
        'bic',
        'error_code',
        'error_message',
        'technical_message',
        'request_payload',
        'response_payload',
        'meta',
        'processed_at',
        'emp_created_at',
        'last_reconciled_at',
        'reconciliation_attempts',
        'debtor_profile_id',
        'billing_model',
        'cycle_anchor',
        'source',
        'chargeback_reason_code',
        'chargeback_reason_description',
        'chargebacked_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'amount' => 'decimal:2',
        'attempt_number' => 'integer',
        'reconciliation_attempts' => 'integer',
        'processed_at' => 'datetime',
        'emp_created_at' => 'datetime',
        'last_reconciled_at' => 'datetime',
        'cycle_anchor' => 'date',
        'chargebacked_at' => 'datetime',
    ];

    protected $hidden = [
        'request_payload',
        'response_payload',
    ];

    public function debtor(): BelongsTo
    {
        return $this->belongsTo(Debtor::class);
    }

    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class);
    }

    public function debtorProfile(): BelongsTo
    {
        return $this->belongsTo(DebtorProfile::class);
    }

    public function chargeback(): HasOne
    {
        return $this->hasOne(Chargeback::class);
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

    public function isChargebacked(): bool
    {
        return $this->status === self::STATUS_CHARGEBACKED;
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL_STATUSES);
    }

    public function canRetry(): bool
    {
        return in_array($this->status, self::RETRIABLE_STATUSES);
    }

    public function canReconcile(): bool
    {
        if (!$this->isPending()) {
            return false;
        }

        if (empty($this->unique_id)) {
            return false;
        }

        if ($this->reconciliation_attempts >= self::RECONCILIATION_MAX_ATTEMPTS) {
            return false;
        }

        return true;
    }

    public function needsReconciliation(): bool
    {
        if (!$this->canReconcile()) {
            return false;
        }

        $minAge = now()->subHours(self::RECONCILIATION_MIN_AGE_HOURS);
        if ($this->created_at > $minAge) {
            return false;
        }

        return true;
    }

    public function markReconciled(): void
    {
        $this->update([
            'last_reconciled_at' => now(),
            'reconciliation_attempts' => $this->reconciliation_attempts + 1,
        ]);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeNeedsReconciliation($query)
    {
        $minAge = now()->subHours(self::RECONCILIATION_MIN_AGE_HOURS);

        return $query
            ->where('status', self::STATUS_PENDING)
            ->whereNotNull('unique_id')
            ->where('created_at', '<', $minAge)
            ->where('reconciliation_attempts', '<', self::RECONCILIATION_MAX_ATTEMPTS);
    }

    public function scopeStale($query, int $hours = 48)
    {
        return $query
            ->where('status', self::STATUS_PENDING)
            ->where('created_at', '<', now()->subHours($hours));
    }
}
