<?php
/**
 * VOP Scoring Engine for calculating debtor verification scores.
 *
 * Calculates a 0-100 score based on IBAN validation, bank identification,
 * SEPA support, country verification, and BAV name matching.
 */
namespace App\Services;

use App\Models\Debtor;
use App\Models\VopLog;
use Illuminate\Support\Facades\Log;

class VopScoringService
{
    private const SCORE_IBAN_VALID = 20;
    private const SCORE_BANK_IDENTIFIED = 25;
    private const SCORE_SEPA_SDD = 25;
    private const SCORE_COUNTRY_SUPPORTED = 15;
    private const SCORE_NAME_MATCH = 15;

    private const SEPA_COUNTRIES = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
        'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
        'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'GB', 'IS', 'LI',
        'NO', 'CH', 'MC', 'SM', 'VA',
    ];

    private IbanValidator $ibanValidator;
    private IbanApiService $ibanApiService;
    private IbanBavService $ibanBavService;

    public function __construct(
        IbanValidator $ibanValidator,
        IbanApiService $ibanApiService,
        IbanBavService $ibanBavService
    ) {
        $this->ibanValidator = $ibanValidator;
        $this->ibanApiService = $ibanApiService;
        $this->ibanBavService = $ibanBavService;
    }

    /**
     * @param Debtor $debtor
     * @param bool $forceRefresh
     * @return VopLog
     */
    public function score(Debtor $debtor, bool $forceRefresh = false): VopLog
    {
        $iban = $debtor->iban;
        $score = 0;
        $meta = [];

        Log::channel('vop')->info('VopScoringService: Starting score calculation', [
            'debtor_id' => $debtor->id,
            'iban' => $this->ibanValidator->mask($iban),
            'bav_selected' => $debtor->bav_selected,
            'force_refresh' => $forceRefresh,
        ]);

        $ibanValid = $this->ibanValidator->isValid($iban);
        if ($ibanValid) {
            $score += self::SCORE_IBAN_VALID;
            $meta['iban_valid'] = true;
        } else {
            $meta['iban_valid'] = false;
        }

        $country = $this->ibanValidator->getCountryCode($iban);
        $countrySupported = in_array($country, self::SEPA_COUNTRIES);
        if ($countrySupported) {
            $score += self::SCORE_COUNTRY_SUPPORTED;
            $meta['country_supported'] = true;
        } else {
            $meta['country_supported'] = false;
        }

        $bankIdentified = false;
        $bankName = null;
        $bic = null;
        $sepaSdd = false;

        if ($ibanValid) {
            $apiResult = $this->ibanApiService->verify($iban, $forceRefresh);

            if ($apiResult['success']) {
                $bankData = $apiResult['bank_data'] ?? [];
                $sepaData = $apiResult['sepa_data'] ?? [];

                $bankName = $bankData['bank'] ?? null;
                $bic = $bankData['bic'] ?? null;
                $bankIdentified = !empty($bankName);

                if ($bankIdentified) {
                    $score += self::SCORE_BANK_IDENTIFIED;
                    $meta['bank_identified'] = true;
                }

                $sepaSdd = strtoupper($sepaData['SDD'] ?? '') === 'YES';
                if ($sepaSdd) {
                    $score += self::SCORE_SEPA_SDD;
                    $meta['sepa_sdd'] = true;
                } else {
                    $meta['sepa_sdd'] = false;
                }

                $meta['api_source'] = $apiResult['source'] ?? 'unknown';
                $meta['api_cached'] = $apiResult['cached'] ?? false;
            } else {
                $meta['api_error'] = $apiResult['error'] ?? 'Unknown error';
            }
        }

        $nameMatch = null;
        $nameMatchScore = null;
        $bavVerified = false;

        // Only perform BAV if VOP base score is successful (>= 60 points without BAV)
        // This means IBAN + Bank + SEPA + Country must be verified first
        $vopBaseScore = $score; // Current score before BAV
        $bavEligible = $vopBaseScore >= 60; // At least LIKELY_VERIFIED level

        if ($debtor->bav_selected && $ibanValid && $bavEligible && $this->ibanBavService->isCountrySupported($country)) {
            $bavResult = $this->verifyBav($debtor, $iban);
            $bavVerified = true;
            $nameMatch = $bavResult['name_match'];
            $nameMatchScore = $bavResult['name_match_score'];

            if ($bavResult['success'] && in_array($nameMatch, [VopLog::NAME_MATCH_YES, VopLog::NAME_MATCH_PARTIAL])) {
                $namePoints = $this->calculateNameMatchPoints($nameMatch, $nameMatchScore);
                $score += $namePoints;
                $meta['name_match_points'] = $namePoints;
            }

            $meta['bav_result'] = $bavResult;
        } elseif ($debtor->bav_selected && !$bavEligible) {
            Log::channel('bav')->info('BAV skipped: VOP base score too low', [
                'debtor_id' => $debtor->id,
                'vop_base_score' => $vopBaseScore,
                'required' => 60,
            ]);
        }

        $result = $this->calculateResult($score);

        Log::channel('vop')->info('VopScoringService: Score calculation complete', [
            'debtor_id' => $debtor->id,
            'iban' => $this->ibanValidator->mask($iban),
            'final_score' => $score,
            'result' => $result,
            'bank' => $bankName,
            'bic' => $bic,
            'sepa_sdd' => $sepaSdd,
            'bav_verified' => $bavVerified,
            'name_match' => $nameMatch,
            'score_breakdown' => [
                'iban_valid' => $ibanValid ? self::SCORE_IBAN_VALID : 0,
                'bank_identified' => $bankIdentified ? self::SCORE_BANK_IDENTIFIED : 0,
                'sepa_sdd' => $sepaSdd ? self::SCORE_SEPA_SDD : 0,
                'country_supported' => $countrySupported ? self::SCORE_COUNTRY_SUPPORTED : 0,
                'name_match' => $meta['name_match_points'] ?? 0,
            ],
        ]);

        $vopLog = VopLog::create([
            'debtor_id' => $debtor->id,
            'upload_id' => $debtor->upload_id,
            'iban_masked' => $this->ibanValidator->mask($iban),
            'iban_valid' => $ibanValid,
            'bank_identified' => $bankIdentified,
            'bank_name' => $bankName,
            'bic' => $bic,
            'country' => $country,
            'vop_score' => $score,
            'result' => $result,
            'name_match' => $nameMatch,
            'name_match_score' => $nameMatchScore,
            'bav_verified' => $bavVerified,
            'meta' => $meta,
        ]);

        $this->updateDebtorStatus($debtor, $vopLog);

        Log::channel('vop')->info('VopScoringService: VopLog created', [
            'vop_log_id' => $vopLog->id,
            'debtor_id' => $debtor->id,
        ]);

        return $vopLog;
    }

    /**
     * @param Debtor $debtor
     * @param string $iban
     * @return array{success: bool, name_match: string, name_match_score: ?int, error: ?string}
     */
    private function verifyBav(Debtor $debtor, string $iban): array
    {
        $name = $debtor->getNameForBav();

        Log::channel('bav')->info('BAV verification started', [
            'debtor_id' => $debtor->id,
            'iban_masked' => $this->ibanValidator->mask($iban),
        ]);

        $result = $this->ibanBavService->verify($iban, $name);

        if (!$result['success']) {
            Log::channel('bav')->warning('BAV verification failed', [
                'debtor_id' => $debtor->id,
                'error' => $result['error'],
            ]);

            return [
                'success' => false,
                'name_match' => VopLog::NAME_MATCH_ERROR,
                'name_match_score' => null,
                'error' => $result['error'],
            ];
        }

        Log::channel('bav')->info('BAV verification completed', [
            'debtor_id' => $debtor->id,
            'name_match' => $result['name_match'],
            'vop_score' => $result['vop_score'],
        ]);

        return [
            'success' => true,
            'name_match' => $result['name_match'],
            'name_match_score' => $result['vop_score'],
            'error' => null,
        ];
    }

    /**
     * @param string $nameMatch
     * @param ?int $nameMatchScore
     * @return int
     */
    private function calculateNameMatchPoints(string $nameMatch, ?int $nameMatchScore): int
    {
        if ($nameMatch === VopLog::NAME_MATCH_YES) {
            return self::SCORE_NAME_MATCH;
        }

        if ($nameMatch === VopLog::NAME_MATCH_PARTIAL && $nameMatchScore !== null) {
            return (int) round(($nameMatchScore / 100) * self::SCORE_NAME_MATCH);
        }

        return 0;
    }

    /**
     * @param Debtor $debtor
     * @param VopLog $vopLog
     * @return void
     */
    private function updateDebtorStatus(Debtor $debtor, VopLog $vopLog): void
    {
        $nameMatch = null;

        if ($vopLog->bav_verified) {
            if (in_array($vopLog->name_match, [VopLog::NAME_MATCH_YES, VopLog::NAME_MATCH_PARTIAL])) {
                $nameMatch = true;
            } elseif ($vopLog->name_match === VopLog::NAME_MATCH_NO) {
                $nameMatch = false;
            }
        }

        if ($vopLog->name_match === VopLog::NAME_MATCH_ERROR) {
            $debtor->markVopError();
        } else {
            $debtor->markVopVerified($nameMatch);
        }
    }

    /**
     * @param Debtor $debtor
     * @return array{score: int, max_score: int, result: string, breakdown: array}
     */
    public function calculate(Debtor $debtor): array
    {
        $iban = $debtor->iban;
        $breakdown = [];
        $score = 0;

        $ibanValid = $this->ibanValidator->isValid($iban);
        $breakdown['iban_valid'] = [
            'passed' => $ibanValid,
            'points' => $ibanValid ? self::SCORE_IBAN_VALID : 0,
            'max' => self::SCORE_IBAN_VALID,
        ];
        if ($ibanValid) {
            $score += self::SCORE_IBAN_VALID;
        }

        $country = $this->ibanValidator->getCountryCode($iban);
        $countrySupported = in_array($country, self::SEPA_COUNTRIES);
        $breakdown['country_supported'] = [
            'passed' => $countrySupported,
            'points' => $countrySupported ? self::SCORE_COUNTRY_SUPPORTED : 0,
            'max' => self::SCORE_COUNTRY_SUPPORTED,
            'country' => $country,
        ];
        if ($countrySupported) {
            $score += self::SCORE_COUNTRY_SUPPORTED;
        }

        $bankIdentified = false;
        $sepaSdd = false;
        if ($ibanValid) {
            $apiResult = $this->ibanApiService->verify($iban);
            if ($apiResult['success']) {
                $bankIdentified = !empty($apiResult['bank_data']['bank'] ?? null);
                $sepaSdd = strtoupper($apiResult['sepa_data']['SDD'] ?? '') === 'YES';
            }
        }

        $breakdown['bank_identified'] = [
            'passed' => $bankIdentified,
            'points' => $bankIdentified ? self::SCORE_BANK_IDENTIFIED : 0,
            'max' => self::SCORE_BANK_IDENTIFIED,
        ];
        if ($bankIdentified) {
            $score += self::SCORE_BANK_IDENTIFIED;
        }

        $breakdown['sepa_sdd'] = [
            'passed' => $sepaSdd,
            'points' => $sepaSdd ? self::SCORE_SEPA_SDD : 0,
            'max' => self::SCORE_SEPA_SDD,
        ];
        if ($sepaSdd) {
            $score += self::SCORE_SEPA_SDD;
        }

        $bavSupported = $this->ibanBavService->isCountrySupported($country);
        $breakdown['name_match'] = [
            'passed' => null,
            'points' => 0,
            'max' => self::SCORE_NAME_MATCH,
            'bav_supported' => $bavSupported,
            'note' => $debtor->bav_selected ? 'Will be verified' : 'Not selected for BAV',
        ];

        return [
            'score' => $score,
            'max_score' => 100,
            'result' => $this->calculateResult($score),
            'breakdown' => $breakdown,
        ];
    }

    /**
     * @param int $score
     * @return string
     */
    private function calculateResult(int $score): string
    {
        return match (true) {
            $score >= 80 => VopLog::RESULT_VERIFIED,
            $score >= 60 => VopLog::RESULT_LIKELY_VERIFIED,
            $score >= 40 => VopLog::RESULT_INCONCLUSIVE,
            $score >= 20 => VopLog::RESULT_MISMATCH,
            default => VopLog::RESULT_REJECTED,
        };
    }

    /**
     * @return array<string, int>
     */
    public static function getScoreBreakdown(): array
    {
        return [
            'iban_valid' => self::SCORE_IBAN_VALID,
            'bank_identified' => self::SCORE_BANK_IDENTIFIED,
            'sepa_sdd' => self::SCORE_SEPA_SDD,
            'country_supported' => self::SCORE_COUNTRY_SUPPORTED,
            'name_match' => self::SCORE_NAME_MATCH,
            'total' => 100,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getResultThresholds(): array
    {
        return [
            VopLog::RESULT_VERIFIED => '80-100',
            VopLog::RESULT_LIKELY_VERIFIED => '60-79',
            VopLog::RESULT_INCONCLUSIVE => '40-59',
            VopLog::RESULT_MISMATCH => '20-39',
            VopLog::RESULT_REJECTED => '0-19',
        ];
    }
}
