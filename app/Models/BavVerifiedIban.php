<?php

/**
 * Global BAV verification cache model.
 * Single source of truth for all BAV-verified IBANs across the system.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BavVerifiedIban extends Model
{
    public const SOURCE_UPLOAD_BAV = 'upload_bav';
    public const SOURCE_STANDALONE_BATCH = 'standalone_batch';
    public const SOURCE_ARTISAN = 'artisan';

    protected $fillable = [
        'iban_hash',
        'iban_masked',
        'full_name',
        'name_match',
        'bic',
        'bav_score',
        'bav_result',
        'source',
        'source_id',
        'verified_at',
    ];

    protected $casts = [
        'bav_score' => 'integer',
        'verified_at' => 'datetime',
    ];

    /**
     * Record a successful BAV verification.
     * Uses upsert to avoid duplicates — if IBAN already verified, updates with latest result.
     */
    public static function recordVerification(
        string $ibanHash,
      string $ibanMasked,
        string $fullName,
        string $nameMatch,
        ?string $bic,
        int $bavScore,
        string $bavResult,
        string $source,
        ?int $sourceId = null
    ): self {
        return self::updateOrCreate(
            ['iban_hash' => $ibanHash],
            [
                'iban_masked' => $ibanMasked,
                'full_name' => $fullName,
                'name_match' => $nameMatch,
                'bic' => $bic,
                'bav_score' => $bavScore,
                'bav_result' => $bavResult,
                'source' => $source,
                'source_id' => $sourceId,
                'verified_at' => now(),
            ]
        );
    }

    /**
     * Check which IBANs from a list are already verified.
     * Returns set of iban_hashes that exist in the table.
     *
     * @param array<string> $ibanHashes
     * @return array<string, self>
     */
    public static function findVerified(array $ibanHashes): array
    {
        if (empty($ibanHashes)) {
            return [];
        }

        return self::whereIn('iban_hash', $ibanHashes)
            ->get()
            ->keyBy('iban_hash')
            ->all();
    }

    /**
     * Check if a single IBAN is already verified.
     */
    public static function isVerified(string $ibanHash): bool
    {
        return self::where('iban_hash', $ibanHash)->exists();
    }
}
