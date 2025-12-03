<?php

/**
 * Debtor model for storing debtor records imported from CSV uploads.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Debtor extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'upload_id',
        'iban',
        'iban_hash',
        'first_name',
        'last_name',
        'email',
        'phone',
        'address',
        'zip_code',
        'city',
        'country',
        'amount',
        'currency',
        'status',
        'risk_class',
        'external_reference',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'amount' => 'decimal:2',
    ];

    protected $hidden = [
        'iban',
    ];

    // Status constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_RECOVERED = 'recovered';
    public const STATUS_FAILED = 'failed';

    // Risk class constants
    public const RISK_LOW = 'low';
    public const RISK_MEDIUM = 'medium';
    public const RISK_HIGH = 'high';

    /**
     * @return BelongsTo<Upload, Debtor>
     */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class);
    }

    /**
     * @return HasMany<VopLog>
     */
    public function vopLogs(): HasMany
    {
        return $this->hasMany(VopLog::class);
    }

    /**
     * @return HasOne<VopLog>
     */
    public function latestVopLog(): HasOne
    {
        return $this->hasOne(VopLog::class)->latestOfMany();
    }

    /**
     * @return HasMany<BillingAttempt>
     */
    public function billingAttempts(): HasMany
    {
        return $this->hasMany(BillingAttempt::class);
    }

    /**
     * @return HasOne<BillingAttempt>
     */
    public function latestBillingAttempt(): HasOne
    {
        return $this->hasOne(BillingAttempt::class)->latestOfMany();
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function getMaskedIbanAttribute(): string
    {
        if (strlen($this->iban) < 8) {
            return '****';
        }

        return substr($this->iban, 0, 4) . '****' . substr($this->iban, -4);
    }

    public function setIbanAttribute(string $value): void
    {
        $this->attributes['iban'] = strtoupper(str_replace(' ', '', $value));
        $this->attributes['iban_hash'] = hash('sha256', $this->attributes['iban']);
    }
}
