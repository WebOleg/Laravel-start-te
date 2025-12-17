<?php

/**
 * Service for IBAN deduplication during file upload.
 * 
 * Implements three-tier skip logic:
 * 1. Hard block forever: blacklisted, chargebacked
 * 2. Soft block forever: already recovered
 * 3. Soft block with 30-day cooldown: recently attempted
 */

namespace App\Services;

use App\Models\Blacklist;
use App\Models\BillingAttempt;
use App\Models\Debtor;
use Illuminate\Support\Facades\DB;

class DeduplicationService
{
    public const SKIP_BLACKLISTED = 'blacklisted';
    public const SKIP_CHARGEBACKED = 'chargebacked';
    public const SKIP_RECOVERED = 'already_recovered';
    public const SKIP_RECENTLY_ATTEMPTED = 'recently_attempted';

    public const COOLDOWN_DAYS = 30;

    public function __construct(
        private IbanValidator $ibanValidator
    ) {}

    /**
     * Check if IBAN should be skipped during upload.
     * 
     * @return array{reason: string, permanent: bool, days_ago?: int, last_status?: string}|null
     */
    public function checkIban(string $iban, ?int $excludeUploadId = null): ?array
    {
        if (empty($iban)) {
            return null;
        }

        $hash = $this->ibanValidator->hash($iban);

        if ($this->isBlacklisted($hash)) {
            return [
                'reason' => self::SKIP_BLACKLISTED,
                'permanent' => true,
            ];
        }

        if ($this->isChargebacked($hash)) {
            return [
                'reason' => self::SKIP_CHARGEBACKED,
                'permanent' => true,
            ];
        }

        if ($this->isRecovered($hash, $excludeUploadId)) {
            return [
                'reason' => self::SKIP_RECOVERED,
                'permanent' => true,
            ];
        }

        $recentAttempt = $this->getRecentAttempt($hash, $excludeUploadId);
        if ($recentAttempt) {
            return [
                'reason' => self::SKIP_RECENTLY_ATTEMPTED,
                'permanent' => false,
                'days_ago' => $recentAttempt['days_ago'],
                'last_status' => $recentAttempt['status'],
            ];
        }

        return null;
    }

    public function isBlacklisted(string $ibanHash): bool
    {
        return Blacklist::where('iban_hash', $ibanHash)->exists();
    }

    public function isChargebacked(string $ibanHash): bool
    {
        return BillingAttempt::whereHas('debtor', function ($q) use ($ibanHash) {
            $q->where('iban_hash', $ibanHash);
        })
            ->where('status', BillingAttempt::STATUS_CHARGEBACKED)
            ->exists();
    }

    public function isRecovered(string $ibanHash, ?int $excludeUploadId = null): bool
    {
        return Debtor::where('iban_hash', $ibanHash)
            ->where('status', Debtor::STATUS_RECOVERED)
            ->when($excludeUploadId, fn($q) => $q->where('upload_id', '!=', $excludeUploadId))
            ->exists();
    }

    /**
     * @return array{days_ago: int, status: string}|null
     */
    public function getRecentAttempt(string $ibanHash, ?int $excludeUploadId = null): ?array
    {
        $cutoffDate = now()->subDays(self::COOLDOWN_DAYS);

        $attempt = BillingAttempt::whereHas('debtor', function ($q) use ($ibanHash, $excludeUploadId) {
            $q->where('iban_hash', $ibanHash)
                ->when($excludeUploadId, fn($q) => $q->where('upload_id', '!=', $excludeUploadId));
        })
            ->whereIn('status', BillingAttempt::IN_FLIGHT_STATUSES)
            ->where('created_at', '>=', $cutoffDate)
            ->orderByDesc('created_at')
            ->first();

        if (!$attempt) {
            return null;
        }

        return [
            'days_ago' => (int) $attempt->created_at->diffInDays(now()),
            'status' => $attempt->status,
        ];
    }

    /**
     * @param array<string> $ibanHashes
     * @return array<string, array{reason: string, permanent: bool, days_ago?: int, last_status?: string}>
     */
    public function checkBatch(array $ibanHashes, ?int $excludeUploadId = null): array
    {
        if (empty($ibanHashes)) {
            return [];
        }

        $results = [];

        $blacklisted = Blacklist::whereIn('iban_hash', $ibanHashes)
            ->pluck('iban_hash')
            ->flip()
            ->all();

        $recoveredQuery = Debtor::whereIn('iban_hash', $ibanHashes)
            ->where('status', Debtor::STATUS_RECOVERED);
        
        if ($excludeUploadId) {
            $recoveredQuery->where('upload_id', '!=', $excludeUploadId);
        }
        
        $recovered = $recoveredQuery
            ->pluck('iban_hash')
            ->flip()
            ->all();

        $chargebacked = DB::table('billing_attempts')
            ->join('debtors', 'billing_attempts.debtor_id', '=', 'debtors.id')
            ->whereIn('debtors.iban_hash', $ibanHashes)
            ->where('billing_attempts.status', BillingAttempt::STATUS_CHARGEBACKED)
            ->distinct()
            ->pluck('debtors.iban_hash')
            ->flip()
            ->all();

        $cutoffDate = now()->subDays(self::COOLDOWN_DAYS);
        
        $recentAttemptsQuery = DB::table('billing_attempts')
            ->join('debtors', 'billing_attempts.debtor_id', '=', 'debtors.id')
            ->whereIn('debtors.iban_hash', $ibanHashes)
            ->whereIn('billing_attempts.status', BillingAttempt::IN_FLIGHT_STATUSES)
            ->where('billing_attempts.created_at', '>=', $cutoffDate);
        
        if ($excludeUploadId) {
            $recentAttemptsQuery->where('debtors.upload_id', '!=', $excludeUploadId);
        }
        
        $recentAttempts = $recentAttemptsQuery
            ->select('debtors.iban_hash', 'billing_attempts.status', 'billing_attempts.created_at')
            ->orderByDesc('billing_attempts.created_at')
            ->get()
            ->groupBy('iban_hash')
            ->map(fn($group) => $group->first())
            ->all();

        foreach ($ibanHashes as $hash) {
            if (isset($blacklisted[$hash])) {
                $results[$hash] = ['reason' => self::SKIP_BLACKLISTED, 'permanent' => true];
            } elseif (isset($chargebacked[$hash])) {
                $results[$hash] = ['reason' => self::SKIP_CHARGEBACKED, 'permanent' => true];
            } elseif (isset($recovered[$hash])) {
                $results[$hash] = ['reason' => self::SKIP_RECOVERED, 'permanent' => true];
            } elseif (isset($recentAttempts[$hash])) {
                $attempt = $recentAttempts[$hash];
                $createdAt = \Carbon\Carbon::parse($attempt->created_at);
                $results[$hash] = [
                    'reason' => self::SKIP_RECENTLY_ATTEMPTED,
                    'permanent' => false,
                    'days_ago' => (int) $createdAt->diffInDays(now()),
                    'last_status' => $attempt->status,
                ];
            }
        }

        return $results;
    }
}
