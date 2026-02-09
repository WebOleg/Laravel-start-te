<?php
/**
 * Model for tracking BAV API credits balance.
 */
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class BavCredit extends Model
{
    protected $fillable = [
        'credits_total',
        'credits_used',
        'expires_at',
        'last_refill_at',
        'last_updated_by',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_refill_at' => 'datetime',
    ];

    /**
     * Get the singleton record.
     */
    public static function getInstance(): self
    {
        return self::firstOrCreate(['id' => 1], [
            'credits_total' => 2500,
            'credits_used' => 0,
        ]);
    }

    /**
     * Get remaining credits.
     */
    public function getRemaining(): int
    {
        return max(0, $this->credits_total - $this->credits_used);
    }

    /**
     * Check if enough credits available.
     */
    public function hasCredits(int $required = 1): bool
    {
        return $this->getRemaining() >= $required;
    }

    /**
     * Atomic increment of credits_used.
     * Returns true if successful, false if not enough credits.
     */
    public static function consume(int $amount = 1): bool
    {
        $affected = DB::table('bav_credits')
            ->where('id', 1)
            ->whereRaw('(credits_total - credits_used) >= ?', [$amount])
            ->increment('credits_used', $amount, ['updated_at' => now()]);

        return $affected > 0;
    }

    /**
     * Refill credits (e.g., after purchasing new package).
     */
    public function refill(int $total, ?string $updatedBy = null): void
    {
        $this->update([
            'credits_total' => $this->credits_total + $total,
            'last_refill_at' => now(),
            'last_updated_by' => $updatedBy,
        ]);
    }

    /**
     * Manual adjustment (e.g., sync with iban.com dashboard).
     */
    public function adjust(int $total, int $used, ?string $updatedBy = null): void
    {
        $this->update([
            'credits_total' => $total,
            'credits_used' => $used,
            'last_updated_by' => $updatedBy,
        ]);
    }

    /**
     * Check if credits are expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Get balance info array.
     */
    public function getBalanceInfo(): array
    {
        return [
            'credits_total' => $this->credits_total,
            'credits_used' => $this->credits_used,
            'credits_remaining' => $this->getRemaining(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'is_expired' => $this->isExpired(),
            'last_refill_at' => $this->last_refill_at?->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
