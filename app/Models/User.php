<?php

/**
 * User model for authentication and API tokens.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens;

    protected $fillable = [
        'name',
        'email',
        'password',
        'two_factor_enabled',
        'two_factor_backup_codes',
        'two_factor_setup_required',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_backup_codes', // Security: Never expose backup codes in API responses
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_enabled' => 'boolean',
            'two_factor_setup_required' => 'boolean',
            'two_factor_backup_codes' => 'array', // Cast JSON column to array automatically
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the OTP codes for the user.
     */
    public function otpCodes(): HasMany
    {
        return $this->hasMany(OtpCode::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Check if the user has enabled 2FA.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_enabled;
    }

    /**
     * Check if the user needs to complete the initial 2FA setup.
     */
    public function needsTwoFactorSetup(): bool
    {
        return $this->two_factor_enabled && $this->two_factor_setup_required;
    }

    /**
     * Mark the 2FA setup as complete.
     */
    public function completeTwoFactorSetup(): void
    {
        $this->update([
            'two_factor_setup_required' => false,
        ]);
    }
}
