<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class DebtorProfile extends Model
{
    use HasFactory;

    public const MAX_AMOUNT_LIMIT = 750;

    public const MODEL_LEGACY = 'legacy';
    public const MODEL_FLYWHEEL = 'flywheel';
    public const MODEL_RECOVERY = 'recovery';
    public const ALL = 'all';

    public const BILLING_MODELS = [
        self::MODEL_LEGACY,
        self::MODEL_FLYWHEEL,
        self::MODEL_RECOVERY,
    ];

    protected $fillable = [
        'iban_hash',
        'iban_masked',
        'billing_model',
        'is_active',
        'billing_amount',
        'currency',
        'last_billed_at',
        'last_success_at',
        'lifetime_charged_amount',
        'next_bill_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'billing_amount' => 'decimal:2',
        'lifetime_charged_amount' => 'decimal:2',
        'last_billed_at' => 'datetime',
        'last_success_at' => 'datetime',
        'next_bill_at' => 'datetime',
    ];

    protected $attributes = [
        'billing_model' => self::MODEL_LEGACY,
        'is_active' => true,
        'currency' => 'EUR',
    ];

    public function debtors(): HasMany
    {
        return $this->hasMany(Debtor::class);
    }

    public function billingAttempts(): HasMany
    {
        return $this->hasMany(BillingAttempt::class);
    }

    /**
     * Calculate the Next Bill Date based on the Model Type.
     */
    public static function calculateNextBillDate(string $model): Carbon
    {
        return match ($model) {
            // Flywheel: Retention every 90 days
            self::MODEL_FLYWHEEL => now()->addDays(90),
            // Recovery: Billed every 6 months
            self::MODEL_RECOVERY => now()->addMonths(6),
            // Legacy: immediate
            default => now(),
        };
    }

    /**
     * Increment the lifetime revenue stats.
     */
    public function addLifetimeRevenue(float $amount): void
    {
        $this->lifetime_charged_amount = ($this->lifetime_charged_amount ?? 0) + $amount;
        $this->save();
    }

    /**
     * Decrement the lifetime revenue stats (e.g. Chargeback).
     */
    public function deductLifetimeRevenue(float $amount): void
    {
        $current = $this->lifetime_charged_amount ?? 0;
        // Prevent negative lifetime value if data was out of sync
        $this->lifetime_charged_amount = max(0, $current - $amount);
        $this->save();
    }


    /**
     * Scope profiles that are due for billing.
     */
    public function scopeDue($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->where('next_bill_at', '<=', now())
                    ->orWhereNull('next_bill_at');
            });
    }

    /**
     * Scope profiles that have not exceeded the lifetime cap.
     */
    public function scopeUnderLifetimeCap($query)
    {
        return $query->where(function ($q) {
            $q->where('lifetime_charged_amount', '<', self::MAX_AMOUNT_LIMIT)
                ->orWhereNull('lifetime_charged_amount');
        });
    }

    /**
     * Check if the profile has at least one valid and verified debtor.
     */
    public function scopeHasVerifiedDebtor($query)
    {
        return $query->whereHas('debtors', function ($q) {
            $q->where('validation_status', \App\Models\Debtor::VALIDATION_VALID)
                ->where('vop_status', \App\Models\Debtor::VOP_VERIFIED);
        });
    }
}
