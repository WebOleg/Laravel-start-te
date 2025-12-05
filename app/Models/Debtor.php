<?php
/**
 * Debtor model representing individual debt records imported from client files.
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

    protected $fillable = [
        'upload_id',
        'iban',
        'iban_hash',
        'old_iban',
        'bank_name',
        'bank_code',
        'bic',
        'first_name',
        'last_name',
        'email',
        'phone',
        'phone_2',
        'phone_3',
        'phone_4',
        'primary_phone',
        'national_id',
        'birth_date',
        'address',
        'street',
        'street_number',
        'floor',
        'door',
        'apartment',
        'postcode',
        'city',
        'province',
        'country',
        'amount',
        'currency',
        'sepa_type',
        'status',
        'risk_class',
        'iban_valid',
        'name_matched',
        'external_reference',
        'meta',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'birth_date' => 'date',
        'iban_valid' => 'boolean',
        'name_matched' => 'boolean',
        'meta' => 'array',
        'deleted_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_RECOVERED = 'recovered';
    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_RECOVERED,
        self::STATUS_FAILED,
    ];

    public const RISK_LOW = 'low';
    public const RISK_MEDIUM = 'medium';
    public const RISK_HIGH = 'high';

    public const RISK_CLASSES = [
        self::RISK_LOW,
        self::RISK_MEDIUM,
        self::RISK_HIGH,
    ];

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

    public function getIbanMaskedAttribute(): string
    {
        if (strlen($this->iban) < 8) {
            return $this->iban;
        }
        return substr($this->iban, 0, 4) . '****' . substr($this->iban, -4);
    }

    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->street,
            $this->street_number,
            $this->floor ? "Floor {$this->floor}" : null,
            $this->door ? "Door {$this->door}" : null,
            $this->apartment ? "Apt {$this->apartment}" : null,
        ]);
        
        $line1 = implode(' ', $parts);
        $line2Parts = array_filter([$this->postcode, $this->city, $this->province, $this->country]);
        $line2 = implode(', ', $line2Parts);
        
        return trim("{$line1}\n{$line2}");
    }

    public function generateIbanHash(): void
    {
        $this->iban_hash = hash('sha256', strtoupper(str_replace(' ', '', $this->iban)));
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isRecovered(): bool
    {
        return $this->status === self::STATUS_RECOVERED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
