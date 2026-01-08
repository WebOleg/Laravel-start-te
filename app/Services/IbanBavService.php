<?php
/**
 * IBAN.com BAV (Bank Account Verification) API service.
 * Uses BAV v3 endpoint for name matching verification.
 */
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IbanBavService
{
    private const SUPPORTED_COUNTRIES = [
        'AT', 'BE', 'CY', 'DE', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR',
        'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PT', 'SI', 'SK',
    ];

    private const SCORE_MAP = [
        'yes' => 100,
        'partial' => 70,
        'unavailable' => 50,
        'no' => 20,
    ];

    private const RESULT_VERIFIED = 'verified';
    private const RESULT_LIKELY_VERIFIED = 'likely_verified';
    private const RESULT_INCONCLUSIVE = 'inconclusive';
    private const RESULT_MISMATCH = 'mismatch';
    private const RESULT_REJECTED = 'rejected';

    private string $apiKey;
    private string $apiUrl;
    private bool $mockMode;
    private IbanValidator $ibanValidator;

    public function __construct(IbanValidator $ibanValidator)
    {
        $this->apiKey = config('services.iban.api_key', '');
        $this->apiUrl = config('services.iban.bav_api_url', 'https://api.iban.com/clients/api/verify/v3/');
        $this->mockMode = config('services.iban.mock', true);
        $this->ibanValidator = $ibanValidator;
    }

    /**
     * @return array{success: bool, valid: bool, name_match: string, bic: ?string, vop_score: int, vop_result: string, error: ?string}
     */
    public function verify(string $iban, string $name): array
    {
        $normalizedIban = $this->ibanValidator->normalize($iban);
        $countryCode = substr($normalizedIban, 0, 2);

        if (!$this->isCountrySupported($countryCode)) {
            return $this->buildResult(
                success: false,
                error: "Country {$countryCode} is not supported by BAV API"
            );
        }

        if ($this->mockMode) {
            return $this->getMockResponse($normalizedIban, $name);
        }

        return $this->callApi($normalizedIban, $name);
    }

    public function isCountrySupported(string $countryCode): bool
    {
        return in_array(strtoupper($countryCode), self::SUPPORTED_COUNTRIES, true);
    }

    public function getSupportedCountries(): array
    {
        return self::SUPPORTED_COUNTRIES;
    }

    private function callApi(string $iban, string $name): array
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(60)->post($this->apiUrl, [
                'IBAN' => $iban,
                'name' => $name,
            ]);

            $data = $response->json();

            Log::info('BAV API response', [
                'iban_masked' => $this->ibanValidator->mask($iban),
                'status' => $response->status(),
                'success' => $data['query']['success'] ?? false,
                'name_match' => $data['result']['name_match'] ?? null,
            ]);

            if (!empty($data['error'])) {
                return $this->buildResult(success: false, error: $data['error']);
            }

            if (!($data['query']['success'] ?? false)) {
                return $this->buildResult(success: false, error: 'BAV verification failed');
            }

            return $this->buildResult(
                success: true,
                valid: $data['result']['valid'] ?? false,
                nameMatch: $data['result']['name_match'] ?? 'unavailable',
                bic: $data['result']['bic'] ?? null
            );

        } catch (\Exception $e) {
            Log::error('BAV API error', [
                'iban_masked' => $this->ibanValidator->mask($iban),
                'error' => $e->getMessage(),
            ]);

            return $this->buildResult(success: false, error: $e->getMessage());
        }
    }

    private function getMockResponse(string $iban, string $name): array
    {
        $lastDigit = (int) substr($iban, -1);

        return match (true) {
            $lastDigit === 0 => $this->buildResult(success: true, valid: true, nameMatch: 'yes', bic: 'DEUTDEFF'),
            $lastDigit === 1 => $this->buildResult(success: true, valid: true, nameMatch: 'partial', bic: 'COBADEFF'),
            $lastDigit === 2 => $this->buildResult(success: true, valid: true, nameMatch: 'no', bic: 'BNPAFRPP'),
            $lastDigit === 9 => $this->buildResult(success: true, valid: false, nameMatch: 'unavailable', bic: null),
            default => $this->buildResult(success: true, valid: true, nameMatch: 'yes', bic: 'ABNANL2A'),
        };
    }

    private function calculateVopScore(string $nameMatch, bool $valid): int
    {
        if (!$valid) {
            return 0;
        }

        return self::SCORE_MAP[$nameMatch] ?? 50;
    }

    private function determineVopResult(int $score): string
    {
        return match (true) {
            $score >= 90 => self::RESULT_VERIFIED,
            $score >= 70 => self::RESULT_LIKELY_VERIFIED,
            $score >= 50 => self::RESULT_INCONCLUSIVE,
            $score >= 20 => self::RESULT_MISMATCH,
            default => self::RESULT_REJECTED,
        };
    }

    private function buildResult(
        bool $success,
        bool $valid = false,
        string $nameMatch = 'unavailable',
        ?string $bic = null,
        ?string $error = null
    ): array {
        $vopScore = $this->calculateVopScore($nameMatch, $valid);

        return [
            'success' => $success,
            'valid' => $valid,
            'name_match' => $nameMatch,
            'bic' => $bic,
            'vop_score' => $vopScore,
            'vop_result' => $this->determineVopResult($vopScore),
            'error' => $error,
        ];
    }
}
