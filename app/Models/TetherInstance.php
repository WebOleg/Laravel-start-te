<?php

/**
 * Tether Instance model.
 * Acquirer-agnostic instance replacing direct EMP account coupling.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TetherInstance extends Model
{
    use HasFactory;

    public const ACQUIRER_EMP = 'emp';
    public const ACQUIRER_FINXP = 'finxp';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_ONBOARDING = 'onboarding';

    protected $fillable = [
        'name',
        'slug',
        'acquirer_type',
        'acquirer_account_id',
        'acquirer_config',
        'proxy_ip',
        'queue_prefix',
        'is_active',
        'status',
        'sort_order',
        'notes',
    ];

    protected $casts = [
        'acquirer_config' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Linked EMP account (nullable for non-EMP acquirers).
     */
    public function acquirerAccount(): BelongsTo
    {
        return $this->belongsTo(EmpAccount::class, 'acquirer_account_id');
    }

    public function debtors(): HasMany
    {
        return $this->hasMany(Debtor::class);
    }

    public function debtorProfiles(): HasMany
    {
        return $this->hasMany(DebtorProfile::class);
    }

    public function uploads(): HasMany
    {
        return $this->hasMany(Upload::class);
    }

    public function billingAttempts(): HasMany
    {
        return $this->hasMany(BillingAttempt::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    public function scopeForAcquirer($query, string $type)
    {
        return $query->where('acquirer_type', $type);
    }

    public function isEmp(): bool
    {
        return $this->acquirer_type === self::ACQUIRER_EMP;
    }

    /**
     * Get queue name for job routing.
     * Returns e.g. 'billing:1', 'vop:1'
     */
    public function getQueueName(string $jobType): string
    {
        $prefix = $this->queue_prefix ?? $this->slug;

        return "{$jobType}:{$this->id}";
    }
}
