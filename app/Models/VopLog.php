<?php

/**
 * VopLog model for storing VOP (Verify Ownership Process) verification results.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class VopLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'debtor_id',
        'upload_id',
        'iban_masked',
        'iban_valid',
        'bank_identified',
        'bank_name',
        'bic',
        'country',
        'vop_score',
        'result',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'iban_valid' => 'boolean',
        'bank_identified' => 'boolean',
        'vop_score' => 'integer',
    ];

    // Result constants
    public const RESULT_PENDING = 'pending';
    public const RESULT_VERIFIED = 'verified';
    public const RESULT_LIKELY_VERIFIED = 'likely_verified';
    public const RESULT_INCONCLUSIVE = 'inconclusive';
    public const RESULT_MISMATCH = 'mismatch';
    public const RESULT_REJECTED = 'rejected';

    /**
     * @return BelongsTo<Debtor, VopLog>
     */
    public function debtor(): BelongsTo
    {
        return $this->belongsTo(Debtor::class);
    }

    /**
     * @return BelongsTo<Upload, VopLog>
     */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class);
    }

    /**
     * Get bank reference by BIC.
     *
     * @return HasOne<BankReference>
     */
    public function bankReference(): HasOne
    {
        return $this->hasOne(BankReference::class, 'bic', 'bic');
    }

    public function isVerified(): bool
    {
        return $this->result === self::RESULT_VERIFIED;
    }

    public function isLikelyVerified(): bool
    {
        return $this->result === self::RESULT_LIKELY_VERIFIED;
    }

    public function isPositive(): bool
    {
        return in_array($this->result, [
            self::RESULT_VERIFIED,
            self::RESULT_LIKELY_VERIFIED,
        ]);
    }

    public function isNegative(): bool
    {
        return in_array($this->result, [
            self::RESULT_MISMATCH,
            self::RESULT_REJECTED,
        ]);
    }

    public function getScoreLabelAttribute(): string
    {
        return match (true) {
            $this->vop_score >= 80 => 'high',
            $this->vop_score >= 50 => 'medium',
            default => 'low',
        };
    }
}
