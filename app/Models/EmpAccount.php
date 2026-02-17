<?php

/**
 * EMP Merchant Account model.
 * Stores credentials for emerchantpay payment terminals.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class EmpAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'endpoint',
        'username',
        'password',
        'terminal_token',
        'is_active',
        'sort_order',
        'notes',
        'monthly_cap',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'monthly_cap' => 'decimal:2',
    ];

    protected $hidden = [
        'username',
        'password',
        'terminal_token',
    ];

    /**
     * Encrypt username before saving.
     */
    public function setUsernameAttribute(string $value): void
    {
        $this->attributes['username'] = Crypt::encryptString($value);
    }

    /**
     * Decrypt username when accessing.
     */
    public function getUsernameAttribute(?string $value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    /**
     * Encrypt password before saving.
     */
    public function setPasswordAttribute(string $value): void
    {
        $this->attributes['password'] = Crypt::encryptString($value);
    }

    /**
     * Decrypt password when accessing.
     */
    public function getPasswordAttribute(?string $value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    /**
     * Encrypt terminal_token before saving.
     */
    public function setTerminalTokenAttribute(string $value): void
    {
        $this->attributes['terminal_token'] = Crypt::encryptString($value);
    }

    /**
     * Decrypt terminal_token when accessing.
     */
    public function getTerminalTokenAttribute(?string $value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    /**
     * Get the currently active EMP account.
     */
    public static function getActive(): ?self
    {
        return static::where('is_active', true)->first();
    }

    /**
     * Set this account as the active one (deactivates others).
     */
    public function setAsActive(): bool
    {
        static::query()->update(['is_active' => false]);

        return $this->update(['is_active' => true]);
    }

    /**
     * Scope: only active accounts.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: ordered by sort_order.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    /**
     * Billing attempts processed through this account.
     */
    public function billingAttempts(): HasMany
    {
        return $this->hasMany(BillingAttempt::class);
    }

    /**
     * Get all transaction descriptors associated with this EMP account.
     */
    public function transactionDescriptors(): HasMany
    {
        return $this->hasMany(TransactionDescriptor::class);
    }
}
