<?php

/**
 * VOP verification service orchestrating scoring and VopLog management.
 *
 * Uses IbanApiService (IBAN SUITE - unlimited) for bank identification.
 */

namespace App\Services;

use App\Models\Debtor;
use App\Models\DebtorProfile;
use App\Models\VopLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class VopVerificationService
{
    public function __construct(
        private VopScoringService $scoringService,
        private IbanValidator $ibanValidator
    ) {}

    /**
     * Verify single debtor. Returns existing VopLog if cached.
     *
     * @param Debtor $debtor
     * @param bool $forceRefresh
     * @return ?VopLog
     */
    public function verify(Debtor $debtor, bool $forceRefresh = false): ?VopLog
    {
        if (!$this->canVerify($debtor)) {
            return null;
        }

        $ibanHash = $debtor->iban_hash ?? $this->ibanValidator->hash($debtor->iban);

        // Check cache (existing VopLog for same IBAN)
        if (!$forceRefresh) {
            $existing = $this->findExistingVopLog($ibanHash);
            if ($existing) {
                Log::debug('VOP cache hit', [
                    'debtor_id' => $debtor->id,
                    'iban_hash' => substr($ibanHash, 0, 8),
                ]);
                return $this->linkVopLogToDebtor($existing, $debtor);
            }
        }

        // Use VopScoringService (IbanApiService - unlimited)
        return $this->scoringService->score($debtor, $forceRefresh);
    }

    /**
     * Check if debtor can be verified.
     *
     * @param Debtor $debtor
     * @return bool
     */
    public function canVerify(Debtor $debtor): bool
    {
        if ($debtor->validation_status !== Debtor::VALIDATION_VALID) {
            return false;
        }

        if (empty($debtor->iban) || !$debtor->iban_valid) {
            return false;
        }

        return true;
    }

    /**
     * Check if debtor already has VopLog.
     *
     * @param Debtor $debtor
     * @return bool
     */
    public function hasVopLog(Debtor $debtor): bool
    {
        return VopLog::where('debtor_id', $debtor->id)->exists();
    }

    /**
     * Find existing VopLog by IBAN hash (cache lookup).
     *
     * @param string $ibanHash
     * @return ?VopLog
     */
    private function findExistingVopLog(string $ibanHash): ?VopLog
    {
        return VopLog::whereHas('debtor', function ($q) use ($ibanHash) {
            $q->where('iban_hash', $ibanHash);
        })->first();
    }

    /**
     * Link existing VopLog data to new debtor.
     *
     * @param VopLog $existing
     * @param Debtor $debtor
     * @return VopLog
     */
    private function linkVopLogToDebtor(VopLog $existing, Debtor $debtor): VopLog
    {
        return VopLog::create([
            'debtor_id' => $debtor->id,
            'upload_id' => $debtor->upload_id,
            'iban_masked' => $this->ibanValidator->mask($debtor->iban),
            'iban_valid' => $existing->iban_valid,
            'bank_identified' => $existing->bank_identified,
            'bank_name' => $existing->bank_name,
            'bic' => $existing->bic,
            'country' => $existing->country,
            'vop_score' => $existing->vop_score,
            'result' => $existing->result,
            'meta' => array_merge($existing->meta ?? [], ['cached_from' => $existing->id]),
        ]);
    }

    /**
     * Get verification stats for upload.
     *
     * @param int $uploadId
     * @param string|null $debtorType
     * @return array
     */
    public function getUploadStats(int $uploadId, ?string $debtorType = null): array
    {
        $applyDebtorFilter = function ($query) use ($debtorType) {
            if (!$debtorType || $debtorType === 'all') {
                return;
            }

            if ($debtorType === DebtorProfile::MODEL_LEGACY) {
                // Legacy = No profile OR Profile is explicitly Legacy
                $query->where(function ($q) {
                    $q->whereDoesntHave('debtorProfile')
                        ->orWhereHas('debtorProfile', fn($sq) => $sq->where('billing_model', DebtorProfile::MODEL_LEGACY));
                });
            } else {
                // Specific models (Flywheel, Recovery)
                $query->whereHas('debtorProfile', fn($q) => $q->where('billing_model', $debtorType));
            }
        };

        $totalQuery = Debtor::where('upload_id', $uploadId)
                            ->where('validation_status', Debtor::VALIDATION_VALID);

        $applyDebtorFilter($totalQuery);

        $total = $totalQuery->count();

        $vopLogsQuery = VopLog::where('upload_id', $uploadId);

        if ($debtorType && $debtorType !== 'all') {
            $vopLogsQuery->whereHas('debtor', $applyDebtorFilter);
        }

        $verified = (clone $vopLogsQuery)->count();

        $byResult = (clone $vopLogsQuery)
            ->selectRaw('result, COUNT(*) as count')
            ->groupBy('result')
            ->pluck('count', 'result')
            ->toArray();

        $avgScore = (clone $vopLogsQuery)->avg('vop_score');

        return [
            'total_eligible' => $total,
            'verified' => $verified,
            'pending' => $total - $verified,
            'by_result' => $byResult,
            'avg_score' => round($avgScore ?? 0),
            'is_processing' => Cache::has("vop_verify_{$uploadId}"),
        ];
    }
}
