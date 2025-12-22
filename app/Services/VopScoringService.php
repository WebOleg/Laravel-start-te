<?php

/**
 * VOP Scoring Engine for calculating debtor verification scores.
 * 
 * Calculates a 0-100 score based on IBAN validation, bank identification,
 * SEPA support, and country verification.
 */

namespace App\Services;

use App\Models\Debtor;
use App\Models\VopLog;

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

    public function __construct(IbanValidator $ibanValidator, IbanApiService $ibanApiService)
    {
        $this->ibanValidator = $ibanValidator;
        $this->ibanApiService = $ibanApiService;
    }

    /**
     * Calculate VOP score and create VopLog for debtor.
     *
     * @param Debtor $debtor
     * @param bool $forceRefresh Skip cache and force API call
     * @return VopLog
     */
    public function score(Debtor $debtor, bool $forceRefresh = false): VopLog
    {
        $iban = $debtor->iban;
        $score = 0;
        $meta = [];

        // 1. IBAN checksum validation (local)
        $ibanValid = $this->ibanValidator->isValid($iban);
        if ($ibanValid) {
            $score += self::SCORE_IBAN_VALID;
            $meta['iban_valid'] = true;
        } else {
            $meta['iban_valid'] = false;
        }

        // 2. Country in SEPA zone
        $country = $this->ibanValidator->getCountryCode($iban);
        $countrySupported = in_array($country, self::SEPA_COUNTRIES);
        if ($countrySupported) {
            $score += self::SCORE_COUNTRY_SUPPORTED;
            $meta['country_supported'] = true;
        } else {
            $meta['country_supported'] = false;
        }

        // 3. Bank identification via API
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

                // 4. SEPA SDD support
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

        // 5. Name match (placeholder for future BAV integration)
        // $score += self::SCORE_NAME_MATCH;
        $meta['name_match'] = null; // Not implemented yet

        // Calculate result based on score
        $result = $this->calculateResult($score);

        // Create VopLog
        return VopLog::create([
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
            'meta' => $meta,
        ]);
    }

    /**
     * Calculate score without creating VopLog (dry run).
     *
     * @param Debtor $debtor
     * @return array{score: int, result: string, breakdown: array}
     */
    public function calculate(Debtor $debtor): array
    {
        $iban = $debtor->iban;
        $breakdown = [];
        $score = 0;

        // IBAN valid
        $ibanValid = $this->ibanValidator->isValid($iban);
        $breakdown['iban_valid'] = [
            'passed' => $ibanValid,
            'points' => $ibanValid ? self::SCORE_IBAN_VALID : 0,
            'max' => self::SCORE_IBAN_VALID,
        ];
        if ($ibanValid) $score += self::SCORE_IBAN_VALID;

        // Country supported
        $country = $this->ibanValidator->getCountryCode($iban);
        $countrySupported = in_array($country, self::SEPA_COUNTRIES);
        $breakdown['country_supported'] = [
            'passed' => $countrySupported,
            'points' => $countrySupported ? self::SCORE_COUNTRY_SUPPORTED : 0,
            'max' => self::SCORE_COUNTRY_SUPPORTED,
            'country' => $country,
        ];
        if ($countrySupported) $score += self::SCORE_COUNTRY_SUPPORTED;

        // Bank identified
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
        if ($bankIdentified) $score += self::SCORE_BANK_IDENTIFIED;

        // SEPA SDD
        $breakdown['sepa_sdd'] = [
            'passed' => $sepaSdd,
            'points' => $sepaSdd ? self::SCORE_SEPA_SDD : 0,
            'max' => self::SCORE_SEPA_SDD,
        ];
        if ($sepaSdd) $score += self::SCORE_SEPA_SDD;

        // Name match (future)
        $breakdown['name_match'] = [
            'passed' => null,
            'points' => 0,
            'max' => self::SCORE_NAME_MATCH,
            'note' => 'Not implemented',
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
     * @return array
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
     * @return array
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
