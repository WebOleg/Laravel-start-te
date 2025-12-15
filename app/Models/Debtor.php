<?php

/**
 * Debtor model representing individual debt records.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Debtor extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_RECOVERED = 'recovered';
    public const STATUS_FAILED = 'failed';

    public const VALIDATION_PENDING = 'pending';
    public const VALIDATION_VALID = 'valid';
    public const VALIDATION_INVALID = 'invalid';

    public const RISK_LOW = 'low';
    public const RISK_MEDIUM = 'medium';
    public const RISK_HIGH = 'high';
    public const RISK_CLASSES = [self::RISK_LOW, self::RISK_MEDIUM, self::RISK_HIGH];

    protected $fillable = [
        'upload_id',
        'iban',
        'iban_hash',
        'iban_valid',
        'first_name',
        'last_name',
        'email',
        'phone',
        'address',
        'street',
        'street_number',
        'postcode',
        'city',
        'province',
        'country',
        'amount',
        'currency',
        'status',
        'validation_status',
        'validation_errors',
        'validated_at',
        'risk_class',
        'external_reference',
        'bank_name',
        'bank_code',
        'bic',
        'national_id',
        'birth_date',
        'meta',
        'raw_data',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'iban_valid' => 'boolean',
        'birth_date' => 'date',
        'meta' => 'array',
        'raw_data' => 'array',
        'validation_errors' => 'array',
        'validated_at' => 'datetime',
    ];

    protected $attributes = [
        'currency' => 'EUR',
        'status' => self::STATUS_PENDING,
        'validation_status' => self::VALIDATION_PENDING,
    ];

    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class);
    }

    public function vopLogs(): HasMany
    {
        return $this->hasMany(VopLog::class);
    }

    public function latestVopLog(): HasOne
    {
        return $this->hasOne(VopLog::class)->latestOfMany();
    }

    public function billingAttempts(): HasMany
    {
        return $this->hasMany(BillingAttempt::class);
    }

    public function latestBillingAttempt(): HasOne
    {
        return $this->hasOne(BillingAttempt::class)->latestOfMany();
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function scopeValid($query)
    {
        return $query->where('validation_status', self::VALIDATION_VALID);
    }

    public function scopeInvalid($query)
    {
        return $query->where('validation_status', self::VALIDATION_INVALID);
    }

    public function scopeValidationPending($query)
    {
        return $query->where('validation_status', self::VALIDATION_PENDING);
    }

    public function scopeReadyForSync($query)
    {
        return $query->where('validation_status', self::VALIDATION_VALID)
            ->where('status', self::STATUS_PENDING);
    }

    public function hasValidationErrors(): bool
    {
        return !empty($this->validation_errors);
    }

    public function isReadyForSync(): bool
    {
        return $this->validation_status === self::VALIDATION_VALID
            && $this->status === self::STATUS_PENDING;
    }
}
