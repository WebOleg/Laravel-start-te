<?php

/**
 * IBAN validation service wrapping jschaedl/iban-validation library.
 */

namespace App\Services;

use Iban\Validation\Iban;
use Iban\Validation\Validator;
use Iban\Validation\CountryInfo;

class IbanValidator
{
    // SEPA member countries (Single Euro Payments Area)
    private const SEPA_COUNTRIES = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
        'DE', 'GR', 'HU', 'IS', 'IE', 'IT', 'LV', 'LI', 'LT', 'LU',
        'MT', 'MC', 'NL', 'NO', 'PL', 'PT', 'RO', 'SM', 'SK', 'SI',
        'ES', 'SE', 'CH', 'GB', 'VA', 'AD', 'FO', 'GL', 'GI',
    ];

    private Validator $validator;

    public function __construct()
    {
        $this->validator = new Validator();
    }

    /**
     * Validate IBAN and return detailed result.
     *
     * @return array{valid: bool, country_code: ?string, country_name: ?string, is_sepa: bool, checksum: ?string, bban: ?string, bank_id: ?string, formatted: ?string, errors: array}
     */
    public function validate(string $iban): array
    {
        try {
            $ibanObj = new Iban($iban);
            $isValid = $this->validator->validate($ibanObj);

            if (!$isValid) {
                return $this->buildResult(
                    valid: false,
                    errors: $this->getViolationMessages()
                );
            }

            $countryCode = $ibanObj->countryCode();
            $countryInfo = new CountryInfo($countryCode);

            return $this->buildResult(
                valid: true,
                countryCode: $countryCode,
                countryName: $countryInfo->getCountryName(),
                isSepa: $this->isSepaCountry($countryCode),
                checksum: $ibanObj->checksum(),
                bban: $ibanObj->bban(),
                bankId: $ibanObj->bbanBankIdentifier(),
                formatted: $ibanObj->format(Iban::FORMAT_PRINT),
                errors: []
            );
        } catch (\Exception $e) {
            return $this->buildResult(
                valid: false,
                errors: [$e->getMessage()]
            );
        }
    }

    /**
     * Quick validation check.
     */
    public function isValid(string $iban): bool
    {
        try {
            $ibanObj = new Iban($iban);
            return $this->validator->validate($ibanObj);
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Check if IBAN country is in SEPA zone.
     */
    public function isSepa(string $iban): bool
    {
        $countryCode = $this->getCountryCode($iban);
        return $this->isSepaCountry($countryCode);
    }

    /**
     * Check if country code is in SEPA zone.
     */
    public function isSepaCountry(string $countryCode): bool
    {
        return in_array(strtoupper($countryCode), self::SEPA_COUNTRIES, true);
    }

    /**
     * Extract country code from IBAN.
     */
    public function getCountryCode(string $iban): string
    {
        try {
            $ibanObj = new Iban($iban);
            return $ibanObj->countryCode();
        } catch (\Exception) {
            return substr($this->normalize($iban), 0, 2);
        }
    }

    /**
     * Get country name from IBAN.
     */
    public function getCountryName(string $iban): ?string
    {
        try {
            $countryCode = $this->getCountryCode($iban);
            $countryInfo = new CountryInfo($countryCode);
            return $countryInfo->getCountryName();
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Extract bank identifier from IBAN.
     */
    public function getBankId(string $iban): ?string
    {
        try {
            $ibanObj = new Iban($iban);
            return $ibanObj->bbanBankIdentifier();
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Normalize IBAN: remove prefix, uppercase, remove spaces and special characters.
     */
    public function normalize(string $iban): string
    {
        $cleaned = strtoupper(trim($iban));
        
        // Remove common prefixes
        if (str_starts_with($cleaned, 'IBAN ')) {
            $cleaned = substr($cleaned, 5);
        } elseif (str_starts_with($cleaned, 'IBAN')) {
            $cleaned = substr($cleaned, 4);
        }
        
        return preg_replace('/[^A-Z0-9]/', '', $cleaned);
    }

    /**
     * Format IBAN for display (groups of 4).
     */
    public function format(string $iban): string
    {
        try {
            $ibanObj = new Iban($iban);
            return $ibanObj->format(Iban::FORMAT_PRINT);
        } catch (\Exception) {
            return trim(chunk_split($this->normalize($iban), 4, ' '));
        }
    }

    /**
     * Mask IBAN for secure display (show first 4 and last 4 only).
     */
    public function mask(string $iban): string
    {
        $normalized = $this->normalize($iban);
        $length = strlen($normalized);
        
        if ($length < 8) {
            return '****';
        }
        
        $middle = str_repeat('*', $length - 8);
        return substr($normalized, 0, 4) . $middle . substr($normalized, -4);
    }

    /**
     * Generate SHA-256 hash for deduplication.
     */
    public function hash(string $iban): string
    {
        return hash('sha256', $this->normalize($iban));
    }

    private function getViolationMessages(): array
    {
        $messages = [];
        foreach ($this->validator->getViolations() as $violation) {
            $messages[] = (string) $violation;
        }
        return $messages;
    }

    private function buildResult(
        bool $valid,
        ?string $countryCode = null,
        ?string $countryName = null,
        bool $isSepa = false,
        ?string $checksum = null,
        ?string $bban = null,
        ?string $bankId = null,
        ?string $formatted = null,
        array $errors = []
    ): array {
        return [
            'valid' => $valid,
            'country_code' => $countryCode,
            'country_name' => $countryName,
            'is_sepa' => $isSepa,
            'checksum' => $checksum,
            'bban' => $bban,
            'bank_id' => $bankId,
            'formatted' => $formatted,
            'errors' => $errors,
        ];
    }
}
