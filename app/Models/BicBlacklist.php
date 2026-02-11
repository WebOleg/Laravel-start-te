<?php

/**
 * Model for BIC blacklist entries.
 * Supports exact BIC match and prefix-based matching.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BicBlacklist extends Model
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_IMPORT = 'import';
    public const SOURCE_AUTO = 'auto';

    protected $fillable = [
        'bic',
        'is_prefix',
        'reason',
        'source',
        'auto_criteria',
        'stats_snapshot',
        'blacklisted_by',
    ];

    protected $casts = [
        'is_prefix' => 'boolean',
        'stats_snapshot' => 'array',
    ];

    /**
     * Check if a given BIC is blacklisted (exact or prefix match).
     */
    public static function isBlacklisted(string $bic): bool
    {
        $bic = strtoupper(trim($bic));

        if (empty($bic)) {
            return false;
        }

        $exactMatch = static::where('bic', $bic)
            ->where('is_prefix', false)
            ->exists();

        if ($exactMatch) {
            return true;
        }

        $prefixes = static::where('is_prefix', true)->pluck('bic');

        foreach ($prefixes as $prefix) {
            if (str_starts_with($bic, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the blacklist entry that matches the given BIC.
     */
    public static function findMatch(string $bic): ?self
    {
        $bic = strtoupper(trim($bic));

        if (empty($bic)) {
            return null;
        }

        $exact = static::where('bic', $bic)
            ->where('is_prefix', false)
            ->first();

        if ($exact) {
            return $exact;
        }

        $prefixes = static::where('is_prefix', true)->get();

        foreach ($prefixes as $entry) {
            if (str_starts_with($bic, $entry->bic)) {
                return $entry;
            }
        }

        return null;
    }
}
