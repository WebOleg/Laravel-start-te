<?php

/**
 * Blacklisted IBANs that should be rejected during upload.
 * Can also store name, email and BIC for extended matching.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Blacklist extends Model
{
    use HasFactory;

    protected $fillable = [
        'iban',
        'iban_hash',
        'first_name',
        'last_name',
        'email',
        'bic',
        'reason',
        'source',
        'added_by',
    ];

    /**
     * User who added this entry.
     */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    /**
     * Check if matches by IBAN hash.
     */
    public static function findByIbanHash(string $ibanHash): ?self
    {
        return self::where('iban_hash', $ibanHash)->first();
    }

    /**
     * Check if matches by name (case-insensitive).
     */
    public static function findByName(string $firstName, string $lastName): ?self
    {
        return self::whereRaw('LOWER(first_name) = ?', [strtolower($firstName)])
            ->whereRaw('LOWER(last_name) = ?', [strtolower($lastName)])
            ->first();
    }

    /**
     * Check if matches by email (case-insensitive).
     */
    public static function findByEmail(string $email): ?self
    {
        return self::whereRaw('LOWER(email) = ?', [strtolower($email)])->first();
    }

    /**
     * Check if matches by BIC (case-insensitive).
     */
    public static function findByBic(string $bic): ?self
    {
        return self::whereRaw('LOWER(bic) = ?', [strtolower($bic)])->first();
    }

    /**
     * Get full name.
     */
    public function getFullNameAttribute(): ?string
    {
        if (!$this->first_name && !$this->last_name) {
            return null;
        }
        return trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));
    }
}
