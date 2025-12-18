<?php

/**
 * VOP verification service with caching and VopLog integration.
 */

namespace App\Services;

use App\Models\Debtor;
use App\Models\VopLog;
use Illuminate\Support\Facades\Log;

class VopVerificationService
{
    public function __construct(
        private IbanBavService $bavService,
        private IbanValidator $ibanValidator
    ) {}

    /**
     * Verify single debtor. Returns existing VopLog if cached.
     */
    public function verify(Debtor $debtor, bool $forceRefresh = false): ?VopLog
    {
        if (!$this->canVerify($debtor)) {
            return null;
        }

        $ibanHash = $debtor->iban_hash ?? $this->ibanValidator->hash($debtor->iban);

        // Check cache (existing VopLog)
        if (!$forceRefresh) {
            $existing = $this->findExistingVopLog($ibanHash);
            if ($existing) {
                Log::debug('VOP cache hit', ['debtor_id' => $debtor->id, 'iban_hash' => substr($ibanHash, 0, 8)]);
                return $this->linkVopLogToDebtor($existing, $debtor);
            }
        }

        // Call BAV API
        $name = trim(($debtor->first_name ?? '') . ' ' . ($debtor->last_name ?? ''));
        $result = $this->bavService->verify($debtor->iban, $name);

        if (!$result['success']) {
            Log::warning('VOP verification failed', [
                'debtor_id' => $debtor->id,
                'error' => $result['error'],
            ]);
            return null;
        }

        return $this->createVopLog($debtor, $result, $ibanHash);
    }

    /**
     * Check if debtor can be verified.
     */
    public function canVerify(Debtor $debtor): bool
    {
        if ($debtor->validation_status !== Debtor::VALIDATION_VALID) {
            return false;
        }

        if (empty($debtor->iban) || !$debtor->iban_valid) {
            return false;
        }

        $countryCode = substr($debtor->iban, 0, 2);
        if (!$this->bavService->isCountrySupported($countryCode)) {
            return false;
        }

        return true;
    }

    /**
     * Check if debtor already has VopLog.
     */
    public function hasVopLog(Debtor $debtor): bool
    {
        return VopLog::where('debtor_id', $debtor->id)->exists();
    }

    /**
     * Find existing VopLog by IBAN hash (cache lookup).
     */
    private function findExistingVopLog(string $ibanHash): ?VopLog
    {
        return VopLog::whereHas('debtor', function ($q) use ($ibanHash) {
            $q->where('iban_hash', $ibanHash);
        })->first();
    }

    /**
     * Link existing VopLog data to new debtor.
     */
    private function linkVopLogToDebtor(VopLog $existing, Debtor $debtor): VopLog
    {
        // Create new VopLog for this debtor with same data
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
     * Create new VopLog from BAV result.
     */
    private function createVopLog(Debtor $debtor, array $result, string $ibanHash): VopLog
    {
        $countryCode = substr($debtor->iban, 0, 2);

        $vopLog = VopLog::create([
            'debtor_id' => $debtor->id,
            'upload_id' => $debtor->upload_id,
            'iban_masked' => $this->ibanValidator->mask($debtor->iban),
            'iban_valid' => $result['valid'],
            'bank_identified' => !empty($result['bic']),
            'bank_name' => null, // BAV doesn't return bank_name
            'bic' => $result['bic'],
            'country' => $countryCode,
            'vop_score' => $result['vop_score'],
            'result' => $result['vop_result'],
            'meta' => [
                'name_match' => $result['name_match'],
                'iban_hash' => $ibanHash,
            ],
        ]);

        Log::info('VOP verification completed', [
            'debtor_id' => $debtor->id,
            'vop_score' => $result['vop_score'],
            'result' => $result['vop_result'],
        ]);

        return $vopLog;
    }

    /**
     * Get verification stats for upload.
     */
    public function getUploadStats(int $uploadId): array
    {
        $total = Debtor::where('upload_id', $uploadId)
            ->where('validation_status', Debtor::VALIDATION_VALID)
            ->count();

        $verified = VopLog::where('upload_id', $uploadId)->count();

        $byResult = VopLog::where('upload_id', $uploadId)
            ->selectRaw('result, COUNT(*) as count')
            ->groupBy('result')
            ->pluck('count', 'result')
            ->toArray();

        return [
            'total_eligible' => $total,
            'verified' => $verified,
            'pending' => $total - $verified,
            'by_result' => $byResult,
        ];
    }
}
