<?php

/**
 * Service for VOP (Verification of Payer) verification.
 * Handles IBAN verification with caching support.
 */

namespace App\Services;

use App\Models\Debtor;
use App\Models\Upload;
use App\Models\VopLog;
use Illuminate\Support\Facades\Log;

class VopVerificationService
{
    public function __construct(
        private VopScoringService $scoringService,
        private IbanValidator $ibanValidator
    ) {}

    public function verify(Debtor $debtor, bool $forceRefresh = false): ?VopLog
    {
        if (!$this->canVerify($debtor)) {
            return null;
        }

        $ibanHash = $debtor->iban_hash ?? $this->ibanValidator->hash($debtor->iban);

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

        return $this->scoringService->score($debtor, $forceRefresh);
    }

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

    public function hasVopLog(Debtor $debtor): bool
    {
        return VopLog::where('debtor_id', $debtor->id)->exists();
    }

    private function findExistingVopLog(string $ibanHash): ?VopLog
    {
        return VopLog::whereHas('debtor', function ($q) use ($ibanHash) {
            $q->where('iban_hash', $ibanHash);
        })->first();
    }

    private function linkVopLogToDebtor(VopLog $existing, Debtor $debtor): VopLog
    {
        $vopLog = VopLog::create([
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

        $debtor->markVopVerified();

        return $vopLog;
    }

    public function getUploadStats(int $uploadId): array
    {
        $upload = Upload::find($uploadId);

        $total = Debtor::where('upload_id', $uploadId)
            ->where('validation_status', Debtor::VALIDATION_VALID)
            ->count();

        $verified = VopLog::where('upload_id', $uploadId)->count();

        $byResult = VopLog::where('upload_id', $uploadId)
            ->selectRaw('result, COUNT(*) as count')
            ->groupBy('result')
            ->pluck('count', 'result')
            ->toArray();

        $avgScore = VopLog::where('upload_id', $uploadId)->avg('vop_score');

        return [
            'total_eligible' => $total,
            'verified' => $verified,
            'pending' => $total - $verified,
            'by_result' => $byResult,
            'avg_score' => round($avgScore ?? 0),
            'is_processing' => $upload?->isVopProcessing() ?? false,
            'vop_status' => $upload?->vop_status ?? 'idle',
            'vop_started_at' => $upload?->vop_started_at?->toIso8601String(),
            'vop_completed_at' => $upload?->vop_completed_at?->toIso8601String(),
        ];
    }
}
